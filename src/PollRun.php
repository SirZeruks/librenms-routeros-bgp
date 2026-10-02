<?php

namespace SirZeruks\LibrenmsRouterosBgp;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * Bookkeeping for one full polling run ("Poll all now" or CLI): a lock so two runs never overlap,
 * and a progress record the settings page reads to show "12 of 24 done".
 */
final class PollRun
{
    private const LOCK = RouterosBgpProvider::PLUGIN . '.poll-lock';

    private const STATE = RouterosBgpProvider::PLUGIN . '.run';

    /** A run longer than this is treated as dead (lock and "running" both expire). */
    public const MAX_SECONDS = 900;

    /**
     * @return Lock|null|false  the lock, null if the cache store cannot lock (run anyway), false if another run holds it
     */
    public static function acquire(): Lock|null|false
    {
        try {
            $lock = Cache::lock(self::LOCK, self::MAX_SECONDS);
        } catch (\Throwable) {
            return null;
        }

        return $lock->get() ? $lock : false;
    }

    public static function release(Lock|null|false $lock): void
    {
        if ($lock instanceof Lock) {
            try {
                $lock->release();
            } catch (\Throwable) {
                // expires by itself after MAX_SECONDS
            }
        }
    }

    public static function start(int $total, string $by): void
    {
        Cache::put(self::STATE, [
            'by' => in_array($by, ['manual', 'cli'], true) ? $by : 'cli',
            'started' => time(),
            'finished' => null,
            'total' => $total,
            'done' => 0,
            'failed' => 0,
        ], now()->addDays(7));
    }

    public static function advance(bool $failed = false): void
    {
        $s = Cache::get(self::STATE);
        if (is_array($s)) {
            $s['done']++;
            $s['failed'] += $failed ? 1 : 0;
            Cache::put(self::STATE, $s, now()->addDays(7));
        }
    }

    public static function finish(): void
    {
        $s = Cache::get(self::STATE);
        if (is_array($s)) {
            $s['finished'] = time();
            Cache::put(self::STATE, $s, now()->addDays(7));
        }
    }

    /**
     * The latest run, with `running` worked out (a run older than MAX_SECONDS that never finished is not running).
     *
     * @return array<string, mixed>|null
     */
    public static function state(): ?array
    {
        $s = Cache::get(self::STATE);
        if (! is_array($s)) {
            return null;
        }
        $s['running'] = $s['finished'] === null && time() - (int) $s['started'] < self::MAX_SECONDS;

        return $s;
    }

    /**
     * A manual run that never made progress and holds no lock did not start (exec blocked, wrong PHP binary):
     * say so instead of showing "running" for MAX_SECONDS.
     */
    public static function checkStarted(): void
    {
        $s = self::state();
        if (! $s || ! $s['running'] || $s['by'] !== 'manual' || $s['done'] > 0 || time() - (int) $s['started'] < 90) {
            return;
        }
        $lock = self::acquire();
        if ($lock === false) {
            return;   // a run holds the lock: it is working on its first device
        }
        self::release($lock);
        $s['finished'] = time();
        $s['error'] = 'The background poll did not start. Run `lnms routeros-bgp:poll` on the server to see why.';
        unset($s['running']);
        Cache::put(self::STATE, $s, now()->addDays(7));
    }

    /** Mark a manual run as requested, so the page shows it before the background process has started. */
    public static function requested(int $total): void
    {
        self::start($total, 'manual');
    }
}
