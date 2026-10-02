<?php

namespace SirZeruks\LibrenmsRouterosBgp;

use App\Models\BgpPeer;
use App\Models\Device;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LibreNMS\RRD\RrdDefinition;
use SirZeruks\LibrenmsRouterosBgp\Transport\TransportException;
use SirZeruks\LibrenmsRouterosBgp\Transport\TransportFactory;

/**
 * Reads BGP sessions from one RouterOS device and stores the prefix counts exactly where the
 * LibreNMS core BGP poller stores them for other vendors (bgpPeers_cbgp + cbgp-*.rrd), so the
 * native Routing > BGP prefix graphs and the list_cbgp API work unchanged.
 */
final class Poller
{
    public const SAFI = 'unicast';

    public function __construct(private readonly SettingsStore $settings)
    {
    }

    /**
     * Devices the plugin looks after: RouterOS devices LibreNMS already knows BGP peers for.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Device>
     */
    public static function candidateDevices()
    {
        return Device::where('os', 'routeros')
            ->where(fn ($q) => $q->where('bgpLocalAs', '>', 0)->orWhereIn('device_id', BgpPeer::query()->select('device_id')))
            ->orderBy('hostname')
            ->get();
    }

    /**
     * Does this RouterOS device run BGP, as far as LibreNMS knows? Either LibreNMS found BGP peers on it, or (for a
     * router with only IPv6 sessions, which SNMP cannot list) at least its BGP local AS.
     */
    public static function runsBgp(Device $device): bool
    {
        return (int) $device->bgpLocalAs > 0 || $device->bgppeers()->exists();
    }

    /**
     * @return array{ok: bool, message: string, sessions: array<int, array<string, mixed>>, matched: int, time: int, via: string}
     */
    public function poll(Device $device, bool $write = true): array
    {
        $config = $this->settings->connectionFor($device);
        $result = ['ok' => false, 'message' => '', 'sessions' => [], 'matched' => 0, 'time' => time(), 'via' => $config->describe()];

        if ($config->username === '') {
            $result['message'] = 'No username configured (set the defaults or a device override)';

            return $this->remember($device, $result, $write);
        }

        try {
            $sessions = TransportFactory::make($config)->fetchSessions();
        } catch (TransportException $e) {
            $result['message'] = $e->getMessage();

            return $this->remember($device, $result, $write);
        }

        // Peers LibreNMS discovered itself. The plugin's own (IPv6) peers are also in bgpPeers, but they are kept up to
        // date by ManagedPeers, so they are left out here.
        $manage = (bool) $this->settings->all()['manage_ipv6'];
        $owned = ManagedPeers::ownedPeers($device);
        $peers = BgpPeer::where('device_id', $device->device_id)->get()
            ->keyBy(fn (BgpPeer $p) => BgpSession::normaliseAddress((string) $p->bgpPeerIdentifier))
            ->reject(fn (BgpPeer $p, string $ip) => in_array($ip, $owned, true));

        $keep = [];
        $result['added'] = 0;
        foreach ($sessions as $session) {
            /** @var BgpPeer|null $peer */
            $peer = $peers->get($session->remoteAddress);
            $identifier = $peer ? (string) $peer->bgpPeerIdentifier : null;
            $added = false;

            // An IPv6 session LibreNMS cannot see over SNMP: the plugin adds it as a BGP peer itself.
            if ($identifier === null && $manage && $session->afi() === 'ipv6') {
                $identifier = $write ? ManagedPeers::sync($device, $session) : $session->remoteAddress;
                $added = $identifier !== null;
                if ($added) {
                    $keep[] = $identifier;
                    $result['added']++;
                }
            }

            $result['sessions'][] = [
                'name' => $session->name,
                'remote' => $session->remoteAddress,
                'prefixes' => $session->prefixCount,
                'established' => $session->established,
                'matched' => $identifier !== null,
                'added' => $added,
            ];
            if ($identifier === null) {
                continue;   // LibreNMS discovery has not seen this peer (yet); nothing to attach the graph to
            }
            $result['matched']++;
            if ($write) {
                $this->store($device, $identifier, $session->afi(), $session->prefixCount ?? 0, $added);
            }
        }

        // the plugin's peers whose session is gone from the router (or all of them, if the option is off)
        $removed = $write ? ManagedPeers::prune($device, $manage ? $keep : []) : 0;

        $result['ok'] = true;
        $result['message'] = sprintf('%d session(s) read, %d matched to LibreNMS BGP peers', count($sessions), $result['matched'])
            . ($result['added'] ? sprintf(' (%d IPv6 added by the plugin)', $result['added']) : '')
            . ($removed ? sprintf(', %d gone IPv6 peer(s) removed', $removed) : '');

        return $this->remember($device, $result, $write);
    }

