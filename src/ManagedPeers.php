<?php

namespace SirZeruks\LibrenmsRouterosBgp;

use App\Models\Device;
use App\Models\Eventlog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LibreNMS\Enum\Severity;
use LibreNMS\RRD\RrdDefinition;

/**
 * BGP peers the PLUGIN adds to LibreNMS: IPv6 sessions, which LibreNMS cannot discover on RouterOS because the only
 * BGP MIB RouterOS offers over SNMP (BGP4-MIB) can only describe IPv4 peers.
 *
 * - Each such session becomes a normal row in LibreNMS's `bgpPeers` table (state, AS, uptime, messages), so it shows
 *   on the Routing > BGP pages, gets prefix and update graphs, and works with LibreNMS's BGP alert rules. The plugin
 *   writes the same "BGP Session Up/Down" event-log entries the LibreNMS poller writes.
 * - The plugin records which rows it created (its own table) and only ever changes or removes THOSE rows; a peer
 *   LibreNMS discovered itself is never touched.
 * - LibreNMS's discovery deletes BGP peers it did not find itself; the plugin puts its rows back right after each
 *   discovery (DeviceDiscovered), with the same IDs, so links, graphs and alert history stay intact.
 * - A session that disappears from the router is removed from LibreNMS on the next poll.
 *
 * LibreNMS's own BGP poller skips these rows (it finds no SNMP data for them), so the two never fight over a row.
 */
final class ManagedPeers
{
    public const TABLE = 'routeros_bgp_managed_peers';

    private static bool $tableReady = false;

    public static function ensureTable(): void
    {
        if (self::$tableReady) {
            return;
        }
        try {
            if (! Schema::hasTable(self::TABLE)) {
                Schema::create(self::TABLE, function (Blueprint $t): void {
                    $t->id();
                    $t->unsignedInteger('device_id');
                    $t->string('peer', 64);               // bgpPeerIdentifier
                    $t->unsignedInteger('bgp_peer_id');   // the bgpPeers row the plugin owns
                    $t->text('row');                      // last bgpPeers values, to restore after discovery
                    $t->text('cbgp')->nullable();         // last bgpPeers_cbgp values, to restore after discovery
                    $t->timestamps();
                    $t->unique(['device_id', 'peer']);
                });
            }
        } catch (\Throwable $e) {
            // another poller worker created it at the same moment
            if (! Schema::hasTable(self::TABLE)) {
                throw $e;
            }
        }
        self::$tableReady = true;
    }

    /**
     * Create or update the bgpPeers row for a session LibreNMS does not know. Returns the peer identifier, or null if
     * a row for this address exists that the plugin does not own (LibreNMS's own: left alone).
     */
    public static function sync(Device $device, BgpSession $s): ?string
    {
        self::ensureTable();
        $peer = $s->remoteAddress;
        $own = DB::table(self::TABLE)->where('device_id', $device->device_id)->where('peer', $peer)->first();
        $existing = DB::table('bgpPeers')->where('device_id', $device->device_id)->where('bgpPeerIdentifier', $peer)->first();

        if ($existing && (! $own || (int) $existing->bgpPeer_id !== (int) $own->bgp_peer_id)) {
            // LibreNMS's own row: never ours to change. If the plugin used to own this address, LibreNMS has taken it
            // over (it discovers the peer itself now): drop the plugin's record and leave the peer to LibreNMS.
            if ($own) {
                DB::table(self::TABLE)->where('id', $own->id)->delete();
            }

            return null;
        }

        $values = self::values($device, $s);
        if ($existing) {
            // keep what an admin may have edited (description) and the looked-up AS name
            unset($values['bgpPeerDescr'], $values['astext']);
            DB::table('bgpPeers')->where('bgpPeer_id', $existing->bgpPeer_id)->update($values);
            self::logStateChange($device, $s, (string) $existing->bgpPeerState, $existing);
            $id = (int) $existing->bgpPeer_id;
            $row = array_merge((array) $existing, $values);
        } else {
            $values['astext'] = self::astext($s->remoteAs);
            if ($own && ! DB::table('bgpPeers')->where('bgpPeer_id', $own->bgp_peer_id)->exists()) {
                // discovery removed it: put it back with the same ID
                $previous = (array) json_decode((string) $own->row, true);
                $values['bgpPeerDescr'] = $previous['bgpPeerDescr'] ?? $values['bgpPeerDescr'];
                $id = (int) $own->bgp_peer_id;
                DB::table('bgpPeers')->insert(['bgpPeer_id' => $id] + $values);
            } else {
                $id = (int) DB::table('bgpPeers')->insertGetId($values, 'bgpPeer_id');
            }
            $row = ['bgpPeer_id' => $id] + $values;
        }

        DB::table(self::TABLE)->updateOrInsert(
            ['device_id' => $device->device_id, 'peer' => $peer],
            ['bgp_peer_id' => $id, 'row' => json_encode($row), 'updated_at' => now(), 'created_at' => $own->created_at ?? now()],
        );

        self::storeUpdatesGraph($device, $s);

        return $peer;
    }

