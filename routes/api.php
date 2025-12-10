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
    // 휴대폰 인증 관련 라우트
    Route::prefix('phone')->group(function () {
        Route::post('/send', [AuthController::class, 'sendVerificationCode']);      // 인증번호 발송
        Route::post('/resend', [AuthController::class, 'resendVerificationCode']);  // 인증번호 재전송
        Route::post('/verify', [AuthController::class, 'verifyCode']);               // 인증번호 검증
        Route::post('/check', [AuthController::class, 'checkPhone']);                 // 휴대폰 번호 중복 체크
        Route::post('/expire', [AuthController::class, 'expireVerification']);        // 인증번호 만료 처리 (앱에서 만료 감지 후 호출)
    });

    Route::post('/register', [AuthController::class, 'registerType']);                          // 일반회원가입
    // 휴대폰 번호 로그인 (인증 완료 후)
    Route::post('/login', [AuthController::class, 'login']);                          // 휴대폰 번호로 로그인
});

// 인증이 필요한 라우트
Route::middleware('auth:api')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::post('/biz_additional_info', [AuthController::class, 'bizAdditionalInfo']); // 사업자 추가정보 등록
        Route::put('/biz_additional_info', [AuthController::class, 'updateBizAdditionalInfo']); // 사업자 추가정보 수정
        Route::post('/biz_edit_info', [AuthController::class, 'bizEditInfo']); // 사업자 정보 수정요청(승인된 사업자만 가능)
    });

    // 여기에 추가 API 라우트를 등록하세요
    // 예: 타투 포스트, 팔로우, 좋아요 등
});