    private function store(Device $device, string $peerIdentifier, string $afi, int $accepted, bool $managed = false): void
    {
        $key = [
            'device_id' => $device->device_id,
            'bgpPeerIdentifier' => $peerIdentifier,
            'afi' => $afi,
            'safi' => self::SAFI,
        ];

        $previous = (int) (DB::table('bgpPeers_cbgp')->where($key)->value('AcceptedPrefixes') ?? $accepted);

        $cbgp = [
            'context_name' => '',
            'AcceptedPrefixes' => $accepted,
            'AcceptedPrefixes_prev' => $previous,
            'AcceptedPrefixes_delta' => $accepted - $previous,
            'DeniedPrefixes' => 0, 'DeniedPrefixes_prev' => 0, 'DeniedPrefixes_delta' => 0,
            'PrefixAdminLimit' => 0, 'PrefixThreshold' => 0, 'PrefixClearThreshold' => 0,
            'AdvertisedPrefixes' => 0, 'AdvertisedPrefixes_prev' => 0, 'AdvertisedPrefixes_delta' => 0,
            'SuppressedPrefixes' => 0, 'SuppressedPrefixes_prev' => 0, 'SuppressedPrefixes_delta' => 0,
            'WithdrawnPrefixes' => 0, 'WithdrawnPrefixes_prev' => 0, 'WithdrawnPrefixes_delta' => 0,
        ];
        DB::table('bgpPeers_cbgp')->updateOrInsert($key, $cbgp);
        if ($managed) {
            ManagedPeers::rememberCbgp($device, $peerIdentifier, $key + $cbgp);
        }

        // Same RRD name and definition as includes/polling/bgp-peers.inc.php, so the core graphs read it.
        $rrdDef = RrdDefinition::make()
            ->addDataset('AcceptedPrefixes', 'GAUGE', null, 100000000000)
            ->addDataset('DeniedPrefixes', 'GAUGE', null, 100000000000)
            ->addDataset('AdvertisedPrefixes', 'GAUGE', null, 100000000000)
            ->addDataset('SuppressedPrefixes', 'GAUGE', null, 100000000000)
            ->addDataset('WithdrawnPrefixes', 'GAUGE', null, 100000000000);

        app('Datastore')->put($device, 'cbgp', [
            'bgpPeerIdentifier' => $peerIdentifier,
            'afi' => $afi,
            'safi' => self::SAFI,
            'rrd_name' => \LibreNMS\Data\Store\Rrd::safeName('cbgp-' . $peerIdentifier . ".$afi." . self::SAFI),
            'rrd_def' => $rrdDef,
        ], [
            'AcceptedPrefixes' => $accepted,
            'DeniedPrefixes' => 0,
            'AdvertisedPrefixes' => 0,
            'SuppressedPrefixes' => 0,
            'WithdrawnPrefixes' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function remember(Device $device, array $result, bool $write): array
    {
        if ($write) {
            Cache::put(self::statusKey($device->device_id), $result, now()->addDays(7));
        }

        return $result;
    }

    public static function statusKey(int $deviceId): string
    {
        return RouterosBgpProvider::PLUGIN . '.status.' . $deviceId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function lastStatus(int $deviceId): ?array
    {
        return Cache::get(self::statusKey($deviceId));
    }
}