    /** Remember the last prefix-count row of a managed peer, to restore it after discovery. */
    public static function rememberCbgp(Device $device, string $peer, array $cbgpRow): void
    {
        self::ensureTable();
        $own = DB::table(self::TABLE)->where('device_id', $device->device_id)->where('peer', $peer)->first();
        if ($own) {
            $all = (array) json_decode((string) ($own->cbgp ?? '[]'), true);
            $all[$cbgpRow['afi'] . '.' . $cbgpRow['safi']] = $cbgpRow;
            DB::table(self::TABLE)->where('id', $own->id)->update(['cbgp' => json_encode($all)]);
        }
    }

    /**
     * Identifiers of the peers the plugin owns on this device: its record exists and the bgpPeers row for that address
     * is the plugin's row (or is missing, removed by discovery). A row LibreNMS discovered itself is never "owned".
     *
     * @return string[]
     */
    public static function ownedPeers(Device $device): array
    {
        self::ensureTable();
        $rows = DB::table('bgpPeers')->where('device_id', $device->device_id)->pluck('bgpPeer_id', 'bgpPeerIdentifier');

        return DB::table(self::TABLE)->where('device_id', $device->device_id)->get()
            ->filter(fn ($own) => ! isset($rows[$own->peer]) || (int) $rows[$own->peer] === (int) $own->bgp_peer_id)
            ->pluck('peer')->all();
    }

    /**
     * Remove the plugin's rows for sessions that are gone from the router ($keep = identifiers still present).
     *
     * @param  string[]  $keep
     */
    public static function prune(Device $device, array $keep): int
    {
        self::ensureTable();
        $removed = 0;
        foreach (DB::table(self::TABLE)->where('device_id', $device->device_id)->whereNotIn('peer', $keep ?: [''])->get() as $own) {
            DB::table('bgpPeers')->where('bgpPeer_id', $own->bgp_peer_id)->where('bgpPeerIdentifier', $own->peer)->delete();
            DB::table('bgpPeers_cbgp')->where('device_id', $device->device_id)->where('bgpPeerIdentifier', $own->peer)->delete();
            DB::table(self::TABLE)->where('id', $own->id)->delete();
            $removed++;
        }

        return $removed;
    }

    /** After LibreNMS's discovery: put back any of the plugin's rows it removed (same IDs), with their prefix counts. */
    public static function restore(Device $device): int
    {
        self::ensureTable();
        $restored = 0;
        foreach (DB::table(self::TABLE)->where('device_id', $device->device_id)->get() as $own) {
            if (DB::table('bgpPeers')->where('bgpPeer_id', $own->bgp_peer_id)->exists()) {
                continue;
            }
            $row = (array) json_decode((string) $own->row, true);
            if (! $row || DB::table('bgpPeers')->where('device_id', $device->device_id)->where('bgpPeerIdentifier', $own->peer)->exists()) {
                continue;   // LibreNMS now knows this peer itself: leave it to LibreNMS
            }
            $row['bgpPeer_id'] = (int) $own->bgp_peer_id;
            DB::table('bgpPeers')->insert(array_intersect_key($row, array_flip(self::COLUMNS)));
            foreach ((array) json_decode((string) ($own->cbgp ?? '[]'), true) as $cbgp) {
                DB::table('bgpPeers_cbgp')->updateOrInsert(
                    ['device_id' => $device->device_id, 'bgpPeerIdentifier' => $own->peer, 'afi' => $cbgp['afi'], 'safi' => $cbgp['safi']],
                    array_diff_key($cbgp, array_flip(['device_id', 'bgpPeerIdentifier', 'afi', 'safi'])),
                );
            }
            $restored++;
        }

        return $restored;
    }

