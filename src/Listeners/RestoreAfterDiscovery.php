<?php

namespace SirZeruks\LibrenmsRouterosBgp\Listeners;

use App\Events\DeviceDiscovered;
use Illuminate\Support\Facades\Log;
use SirZeruks\LibrenmsRouterosBgp\ManagedPeers;
use SirZeruks\LibrenmsRouterosBgp\SettingsStore;

/**
 * LibreNMS's discovery removes BGP peers it did not find itself, which includes the IPv6 peers the plugin adds.
 * Right after a device's discovery the plugin puts them back with the same IDs (graphs, links and alert history
 * stay intact); the next poll then brings them up to date.
 */
class RestoreAfterDiscovery
{
    public function __construct(private readonly SettingsStore $settings)
    {
    }

    public function handle(DeviceDiscovered $event): void
    {
        $device = $event->device;
        if ($device->os !== 'routeros') {
            return;
        }
        try {
            $all = $this->settings->all();
            if ($all['enabled'] && $all['manage_ipv6']) {
                $n = ManagedPeers::restore($device);
                if ($n) {
                    Log::info(sprintf('routeros-bgp: %s: restored %d IPv6 peer(s) after discovery', $device->displayName(), $n));
                }
            }
        } catch (\Throwable $e) {
            Log::error('routeros-bgp: restoring IPv6 peers failed for ' . $device->displayName() . ': ' . $e->getMessage());
        }
    }
}
