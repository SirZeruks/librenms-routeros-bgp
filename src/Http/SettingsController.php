<?php

namespace SirZeruks\LibrenmsRouterosBgp\Http;

use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use SirZeruks\LibrenmsRouterosBgp\Poller;
use SirZeruks\LibrenmsRouterosBgp\PollRun;
use SirZeruks\LibrenmsRouterosBgp\RouterosBgpProvider;
use SirZeruks\LibrenmsRouterosBgp\SettingsStore;
use SirZeruks\LibrenmsRouterosBgp\Updater;
use SirZeruks\LibrenmsRouterosBgp\Transport\ConnectionConfig;

class SettingsController extends Controller
{
    private const HOST_RULE = 'regex:/^[A-Za-z0-9.:\-\[\]]+$/';

    public function __construct(private readonly SettingsStore $store)
    {
    }

    /** Save the master switch and the global defaults. */
    public function saveDefaults(Request $request): RedirectResponse
    {
        $v = $request->validate([
            'enabled' => 'nullable|in:0,1',
            'manage_ipv6' => 'nullable|in:0,1',
            'transport' => ['required', Rule::in(array_keys(ConnectionConfig::TRANSPORTS))],
            'port' => 'nullable|integer|between:1,65535',
            'username' => 'nullable|string|max:64',
            'password' => 'nullable|string|max:256',
            'ssh_key' => 'nullable|string|max:16384',
            'clear_password' => 'nullable|in:1',
            'clear_ssh_key' => 'nullable|in:1',
            'verify_tls' => 'nullable|in:0,1',
            'timeout' => 'nullable|integer|between:2,60',
        ]);

        $all = $this->store->all();
        $d = $all['defaults'];
        $all['enabled'] = ($v['enabled'] ?? '0') === '1';
        $all['manage_ipv6'] = ($v['manage_ipv6'] ?? '0') === '1';
        $d['transport'] = $v['transport'];
        $d['port'] = (int) ($v['port'] ?? 0);
        $d['username'] = trim((string) ($v['username'] ?? ''));
        $d['verify_tls'] = ($v['verify_tls'] ?? '0') === '1';
        $d['timeout'] = (int) ($v['timeout'] ?? 10);
        $this->applySecrets($d, $v);
        $all['defaults'] = $d;

        $this->store->save($all);
        $this->toast('success', 'RouterOS BGP: settings saved');

        return $this->back();
    }

    /** Add or update the override for one device. */
    public function saveDevice(Request $request): RedirectResponse
    {
        $v = $request->validate([
            'device_id' => 'required|integer|exists:devices,device_id',
            'enabled' => 'nullable|in:0,1',
            'host' => ['nullable', 'string', 'max:253', self::HOST_RULE],
            'transport' => ['nullable', Rule::in(array_merge([''], array_keys(ConnectionConfig::TRANSPORTS)))],
            'port' => 'nullable|integer|between:1,65535',
            'username' => 'nullable|string|max:64',
            'password' => 'nullable|string|max:256',
            'ssh_key' => 'nullable|string|max:16384',
            'clear_password' => 'nullable|in:1',
            'clear_ssh_key' => 'nullable|in:1',
            'verify_tls' => 'nullable|in:0,1',
        ]);

        $id = (int) $v['device_id'];
        $all = $this->store->all();
        $o = array_merge(SettingsStore::blankOverride(), (array) ($all['devices'][$id] ?? []));
        $o['enabled'] = ($v['enabled'] ?? '0') === '1';
        $o['host'] = trim((string) ($v['host'] ?? ''), " []");
        $o['transport'] = (string) ($v['transport'] ?? '');
        $o['port'] = (int) ($v['port'] ?? 0);
        $o['username'] = trim((string) ($v['username'] ?? ''));
        $o['verify_tls'] = (string) ($v['verify_tls'] ?? '');
        $this->applySecrets($o, $v);
        $all['devices'][$id] = $o;

        $this->store->save($all);
        $this->toast('success', 'RouterOS BGP: device settings saved for ' . Device::find($id)?->displayName());

        return $this->back();
    }

