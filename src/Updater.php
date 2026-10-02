<?php

namespace SirZeruks\LibrenmsRouterosBgp;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * "Update now" from the settings page: checks Packagist for a newer release of THIS package and installs it with
 * LibreNMS's own `lnms plugin:add`, then refreshes the route cache, in the background.
 */
final class Updater
{
    public const PACKAGE = 'sirzeruks/librenms-routeros-bgp';

    private const METADATA_URL = 'https://repo.packagist.org/p2/sirzeruks/librenms-routeros-bgp.json';

    private const STATE = RouterosBgpProvider::PLUGIN . '.update';

    private const MAX_SECONDS = 900;

    public static function installedVersion(): string
    {
        try {
            return InstalledVersions::getPrettyVersion(self::PACKAGE) ?? 'unknown';
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    /** Installed from a local folder or a branch: updates come from there, not from Packagist. */
    public static function isDevInstall(string $installed): bool
    {
        return str_starts_with($installed, 'dev-') || str_ends_with($installed, '-dev') || $installed === 'unknown';
    }

    /** Newest stable release on Packagist (cached for an hour), or null if it cannot be determined. */
    public static function latestVersion(bool $refresh = false): ?string
    {
        if ($refresh) {
            Cache::forget(self::STATE . '.latest');
        }

        return Cache::remember(self::STATE . '.latest', 3600, function (): ?string {
            try {
                $res = Http::timeout(5)->acceptJson()->get(self::METADATA_URL);

                return $res->successful() ? self::parseLatest((array) $res->json()) : null;
            } catch (\Throwable) {
                return null;
            }
        });
    }

    /**
     * Pick the newest STABLE version out of Packagist's p2 metadata.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function parseLatest(array $metadata): ?string
    {
        $latest = null;
        foreach ((array) ($metadata['packages'][self::PACKAGE] ?? []) as $entry) {
            $v = is_array($entry) ? (string) ($entry['version'] ?? '') : '';
            if (! self::isRelease($v)) {
                continue;
            }
            if ($latest === null || version_compare(self::norm($v), self::norm($latest), '>')) {
                $latest = $v;
            }
        }

        return $latest;
    }

    /** A plain release number such as 0.3.0 or v1.2.10: nothing else is ever passed on to the shell. */
    public static function isRelease(string $v): bool
    {
        return (bool) preg_match('/^v?\d{1,4}\.\d{1,4}\.\d{1,4}$/', $v);
    }

    public static function newer(?string $latest, string $installed): bool
    {
        return $latest !== null && self::isRelease($installed) && version_compare(self::norm($latest), self::norm($installed), '>');
    }

    private static function norm(string $v): string
    {
        return ltrim($v, 'vV');
    }

    /**
     * @return array<string, mixed>|null  the latest update run: started, finished, ok, version, log (tail)
     */
    public static function state(): ?array
    {
        $s = Cache::get(self::STATE);
        if (! is_array($s)) {
            return null;
        }
        // the background job writes its exit code and output to files; pick them up once it has finished
        if ($s['finished'] === null && is_file($s['rc_file'] ?? '')) {
            $rc = trim((string) @file_get_contents($s['rc_file']));
            $s['finished'] = time();
            $s['ok'] = $rc === '0';
            $s['log'] = self::tail((string) ($s['log_file'] ?? ''));
            @unlink($s['rc_file']);
            Cache::put(self::STATE, $s, now()->addDays(7));
        }
        $s['running'] = $s['finished'] === null && time() - (int) $s['started'] < self::MAX_SECONDS;
        if ($s['finished'] === null && ! $s['running']) {
            $s['ok'] = false;
            $s['log'] = self::tail((string) ($s['log_file'] ?? '')) ?: 'The update did not finish within 15 minutes.';
        }

        return $s;
    }

    /**
     * Start the update in the background.
     *
     * @return array{started: bool, message: string}
     */
    public static function start(string $version): array
    {
        if (! self::isRelease($version)) {
            return ['started' => false, 'message' => 'No valid release to update to.'];
        }
        $current = self::state();
        if ($current && $current['running']) {
            return ['started' => false, 'message' => 'An update is already running.'];
        }

        $dir = storage_path('logs');
        $log = $dir . '/routeros-bgp-update.log';
        $rc = $dir . '/routeros-bgp-update.rc';
        @unlink($rc);

        $php = escapeshellarg(self::phpCli());
        $base = escapeshellarg(base_path());
        $pkg = escapeshellarg(self::PACKAGE . ':^' . self::norm($version));
        // plugin:add replaces the package (as the web/LibreNMS user); NEW processes then cache the routes with the new code
        // and clear the compiled views (installed files can carry OLDER timestamps than the compiled copies, which would
        // otherwise keep serving the previous version's pages)
        $script = "cd $base && $php lnms plugin:add $pkg && $php artisan route:cache && $php artisan view:clear";
        $cmd = sprintf('nohup bash -c %s > %s 2>&1; echo $? > %s',
            escapeshellarg($script), escapeshellarg($log), escapeshellarg($rc));
        exec(sprintf('(%s) > /dev/null 2>&1 &', $cmd));

        Cache::put(self::STATE, [
            'version' => $version, 'started' => time(), 'finished' => null, 'ok' => null,
            'log' => '', 'log_file' => $log, 'rc_file' => $rc,
        ], now()->addDays(7));

        return ['started' => true, 'message' => "Updating to $version in the background."];
    }

    private static function tail(string $file, int $lines = 25): string
    {
        if (! is_file($file)) {
            return '';
        }
        $all = preg_split('/\r?\n/', (string) @file_get_contents($file)) ?: [];
        // drop ANSI colour codes composer prints
        return preg_replace('/\x1b\[[0-9;]*m/', '', implode("\n", array_slice(array_filter($all, 'strlen'), -$lines))) ?? '';
    }

    /** The PHP CLI binary (the web server runs php-fpm, whose PHP_BINARY cannot run artisan). */
    public static function phpCli(): string
    {
        foreach ([PHP_BINDIR . '/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, PHP_BINDIR . '/php'] as $bin) {
            if (is_executable($bin)) {
                return $bin;
            }
        }

        return 'php';
    }
}
