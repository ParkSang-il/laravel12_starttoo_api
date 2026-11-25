<?php

use App\Http\Controllers\StarttooSafetyController;
use App\Http\Middleware\StarttooDatabase;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/projects/starttoo')->middleware([StarttooDatabase::class])->group(function () {
    Route::get('blocks', [StarttooSafetyController::class, 'index']);
    Route::post('users/{user}/block', [StarttooSafetyController::class, 'block'])->whereNumber('user');
    Route::post('artworks/{artwork}/hide', [StarttooSafetyController::class, 'hide'])->whereUuid('artwork');
    Route::post('reports', [StarttooSafetyController::class, 'report']);
    Route::get('reports', [StarttooSafetyController::class, 'reports']); // 내가 신고한 내역
});
