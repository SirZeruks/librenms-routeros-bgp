<?php

namespace SirZeruks\LibrenmsRouterosBgp;

use App\Models\Device;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use SirZeruks\LibrenmsRouterosBgp\Transport\ConnectionConfig;

/**
 * Plugin settings, stored in the LibreNMS `plugins.settings` column via the PluginManager.
 *
 * Layout:
 *   enabled   bool    master switch for collection
 *   manage_ipv6 bool  add IPv6 sessions (which SNMP cannot show) as LibreNMS BGP peers (default on)
 *   defaults  array   transport, port (0 = transport default), username, password*, ssh_key*, verify_tls, timeout
 *   devices   array   device_id => enabled, host, transport ('' = inherit), port (0 = inherit),
 *                     username ('' = inherit), password* ('' = inherit), ssh_key* ('' = inherit),
 *                     verify_tls ('' = inherit, '1', '0')
 *
 * Fields marked * are stored encrypted with the LibreNMS APP_KEY and never sent back to the browser.
 */
final class SettingsStore
{
    public const SECRET_FIELDS = ['password', 'ssh_key'];

    public function __construct(private readonly PluginManagerInterface $manager)
    {
    }

    /**
     * @return array{enabled: bool, defaults: array<string, mixed>, devices: array<int|string, array<string, mixed>>}
     */
    public function all(): array
    {
        $s = $this->manager->getSettings(RouterosBgpProvider::PLUGIN);

        return [
            'enabled' => (bool) ($s['enabled'] ?? false),
            'manage_ipv6' => (bool) ($s['manage_ipv6'] ?? true),
            'defaults' => array_merge(self::blankDefaults(), (array) ($s['defaults'] ?? [])),
            'devices' => (array) ($s['devices'] ?? []),
        ];
    }

    /**
     * @param  array{enabled: bool, defaults: array<string, mixed>, devices: array<int|string, array<string, mixed>>}  $settings
     */
    public function save(array $settings): void
    {
        // keep the plugin's record of the IPv6 peers it added (ManagedPeers), which the settings page does not edit;
        // read fresh and write under the same lock the pollers use, so neither side overwrites the other
        ManagedPeers::locked(function () use ($settings): void {
            $current = (array) (\App\Models\Plugin::where('plugin_name', RouterosBgpProvider::PLUGIN)->value('settings') ?? []);
            if (isset($current['owned'])) {
                $settings['owned'] = $current['owned'];
            }
            $this->manager->setSettings(RouterosBgpProvider::PLUGIN, $settings);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function blankDefaults(): array
    {
        return [
            'transport' => ConnectionConfig::REST_HTTPS,
            'port' => 0,
            'username' => '',
            'password' => '',
            'ssh_key' => '',
            'verify_tls' => false,
            'timeout' => 10,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function blankOverride(): array
    {
        return [
            'enabled' => true,
            'host' => '',
            'transport' => '',
            'port' => 0,
            'username' => '',
            'password' => '',
            'ssh_key' => '',
            'verify_tls' => '',
        ];
    }

    public static function encrypt(string $plain): string
    {
        return $plain === '' ? '' : Crypt::encryptString($plain);
    }

    public static function decrypt(string $stored): string
    {
        if ($stored === '') {
            return '';
        }
        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return '';   // APP_KEY changed: treat as not set, the UI will show the secret is missing
        }
    }

    /**
     * Is collection switched on for this device?
     */
    public function deviceEnabled(Device $device): bool
    {
        $s = $this->all();
        $override = $s['devices'][$device->device_id] ?? null;

        return $s['enabled'] && ($override === null || ! empty($override['enabled']));
    }

    /**
     * Merge the defaults with the device override.
     */
    public function connectionFor(Device $device): ConnectionConfig
    {
        $s = $this->all();
        $d = $s['defaults'];
        $o = array_merge(self::blankOverride(), (array) ($s['devices'][$device->device_id] ?? []));

        $transport = $o['transport'] !== '' ? (string) $o['transport'] : (string) $d['transport'];
        if (! ConnectionConfig::validTransport($transport)) {
            $transport = ConnectionConfig::REST_HTTPS;
        }

        $port = (int) $o['port'] ?: (int) $d['port'] ?: ConnectionConfig::defaultPort($transport);
        $verify = $o['verify_tls'] === '' ? (bool) $d['verify_tls'] : (bool) (int) $o['verify_tls'];

        $host = trim((string) $o['host']);
        if ($host === '') {
            $host = (string) ($device->overwrite_ip ?: $device->hostname);
        }

        return new ConnectionConfig(
            host: $host,
            transport: $transport,
            port: $port,
            username: $o['username'] !== '' ? (string) $o['username'] : (string) $d['username'],
            password: self::decrypt($o['password'] !== '' ? (string) $o['password'] : (string) $d['password']),
            sshKey: self::decrypt($o['ssh_key'] !== '' ? (string) $o['ssh_key'] : (string) $d['ssh_key']),
            verifyTls: $verify,
            timeout: max(2, min(60, (int) $d['timeout'] ?: 10)),
        );
    }
}
