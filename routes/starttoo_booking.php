<?php

use App\Http\Controllers\StarttooBookingController as C;
use App\Http\Middleware\StarttooDatabase;
use Illuminate\Support\Facades\Route;

// Phase 4 예약
Route::prefix('v1/projects/starttoo/bookings')->middleware([StarttooDatabase::class])->group(function () {
    Route::get('/', [C::class, 'list']);
    Route::post('/', [C::class, 'request'])->middleware('throttle:20,1');
    Route::get('next', [C::class, 'next']);
    Route::get('availability/{artist}', [C::class, 'availability'])->whereNumber('artist');
    Route::get('room/{consultation}', [C::class, 'room'])->whereNumber('consultation');
    // 아티스트 스튜디오
    Route::get('calendar', [C::class, 'calendar']);
    Route::get('calendar/day', [C::class, 'day']);
    Route::get('settings', [C::class, 'settings']);
    Route::post('settings', [C::class, 'updateSettings'])->middleware('throttle:30,1');
    Route::post('settings/closure', [C::class, 'closure'])->middleware('throttle:60,1');
    Route::get('{booking}', [C::class, 'show'])->whereNumber('booking');
    foreach (['change', 'propose', 'cancel', 'confirm', 'reject', 'complete'] as $action) {
        Route::post("{booking}/$action", [C::class, $action])->whereNumber('booking')->middleware('throttle:30,1');
    }
    Route::post('{booking}/change/accept', [C::class, 'acceptChange'])->whereNumber('booking')->middleware('throttle:30,1');
    Route::post('{booking}/change/reject', [C::class, 'rejectChange'])->whereNumber('booking')->middleware('throttle:30,1');
});