    /** Remove a device override (the device falls back to the defaults). */
    public function deleteDevice(int $deviceId): RedirectResponse
    {
        $all = $this->store->all();
        unset($all['devices'][$deviceId]);
        $this->store->save($all);
        $this->toast('success', 'RouterOS BGP: device now uses the default settings');

        return $this->back();
    }

    /** Read the router now. mode=test only reads; mode=poll also stores the counts. */
    public function run(Request $request, Device $device, Poller $poller): JsonResponse
    {
        if ($device->os !== 'routeros') {
            return response()->json(['ok' => false, 'message' => 'Not a RouterOS device'], 422);
        }

        return response()->json($poller->poll($device, $request->input('mode') === 'poll'));
    }

    /** "Poll all now": start a full run in the background and return at once (24 routers can take minutes). */
    public function pollAll(): JsonResponse
    {
        if (! $this->store->all()['enabled']) {
            return response()->json(['started' => false, 'message' => 'Collection is switched off. Tick "Collect prefix counts" and save first.'], 422);
        }
        $state = PollRun::state();
        if ($state && $state['running']) {
            return response()->json(['started' => false, 'message' => 'A poll is already running.', 'run' => $state]);
        }

        $total = Poller::candidateDevices()->count();
        PollRun::requested($total);   // shown at once; the background run overwrites it with the same numbers

        $cmd = sprintf('cd %s && nohup %s artisan routeros-bgp:poll --by=manual > /dev/null 2>&1 &',
            escapeshellarg(base_path()), escapeshellarg(self::phpCli()));
        exec($cmd);

        return response()->json(['started' => true, 'message' => "Polling $total device(s) in the background.", 'run' => PollRun::state()]);
    }

    /** Installed vs newest release (the newest is looked up on Packagist, cached for an hour). */
    public function updateCheck(Request $request): JsonResponse
    {
        $installed = Updater::installedVersion();
        $latest = Updater::latestVersion((bool) $request->boolean('refresh'));

        return response()->json([
            'installed' => $installed,
            'latest' => $latest,
            'dev_install' => Updater::isDevInstall($installed),
            'available' => Updater::newer($latest, $installed),
            'run' => Updater::state(),
        ]);
    }

    /** "Update now": install the newest release in the background (plugin:add + route:cache). */
    public function updateStart(): JsonResponse
    {
        $installed = Updater::installedVersion();
        $latest = Updater::latestVersion(true);
        if (Updater::isDevInstall($installed)) {
            return response()->json(['started' => false, 'message' => 'This is a development install; update it from its source instead.'], 422);
        }
        if (! Updater::newer($latest, $installed)) {
            return response()->json(['started' => false, 'message' => 'Already up to date.'], 422);
        }

        return response()->json(Updater::start((string) $latest) + ['run' => Updater::state()]);
    }

    public function updateStatus(): JsonResponse
    {
        return response()->json(['run' => Updater::state(), 'installed' => Updater::installedVersion()]);
    }

    /** Progress of the latest run, for the settings page. */
    public function runStatus(): JsonResponse
    {
        PollRun::checkStarted();

        return response()->json(['run' => PollRun::state()]);
    }

    /** The PHP CLI binary (the web server runs php-fpm, whose PHP_BINARY cannot run artisan). */
    private static function phpCli(): string
    {
        foreach ([PHP_BINDIR . '/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, PHP_BINDIR . '/php'] as $bin) {
            if (is_executable($bin)) {
                return $bin;
            }
        }

        return 'php';
    }

    /**
     * Blank secret field = keep what is stored; the clear_* checkbox removes it.
     *
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $input
     */
    private function applySecrets(array &$target, array $input): void
    {
        foreach (SettingsStore::SECRET_FIELDS as $f) {
            if (! empty($input["clear_$f"])) {
                $target[$f] = '';
            } elseif (($input[$f] ?? '') !== '') {
                $target[$f] = SettingsStore::encrypt((string) $input[$f]);
            }
        }
    }

    private function back(): RedirectResponse
    {
        return redirect()->route('plugin.settings', ['plugin' => RouterosBgpProvider::PLUGIN]);
    }

    private function toast(string $level, string $message): void
    {
        if (function_exists('toast')) {
            toast()->$level($message);
        }
    }
}
