<?php

namespace SirZeruks\LibrenmsRouterosBgp\Listeners;

use App\Events\DeviceDiscovered;
use Illuminate\Support\Facades\Log;
use SirZeruks\LibrenmsRouterosBgp\ManagedPeers;
use SirZeruks\LibrenmsRouterosBgp\Poller;
use SirZeruks\LibrenmsRouterosBgp\SettingsStore;

/**
 * LibreNMS's discovery removes BGP peers it did not find itself, which includes the IPv6 peers the plugin adds.
 * Right after a device's discovery the plugin reads the router again and puts them back with the same IDs (graphs,
 * links and alert history stay intact).
 */
class RestoreAfterDiscovery
{
    public function __construct(private readonly SettingsStore $settings, private readonly Poller $poller)
    {
    }

    public function handle(DeviceDiscovered $event): void
    {
        $device = $event->device;
        if ($device->os !== 'routeros' || ! $device->status) {
            return;
        }
        try {
            $all = $this->settings->all();
            if (! $all['manage_ipv6'] || ! $this->settings->deviceEnabled($device) || ! ManagedPeers::owned($device)) {
                return;
            }
            // read the router again: sync() puts back every IPv6 peer discovery removed, with its old ID
            $r = $this->poller->poll($device);
            Log::info(sprintf('routeros-bgp: %s: after discovery: %s', $device->displayName(), $r['message']));
        } catch (\Throwable $e) {
            Log::error('routeros-bgp: restoring IPv6 peers failed for ' . $device->displayName() . ': ' . $e->getMessage());
        }
    }
}
