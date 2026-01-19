<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\FollowController;
use App\Http\Controllers\VodController;
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
        Route::post('/send', [AuthController::class, 'sendVerificationCode']);       // 인증번호 발송
        Route::post('/resend', [AuthController::class, 'resendVerificationCode']);   // 인증번호 재전송
        Route::post('/verify', [AuthController::class, 'verifyCode']);               // 인증번호 검증
        Route::post('/check', [AuthController::class, 'checkPhone']);                // 휴대폰 번호 중복 체크
        Route::post('/expire', [AuthController::class, 'expireVerification']);       // 인증번호 만료 처리 (앱에서 만료 감지 후 호출)
    });

    Route::post('/device/register', [AuthController::class, 'registerDevice']);      // 디바이스 등록/업데이트 (인증 선택적: 토큰이 있으면 사용자 정보 포함)
    Route::post('/register', [AuthController::class, 'registerType']);               // 일반회원가입
    // 휴대폰 번호 로그인 (인증 완료 후)
    Route::post('/login', [AuthController::class, 'login']);                         // 휴대폰 번호로 로그인
    Route::post('/refresh', [AuthController::class, 'refresh']);                     // 토큰 리프레시
});

// VOD 콜백 (인증 불필요)
Route::post('/vod/callback', [VodController::class, 'callback']);                    // VOD 인코딩 완료 콜백
Route::post('/vod/test-callback', [VodController::class, 'testCallback']);           // VOD 테스트 콜백 (로그만 저장)

// 인증이 필요한 라우트
Route::middleware(['auth:api'])->group(function () {
    Route::prefix('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::put('/profile', [AuthController::class, 'updateProfile']); // 프로필 수정
        Route::put('/artist-profile', [AuthController::class, 'updateArtistProfile']); // 아티스트 프로필 수정
        Route::get('/artist-profile', [AuthController::class, 'getArtistProfileInfo']); // 아티스트 프로필 정보 조회 (현재 사용자)
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/biz_additional_info', [AuthController::class, 'bizAdditionalInfo']); // 사업자 추가정보 등록
        Route::put('/biz_additional_info', [AuthController::class, 'updateBizAdditionalInfo']); // 사업자 추가정보 수정
        Route::post('/biz_edit_info', [AuthController::class, 'bizEditInfo']); // 사업자 정보 수정요청(승인된 사업자만 가능)
    });

    // 아티스트 프로필 조회 (특정 사용자 ID)
    Route::get('/artists/{userId}/profile', [AuthController::class, 'getArtistProfileInfo']); // 아티스트 프로필 정보 조회 (특정 사용자)

    // 포트폴리오 API
    Route::prefix('portfolios')->group(function () {
        Route::get('/', [PortfolioController::class, 'index']); // 포트폴리오 목록 조회
        Route::post('/', [PortfolioController::class, 'store']); // 포트폴리오 생성
        Route::get('/{id}', [PortfolioController::class, 'show']); // 포트폴리오 상세 조회
        Route::put('/{id}', [PortfolioController::class, 'update']); // 포트폴리오 수정
        Route::delete('/{id}', [PortfolioController::class, 'destroy']); // 포트폴리오 삭제
        Route::post('/{id}/like', [PortfolioController::class, 'like']); // 포트폴리오 좋아요
        Route::delete('/{id}/like', [PortfolioController::class, 'unlike']); // 포트폴리오 좋아요 취소

        // 댓글 API
        Route::get('/{id}/comments', [PortfolioController::class, 'getComments']); // 댓글 목록 조회 (상위 댓글만)
        Route::get('/{id}/comments/{commentId}/replies', [PortfolioController::class, 'getReplies']); // 대댓글 목록 조회
        Route::post('/{id}/comments', [PortfolioController::class, 'createComment']); // 댓글 작성
        Route::put('/{id}/comments/{commentId}', [PortfolioController::class, 'updateComment']); // 댓글 수정
        Route::delete('/{id}/comments/{commentId}', [PortfolioController::class, 'deleteComment']); // 댓글 삭제
        Route::post('/{id}/comments/{commentId}/pin', [PortfolioController::class, 'pinComment']); // 댓글 고정/해제

        // 신고 API
        Route::post('/{id}/report', [PortfolioController::class, 'reportPortfolio']); // 포트폴리오 신고
        Route::post('/{id}/comments/{commentId}/report', [PortfolioController::class, 'reportComment']); // 댓글 신고
    });

    // 팔로우/언팔로우 및 목록
    Route::post('/follows/{id}', [FollowController::class, 'follow']); // 팔로우 생성
    Route::delete('/follows/{id}', [FollowController::class, 'unfollow']); // 언팔로우
    Route::get('/me/followers', [FollowController::class, 'followers']); // 내 팔로워(사업자만)
    Route::get('/me/followings', [FollowController::class, 'followings']); // 내 팔로잉

    //피드(포트폴리오) 리스트
    Route::get('/feed', [PortfolioController::class, 'feed']); // 피드 포트폴리오 목록 조회
});

