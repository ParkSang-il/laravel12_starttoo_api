<?php

use App\Http\Controllers\StarttooAuthController;
use App\Http\Controllers\StarttooTermsController;
use App\Http\Controllers\StarttooProfileController;
use App\Http\Middleware\StarttooDatabase;
use Illuminate\Support\Facades\Route;

// 고정 경로를 기존 프로젝트 JWT 경로보다 먼저 등록한다.
// STARTTOO 전용 토큰은 이 경로에서만 사용하며 다른 앱의 JWT 인증과 분리한다.
Route::prefix('v1/projects/starttoo/auth')->middleware([StarttooDatabase::class])->group(function () {
    Route::post('google', [StarttooAuthController::class, 'google']);
    Route::post('kakao', [StarttooAuthController::class, 'kakao'])->middleware('throttle:20,1,auth-kakao');
    Route::post('naver', [StarttooAuthController::class, 'naver'])->middleware('throttle:20,1,auth-naver');
    Route::post('apple', [StarttooAuthController::class, 'apple'])->middleware('throttle:20,1,auth-apple');
    Route::post('refresh', [StarttooAuthController::class, 'refresh']);
    Route::get('me', [StarttooAuthController::class, 'me']);
    Route::get('terms', [StarttooTermsController::class, 'index']);
    Route::post('terms', [StarttooTermsController::class, 'store']);
    Route::get('profile', [StarttooProfileController::class, 'show']);
    Route::post('profile', [StarttooProfileController::class, 'store']);
    Route::post('logout', [StarttooAuthController::class, 'logout']);
    Route::post('withdraw', [StarttooAuthController::class, 'withdraw'])->middleware('throttle:5,1,auth-withdraw');
});
