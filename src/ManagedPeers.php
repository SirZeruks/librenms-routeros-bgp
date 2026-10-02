<?php

namespace SirZeruks\LibrenmsRouterosBgp;

use App\Models\Device;
use App\Models\Eventlog;
use App\Models\Plugin;
use Illuminate\Support\Facades\Cache;
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
 * - The plugin records which rows it created and only ever changes or removes THOSE rows; a peer LibreNMS discovered
 *   itself is never touched. The record ("owned") lives in the plugin's own settings row in LibreNMS's `plugins`
 *   table: per device, peer address => [bgpPeer_id, description]. No extra database table, so LibreNMS's schema
 *   validation stays clean.
 * - LibreNMS's discovery deletes BGP peers it did not find itself; right after each discovery the plugin reads the
 *   router again and puts its rows back with the same IDs (links, graphs and alert history stay intact).
 * - A session that disappears from the router is removed from LibreNMS on the next poll.
 *
 * LibreNMS's own BGP poller skips these rows (it finds no SNMP data for them), so the two never fight over a row.
 */
final class ManagedPeers
{
    /** Table used by 0.3.0 for the same record; migrated into the settings and dropped (LibreNMS flags extra tables). */
    private const LEGACY_TABLE = 'routeros_bgp_managed_peers';

    private static bool $legacyChecked = false;

    /**
     * The plugin's peers on this device, read fresh from the database: address => ['id' => int, 'descr' => string].
     *
     * @return array<string, array{id: int, descr: string}>
     */
    public static function owned(Device $device): array
    {
        self::migrateLegacyTable();

        return (array) (self::settingsRow()['owned'][(string) $device->device_id] ?? []);
    }

    /**
     * Save this device's record. Locked, and read-modify-write on a FRESH copy of the settings, so poller workers
     * handling other devices at the same time, or an admin saving the settings page, never lose each other's changes.
     *
     * @param  array<string, array{id: int, descr: string}>  $owned
     */
    public static function saveOwned(Device $device, array $owned): void
    {
        self::locked(function () use ($device, $owned): void {
            $plugin = Plugin::where('plugin_name', RouterosBgpProvider::PLUGIN)->first();
            if (! $plugin) {
                return;
            }
            $settings = (array) $plugin->settings;
            if ($owned) {
                $settings['owned'][(string) $device->device_id] = $owned;
            } else {
                unset($settings['owned'][(string) $device->device_id]);
            }
            $plugin->settings = $settings;
            $plugin->save();
        });
    }

    /**
     * Addresses of the peers the plugin owns on this device: its record exists and the bgpPeers row for that address
     * is the plugin's row (or is missing, removed by discovery). A row LibreNMS discovered itself is never "owned".
     *
     * @param  array<string, array{id: int, descr: string}>  $owned
     * @return string[]
     */
    public static function ownedPeers(Device $device, array $owned): array
    {
        $rows = DB::table('bgpPeers')->where('device_id', $device->device_id)->pluck('bgpPeer_id', 'bgpPeerIdentifier');

        return array_values(array_filter(array_keys($owned), fn (string $peer) => ! isset($rows[$peer]) || (int) $rows[$peer] === (int) $owned[$peer]['id']));
    }

    /**
     * Create or update the bgpPeers row for a session LibreNMS does not know, updating $owned. Returns the peer
     * address, or null if a row for this address exists that the plugin does not own (LibreNMS's own: left alone).
     *
     * @param  array<string, array{id: int, descr: string}>  $owned
     */
    public static function sync(Device $device, BgpSession $s, array &$owned): ?string
    {
        $peer = $s->remoteAddress;
        $own = $owned[$peer] ?? null;
        $existing = DB::table('bgpPeers')->where('device_id', $device->device_id)->where('bgpPeerIdentifier', $peer)->first();

        if ($existing && (! $own || (int) $existing->bgpPeer_id !== (int) $own['id'])) {
            // LibreNMS's own row: never ours to change. If the plugin used to own this address, LibreNMS has taken it
            // over (it discovers the peer itself now): forget it and leave the peer to LibreNMS.
            unset($owned[$peer]);

            return null;
        }

        $values = self::values($device, $s);
        if ($existing) {
            // keep what an admin may have edited (description) and the looked-up AS name
            unset($values['bgpPeerDescr'], $values['astext']);
            DB::table('bgpPeers')->where('bgpPeer_id', $existing->bgpPeer_id)->update($values);
            self::logStateChange($device, $s, (string) $existing->bgpPeerState, (string) $existing->bgpPeerDescr);
            $id = (int) $existing->bgpPeer_id;
            $descr = (string) $existing->bgpPeerDescr;
        } else {
            $values['astext'] = self::astext($s->remoteAs);
            if ($own && ! DB::table('bgpPeers')->where('bgpPeer_id', $own['id'])->exists()) {
                // discovery removed it: put it back with the same ID and the description it had
                $values['bgpPeerDescr'] = $own['descr'] !== '' ? $own['descr'] : $values['bgpPeerDescr'];
                $id = (int) $own['id'];
                DB::table('bgpPeers')->insert(['bgpPeer_id' => $id] + $values);
            } else {
                $id = (int) DB::table('bgpPeers')->insertGetId($values, 'bgpPeer_id');
            }
            $descr = (string) $values['bgpPeerDescr'];
        }

        $owned[$peer] = ['id' => $id, 'descr' => mb_substr($descr, 0, 255)];
        self::storeUpdatesGraph($device, $s);

        return $peer;
    }

