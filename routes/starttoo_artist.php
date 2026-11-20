<?php

use App\Http\Controllers\StarttooArtistController;
use App\Http\Middleware\StarttooDatabase;
use Illuminate\Support\Facades\Route;

// 2-12·2-13 아티스트 전환 신청
Route::prefix('v1/projects/starttoo/artist')->middleware([StarttooDatabase::class])->group(function () {
    Route::get('application', [StarttooArtistController::class, 'show']);
    Route::post('application', [StarttooArtistController::class, 'submit'])->middleware('throttle:10,1');
    Route::post('identity', [StarttooArtistController::class, 'identity'])->middleware('throttle:10,1');
});