    private const COLUMNS = ['bgpPeer_id', 'device_id', 'vrf_id', 'astext', 'bgpPeerIdentifier', 'bgpPeerRemoteAs', 'bgpPeerState',
        'bgpPeerAdminStatus', 'bgpPeerLastErrorCode', 'bgpPeerLastErrorSubCode', 'bgpPeerLastErrorText', 'bgpPeerIface',
        'bgpLocalAddr', 'bgpPeerRemoteAddr', 'bgpPeerDescr', 'bgpPeerInUpdates', 'bgpPeerOutUpdates', 'bgpPeerInTotalMessages',
        'bgpPeerOutTotalMessages', 'bgpPeerFsmEstablishedTime', 'bgpPeerInUpdateElapsedTime', 'context_name'];

    /** @return array<string, mixed> */
    private static function values(Device $device, BgpSession $s): array
    {
        $u32 = fn (?int $v) => max(0, min(4294967295, (int) $v));

        return [
            'device_id' => $device->device_id,
            'vrf_id' => null,
            'astext' => '',
            'bgpPeerIdentifier' => $s->remoteAddress,
            'bgpPeerRemoteAs' => (int) ($s->remoteAs ?? 0),
            'bgpPeerState' => $s->established ? 'established' : 'idle',
            'bgpPeerAdminStatus' => $s->stopped ? 'stop' : 'start',
            'bgpPeerLastErrorCode' => null,
            'bgpPeerLastErrorSubCode' => null,
            'bgpPeerLastErrorText' => null,
            'bgpPeerIface' => null,
            'bgpLocalAddr' => $s->localAddress,
            'bgpPeerRemoteAddr' => $s->remoteAddress,
            'bgpPeerDescr' => mb_substr($s->name, 0, 255),
            'bgpPeerInUpdates' => 0,
            'bgpPeerOutUpdates' => 0,
            'bgpPeerInTotalMessages' => $u32($s->inMessages),
            'bgpPeerOutTotalMessages' => $u32($s->outMessages),
            'bgpPeerFsmEstablishedTime' => $s->established ? $u32($s->uptimeSeconds) : 0,
            'bgpPeerInUpdateElapsedTime' => 0,
            'context_name' => '',
        ];
    }

    /** The same event-log entries LibreNMS's BGP poller writes, so alerting and the event log behave the same. */
    private static function logStateChange(Device $device, BgpSession $s, string $previous, object $row): void
    {
        $now = $s->established ? 'established' : 'idle';
        if ($previous === $now) {
            return;
        }
        $what = sprintf('%s (AS%s %s)', $s->remoteAddress, $s->remoteAs ?? $row->bgpPeerRemoteAs, $row->bgpPeerDescr);
        if ($now === 'established') {
            Eventlog::log('BGP Session Up: ' . $what, $device, 'bgpPeer', Severity::Ok, $s->remoteAddress);
        } elseif ($previous === 'established') {
            Eventlog::log('BGP Session Down: ' . $what, $device, 'bgpPeer', Severity::Error, $s->remoteAddress);
        }
    }

    /** The per-peer "updates" graph, same RRD name and definition as LibreNMS's BGP poller. */
    private static function storeUpdatesGraph(Device $device, BgpSession $s): void
    {
        $def = RrdDefinition::make()
            ->addDataset('bgpPeerOutUpdates', 'COUNTER', null, 100000000000)
            ->addDataset('bgpPeerInUpdates', 'COUNTER', null, 100000000000)
            ->addDataset('bgpPeerOutTotal', 'COUNTER', null, 100000000000)
            ->addDataset('bgpPeerInTotal', 'COUNTER', null, 100000000000)
            ->addDataset('bgpPeerEstablished', 'GAUGE', 0);
        app('Datastore')->put($device, 'bgp', [
            'bgpPeerIdentifier' => $s->remoteAddress,
            'rrd_name' => \LibreNMS\Data\Store\Rrd::safeName('bgp-' . $s->remoteAddress),
            'rrd_def' => $def,
        ], [
            'bgpPeerOutUpdates' => 0,
            'bgpPeerInUpdates' => 0,
            'bgpPeerOutTotal' => (int) ($s->outMessages ?? 0),
            'bgpPeerInTotal' => (int) ($s->inMessages ?? 0),
            'bgpPeerEstablished' => $s->established ? $s->uptimeSeconds : 0,
        ]);
    }

    private static function astext(?int $as): string
    {
        if (! $as) {
            return '';
        }
        try {
            return mb_substr((string) \LibreNMS\Util\AutonomousSystem::get($as)->name(), 0, 255);
        } catch (\Throwable $e) {
            Log::debug('routeros-bgp: AS name lookup failed: ' . $e->getMessage());

            return '';
        }
    }
}
