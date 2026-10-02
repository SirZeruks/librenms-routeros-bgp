<?php

namespace SirZeruks\LibrenmsRouterosBgp\Listeners;

use App\Events\DevicePolled;
use Illuminate\Support\Facades\Log;
use SirZeruks\LibrenmsRouterosBgp\Poller;
use SirZeruks\LibrenmsRouterosBgp\SettingsStore;

/**
 * Reads a RouterOS device's BGP prefix counts as the last step of its normal LibreNMS poll.
 *
 * LibreNMS fires DevicePolled at the end of every device poll, inside the poller worker (the same hook its own
 * alerting and device-group updates use). Listening here means collection is queued, spread over the poller
 * workers and timed exactly like the rest of the device's polling, instead of being a separate job.
 */
class CollectOnDevicePoll
{
    public function __construct(private readonly SettingsStore $settings, private readonly Poller $poller)
    {
    }

    public function handle(DevicePolled $event): void
    {
        $device = $event->device;

        // cheap checks first: this runs after EVERY device poll
        if ($device->os !== 'routeros' || ! $device->status) {
            return;
        }

        try {
            if (! $this->settings->deviceEnabled($device) || ! Poller::runsBgp($device)) {
                return;
            }
            $r = $this->poller->poll($device);
            Log::info(sprintf('routeros-bgp: %s: %s', $device->displayName(), $r['message']));
        } catch (\Throwable $e) {
            // never let the plugin break the device poll
            Log::error('routeros-bgp: collection failed for ' . $device->displayName() . ': ' . $e->getMessage());
        }
    }
}