    /**
     * Remove the plugin's rows for sessions that are gone from the router ($keep = addresses still present).
     *
     * @param  string[]  $keep
     * @param  array<string, array{id: int, descr: string}>  $owned
     */
    public static function prune(Device $device, array $keep, array &$owned): int
    {
        $removed = 0;
        foreach (array_diff(array_keys($owned), $keep) as $peer) {
            DB::table('bgpPeers')->where('bgpPeer_id', $owned[$peer]['id'])->where('bgpPeerIdentifier', $peer)->delete();
            DB::table('bgpPeers_cbgp')->where('device_id', $device->device_id)->where('bgpPeerIdentifier', $peer)->delete();
            unset($owned[$peer]);
            $removed++;
        }

        return $removed;
    }

    /** One plugin-wide lock for writes to the plugin's settings row (the record and the settings page). */
    public static function locked(callable $fn): mixed
    {
        try {
            $lock = Cache::lock(RouterosBgpProvider::PLUGIN . '.settings-lock', 30);
            return $lock->block(15, $fn);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            throw $e;
        } catch (\Throwable) {
            return $fn();   // cache store without locks: write anyway
        }
    }

    /** @return array<string, mixed> the plugin's settings, read fresh (no in-process cache) */
    private static function settingsRow(): array
    {
        return (array) (Plugin::where('plugin_name', RouterosBgpProvider::PLUGIN)->value('settings') ?? []);
    }

    /** 0.3.0 kept the record in its own table, which LibreNMS's schema validation reports as a failure: move and drop it. */
    private static function migrateLegacyTable(): void
    {
        if (self::$legacyChecked) {
            return;
        }
        self::$legacyChecked = true;
        try {
            if (! Schema::hasTable(self::LEGACY_TABLE)) {
                return;
            }
            $byDevice = [];
            foreach (DB::table(self::LEGACY_TABLE)->get() as $r) {
                $row = (array) json_decode((string) ($r->row ?? ''), true);
                $byDevice[(string) $r->device_id][$r->peer] = ['id' => (int) $r->bgp_peer_id, 'descr' => (string) ($row['bgpPeerDescr'] ?? '')];
            }
            self::locked(function () use ($byDevice): void {
                $plugin = Plugin::where('plugin_name', RouterosBgpProvider::PLUGIN)->first();
                if ($plugin && $byDevice) {
                    $settings = (array) $plugin->settings;
                    foreach ($byDevice as $deviceId => $peers) {
                        $settings['owned'][$deviceId] = array_merge((array) ($settings['owned'][$deviceId] ?? []), $peers);
                    }
                    $plugin->settings = $settings;
                    $plugin->save();
                }
            });
            Schema::drop(self::LEGACY_TABLE);
            Log::info('routeros-bgp: moved the IPv6 peer record into the plugin settings and dropped table ' . self::LEGACY_TABLE);
        } catch (\Throwable $e) {
            Log::error('routeros-bgp: could not migrate ' . self::LEGACY_TABLE . ': ' . $e->getMessage());
        }
    }

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
    private static function logStateChange(Device $device, BgpSession $s, string $previous, string $descr): void
    {
        $now = $s->established ? 'established' : 'idle';
        if ($previous === $now) {
            return;
        }
        $what = sprintf('%s (AS%s %s)', $s->remoteAddress, $s->remoteAs ?? '?', $descr);
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
