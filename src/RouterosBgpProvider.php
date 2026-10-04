<?php

namespace SirZeruks\LibrenmsRouterosBgp;

use App\Events\DeviceDiscovered;
use App\Events\DevicePolled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use SirZeruks\LibrenmsRouterosBgp\Commands\PollCommand;
use SirZeruks\LibrenmsRouterosBgp\Hooks\DeviceOverview;
use SirZeruks\LibrenmsRouterosBgp\Hooks\Settings;
use SirZeruks\LibrenmsRouterosBgp\Listeners\CollectOnDevicePoll;
use SirZeruks\LibrenmsRouterosBgp\Listeners\RestoreAfterDiscovery;

class RouterosBgpProvider extends ServiceProvider
{
    public const PLUGIN = 'routeros-bgp';

    public const VERSION = '0.3.2';

    public function register(): void
    {
        $this->app->singleton(SettingsStore::class);
    }

    public function boot(PluginManagerInterface $pluginManager): void
    {
        // Hooks must always be published, otherwise LibreNMS removes the plugin from its list.
        $pluginManager->publishHook(self::PLUGIN, SettingsHook::class, Settings::class);
        $pluginManager->publishHook(self::PLUGIN, DeviceOverviewHook::class, DeviceOverview::class);

        if ($this->app->runningInConsole()) {
            $this->commands([PollCommand::class]);
        }

        if (! $pluginManager->pluginEnabled(self::PLUGIN)) {
            return;
        }

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', self::PLUGIN);

        // Collection is part of the normal LibreNMS device poll: LibreNMS fires DevicePolled at the end of each device's
        // poll (in the poller worker), the same hook its own alerting uses. "Poll all now" and the CLI run on demand.
        Event::listen(DevicePolled::class, CollectOnDevicePoll::class);
        // IPv6 peers the plugin added: put back right after LibreNMS's discovery removes them
        Event::listen(DeviceDiscovered::class, RestoreAfterDiscovery::class);
    }
}
