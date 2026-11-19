<?php

use App\Http\Controllers\StarttooPublicProfileController;
use App\Http\Middleware\StarttooDatabase;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/projects/starttoo/users')->middleware([StarttooDatabase::class])->group(function () {
    Route::get('{user}/follows', [StarttooPublicProfileController::class, 'follows'])->where('user', 'me|[0-9]+');
    Route::post('{user}/follow', [StarttooPublicProfileController::class, 'follow'])->whereNumber('user');
    Route::get('{user}', [StarttooPublicProfileController::class, 'show'])->whereNumber('user');
    Route::get('{user}/artworks', [StarttooPublicProfileController::class, 'artworks'])->whereNumber('user');
    Route::get('{user}/feed', [StarttooPublicProfileController::class, 'feed'])->whereNumber('user');
});
