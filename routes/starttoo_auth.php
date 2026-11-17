<?php

use App\Http\Controllers\StarttooAuthController;
use App\Http\Middleware\StarttooDatabase;
use Illuminate\Support\Facades\Route;

// 고정 경로를 기존 프로젝트 JWT 경로보다 먼저 등록한다.
// STARTTOO 전용 토큰은 이 경로에서만 사용하며 다른 앱의 JWT 인증과 분리한다.
Route::prefix('v1/projects/starttoo/auth')->middleware([StarttooDatabase::class])->group(function () {
    Route::post('google', [StarttooAuthController::class, 'google']);
    Route::post('refresh', [StarttooAuthController::class, 'refresh']);
    Route::get('me', [StarttooAuthController::class, 'me']);
    Route::post('logout', [StarttooAuthController::class, 'logout']);
});
