<?php

namespace SirZeruks\LibrenmsRouterosBgp\Hooks;

use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;
use SirZeruks\LibrenmsRouterosBgp\Poller;
use SirZeruks\LibrenmsRouterosBgp\RouterosBgpProvider;
use SirZeruks\LibrenmsRouterosBgp\SettingsStore;
use SirZeruks\LibrenmsRouterosBgp\Transport\ConnectionConfig;

class Settings implements SettingsHook
{
    public function authorize(): bool
    {
        return (bool) auth()->user()?->can('plugin.admin');
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function handle(string $pluginName, array $settings, SettingsStore $store): array
    {
        $all = $store->all();

        // Never send secrets to the browser - only whether one is stored.
        $defaults = $all['defaults'];
        foreach (SettingsStore::SECRET_FIELDS as $f) {
            $defaults[$f . '_set'] = (string) ($defaults[$f] ?? '') !== '';
            unset($defaults[$f]);
        }

        $devices = Poller::candidateDevices();
        $overrides = [];
        foreach ($all['devices'] as $id => $o) {
            $o = array_merge(SettingsStore::blankOverride(), (array) $o);
            foreach (SettingsStore::SECRET_FIELDS as $f) {
                $o[$f . '_set'] = (string) $o[$f] !== '';
                unset($o[$f]);
            }
            $overrides[(int) $id] = $o;
        }

        $rows = [];
        foreach ($devices as $device) {
            $rows[] = [
                'device' => $device,
                'override' => $overrides[$device->device_id] ?? null,
                'enabled' => $store->deviceEnabled($device),
                'connection' => $store->connectionFor($device),
                'status' => Poller::lastStatus($device->device_id),
                'peers' => $device->bgppeers()->count(),
            ];
        }

        return [
            'title' => 'RouterOS BGP prefixes',
            'content_view' => "$pluginName::settings",
            'settings' => [
                'plugin' => RouterosBgpProvider::PLUGIN,
                'version' => RouterosBgpProvider::VERSION,
                'enabled' => $all['enabled'],
                'manage_ipv6' => $all['manage_ipv6'],
                'defaults' => $defaults,
                'rows' => $rows,
                'transports' => ConnectionConfig::TRANSPORTS,
                'run' => \SirZeruks\LibrenmsRouterosBgp\PollRun::state(),
            ],
        ];
    }
}
