<?php

namespace SirZeruks\LibrenmsRouterosBgp\Hooks;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;
use SirZeruks\LibrenmsRouterosBgp\Poller;
use SirZeruks\LibrenmsRouterosBgp\SettingsStore;

/**
 * Small panel on the device overview of RouterOS devices with BGP peers.
 */
class DeviceOverview implements DeviceOverviewHook
{
    public function authorize(Device $device): bool
    {
        return $device->os === 'routeros' && (bool) auth()->user()?->can('view', $device);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function handle(string $pluginName, array $settings, Device $device, SettingsStore $store): \Illuminate\Contracts\View\View|string
    {
        if (! $store->all()['enabled'] || ! Poller::runsBgp($device)) {
            return '';
        }

        $rows = DB::table('bgpPeers as p')
            ->leftJoin('bgpPeers_cbgp as c', function ($j): void {
                $j->on('c.device_id', '=', 'p.device_id')->on('c.bgpPeerIdentifier', '=', 'p.bgpPeerIdentifier');
            })
            ->where('p.device_id', $device->device_id)
            ->orderBy('p.bgpPeerIdentifier')
            ->get(['p.bgpPeer_id', 'p.bgpPeerIdentifier', 'p.bgpPeerRemoteAs', 'p.bgpPeerDescr', 'p.bgpPeerState', 'c.afi', 'c.safi', 'c.AcceptedPrefixes', 'c.AcceptedPrefixes_delta']);

        return view("$pluginName::device-overview", [
            'device' => $device,
            'rows' => $rows,
            'status' => Poller::lastStatus($device->device_id),
            'enabled' => $store->deviceEnabled($device),
        ]);
    }
}
