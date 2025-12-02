<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API 라우트
|--------------------------------------------------------------------------
|
| 여기에 애플리케이션의 API 라우트를 등록할 수 있습니다. 이 라우트들은
| RouteServiceProvider에 의해 로드되며 모두 "api" 미들웨어 그룹에 할당됩니다.
|
*/

// 인증 관련 라우트 (인증 불필요)
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    
    // 소셜 로그인 라우트
    Route::prefix('social')->group(function () {
        // 구글
        Route::get('/google', [\App\Http\Controllers\SocialAuthController::class, 'redirectToGoogle']);
        Route::get('/google/callback', [\App\Http\Controllers\SocialAuthController::class, 'handleGoogleCallback']);
        
        // 카카오
        Route::get('/kakao', [\App\Http\Controllers\SocialAuthController::class, 'redirectToKakao']);
        Route::get('/kakao/callback', [\App\Http\Controllers\SocialAuthController::class, 'handleKakaoCallback']);
        
        // 인스타그램
        Route::get('/instagram', [\App\Http\Controllers\SocialAuthController::class, 'redirectToInstagram']);
        Route::get('/instagram/callback', [\App\Http\Controllers\SocialAuthController::class, 'handleInstagramCallback']);
    });
});

// 인증이 필요한 라우트
Route::middleware('auth:api')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
    });

    // 여기에 추가 API 라우트를 등록하세요
    // 예: 타투 포스트, 팔로우, 좋아요 등
});

