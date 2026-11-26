<?php

use App\Http\Controllers\StarttooDiscoverController;
use App\Http\Middleware\StarttooDatabase;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/projects/starttoo/discover')->middleware([StarttooDatabase::class, 'throttle:120,1,discover'])->group(function () {
    Route::get('options', [StarttooDiscoverController::class, 'options']);
    Route::get('explore', [StarttooDiscoverController::class, 'explore']);
    Route::get('popular', [StarttooDiscoverController::class, 'popular']);
    Route::get('/', [StarttooDiscoverController::class, 'search']);
});
