<?php

use App\Http\Controllers\StarttooPublicProfileController;
use App\Http\Middleware\StarttooDatabase;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/projects/starttoo/users')->middleware([StarttooDatabase::class])->group(function () {
    Route::get('{user}', [StarttooPublicProfileController::class, 'show'])->whereNumber('user');
    Route::get('{user}/artworks', [StarttooPublicProfileController::class, 'artworks'])->whereNumber('user');
});
