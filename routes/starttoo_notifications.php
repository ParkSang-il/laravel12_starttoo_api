<?php

use App\Http\Controllers\StarttooNotificationsController;
use App\Http\Middleware\StarttooDatabase;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/projects/starttoo/me')->middleware([StarttooDatabase::class])->group(function () {
    Route::post('push-campaigns/{campaign}/open', [\App\Http\Controllers\StarttooCampaignController::class, 'open'])->whereUuid('campaign');
    Route::post('push-device', [\App\Http\Controllers\StarttooPushController::class, 'store']);
    Route::get('notifications', [StarttooNotificationsController::class, 'handle'])->name('starttoo.notifications');
    Route::get('notifications/unread', [StarttooNotificationsController::class, 'handle'])->name('starttoo.notifications.unread');
    Route::post('notifications/{notification}/read', [StarttooNotificationsController::class, 'handle'])->whereNumber('notification')->name('starttoo.notifications.read');
    Route::post('notifications/{notification}/open', [StarttooNotificationsController::class, 'handle'])->whereNumber('notification')->name('starttoo.notifications.open');
    Route::get('preferences', [StarttooNotificationsController::class, 'handle'])->name('starttoo.preferences.get');
    Route::post('preferences', [StarttooNotificationsController::class, 'handle'])->name('starttoo.preferences.set');
});
