<?php

namespace SirZeruks\LibrenmsRouterosBgp\Commands;

use App\Models\Device;
use Illuminate\Console\Command;
use SirZeruks\LibrenmsRouterosBgp\Poller;
use SirZeruks\LibrenmsRouterosBgp\PollRun;
use SirZeruks\LibrenmsRouterosBgp\SettingsStore;

class PollCommand extends Command
{
    protected $signature = 'routeros-bgp:poll
        {device?* : Device ID or hostname (default: every RouterOS device with BGP peers)}
        {--test : Read and print only, do not store anything}
        {--by=cli : Who started this run (manual = the Poll all now button, cli), shown on the settings page}';

    protected $description = 'Read BGP prefix counts from MikroTik RouterOS devices (routeros-bgp plugin)';

    public function handle(SettingsStore $settings, Poller $poller): int
    {
        $test = (bool) $this->option('test');
        $only = (array) $this->argument('device');

        if (! $test && ! $settings->all()['enabled']) {
            $this->info('Collection is switched off in the plugin settings (use --test to try a device anyway).');

            return self::SUCCESS;
        }

        $devices = Poller::candidateDevices();
        if ($only) {
            $devices = $devices->filter(fn (Device $d) => in_array((string) $d->device_id, $only, true) || in_array($d->hostname, $only, true));
            if ($devices->isEmpty()) {
                $this->error('No matching RouterOS device with BGP peers. Is the device ID/hostname right, and has LibreNMS discovered its BGP peers?');

                return self::FAILURE;
            }
        }

        // One full storing run at a time ("Poll all now" or the CLI). The device poll collects per device and needs no lock.
        // A test run stores nothing, so it does not need the lock.
        $lock = null;
        if (! $test) {
            $lock = PollRun::acquire();
            if ($lock === false) {
                $this->info('Another poll is already running; not starting a second one.');

                return self::SUCCESS;
            }
            PollRun::start($devices->count(), (string) $this->option('by'));
        }

        $failures = 0;
        try {
            foreach ($devices as $device) {
                if (! $test && ! $settings->deviceEnabled($device)) {
                    $this->line("<comment>skip</comment> {$device->displayName()} (disabled for this device)");
                    PollRun::advance();

                    continue;
                }

                $r = $poller->poll($device, ! $test);
                $this->line(sprintf('%s %s via %s: %s', $r['ok'] ? '<info>ok</info>  ' : '<error>fail</error>', $device->displayName(), $r['via'], $r['message']));
                if ($this->output->isVerbose() || $test) {
                    foreach ($r['sessions'] as $s) {
                        $this->line(sprintf('      %-40s %-39s prefixes=%-8s %s%s',
                            $s['name'], $s['remote'], $s['prefixes'] ?? '-', $s['established'] ? 'established' : 'down',
                            $s['matched'] ? '' : '  (not in LibreNMS BGP peers)'));
                    }
                }
                $failures += $r['ok'] ? 0 : 1;
                if (! $test) {
                    PollRun::advance(! $r['ok']);
                }
            }
        } finally {
            if (! $test) {
                PollRun::finish();
                PollRun::release($lock);
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
