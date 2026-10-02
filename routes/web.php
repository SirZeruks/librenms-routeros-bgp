<?php

use Illuminate\Support\Facades\Route;
use SirZeruks\LibrenmsRouterosBgp\Http\SettingsController;

Route::middleware(['web', 'auth', 'can:plugin.admin'])
    ->prefix('plugin/routeros-bgp')
    ->name('routeros-bgp.')
    ->group(function (): void {
        Route::post('defaults', [SettingsController::class, 'saveDefaults'])->name('defaults');
        Route::post('device', [SettingsController::class, 'saveDevice'])->name('device.save');
        Route::post('device/{deviceId}/delete', [SettingsController::class, 'deleteDevice'])->whereNumber('deviceId')->name('device.delete');
        Route::post('run/{device}', [SettingsController::class, 'run'])->name('run');
        Route::post('poll-all', [SettingsController::class, 'pollAll'])->name('poll-all');
        Route::get('run-status', [SettingsController::class, 'runStatus'])->name('run-status');
        Route::get('update/check', [SettingsController::class, 'updateCheck'])->name('update.check');
        Route::post('update/start', [SettingsController::class, 'updateStart'])->name('update.start');
        Route::get('update/status', [SettingsController::class, 'updateStatus'])->name('update.status');
    });
