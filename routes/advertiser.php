<?php

use App\Http\Controllers\Advertiser\AdvertiserAccountController as A;
use App\Http\Controllers\Advertiser\AdvertiserWebController as W;
use Illuminate\Support\Facades\Route;

/*
| 외부 광고주 웹 (디자인 AW-01 ~ AW-03, docs/ads.md 「외부 광고」)
| bootstrap/app.php 에서 'web' 미들웨어(세션 · CSRF)로 등록한다. advertiser.web 이 스타투 DB 를 고른다.
|   공개      : 상품 소개 · 광고 문의 · 로그인 · 비밀번호 찾기
|   비밀 링크 : 견적서(q) · 성과 보고(r) · 비밀번호 설정 — 48자 토큰, 분당 60회 제한
|   로그인    : 내 광고(견적 · 계약 · 문의 목록) · 내 정보 · 비밀번호 변경
*/

$token = '[A-Za-z0-9]{48}';

Route::prefix('ads')->name('advertiser.')->group(function () use ($token) {
    Route::middleware('advertiser.web')->group(function () {
        Route::get('/', fn () => redirect()->route('advertiser.products'));
        Route::get('products', [W::class, 'products'])->name('products');
        // AW-01 공개 문의
        Route::get('inquiry', [W::class, 'inquiry'])->name('inquiry');
        Route::post('inquiry', [W::class, 'storeInquiry'])->middleware('throttle:5,10,ad-inquiry')->name('inquiry.store');
        Route::get('inquiry/done/{code}', [W::class, 'inquiryDone'])->where('code', 'AD-\d{8}-\d{3,}')->name('inquiry.done');

        Route::get('login', [A::class, 'login'])->name('login');
        Route::post('login', [A::class, 'authenticate'])->middleware('throttle:10,10,ad-login')->name('login.submit');
        Route::get('password/forgot', [A::class, 'forgot'])->name('password.forgot');
        Route::post('password/forgot', [A::class, 'sendLink'])->middleware('throttle:5,10,ad-password')->name('password.send');
    });

    // AW-02 견적서 · AW-03 성과 보고 · 비밀번호 설정 — 비밀 토큰
    Route::middleware(['advertiser.web:private', 'throttle:60,1,ad-token'])->group(function () use ($token) {
        // 견적서 · 성과 보고는 계약 주인 광고주로 로그인해야 열린다(계정이 없으면 링크에서 비밀번호를 만든다)
        Route::get('q/{token}', [W::class, 'quote'])->where('token', $token)->name('quote');
        Route::post('q/{token}/accept', [W::class, 'acceptQuote'])->where('token', $token)->name('quote.accept');
        Route::post('q/{token}/change', [W::class, 'requestChange'])->where('token', $token)->name('quote.change');
        Route::post('q/{token}/paid', [W::class, 'reportPayment'])->where('token', $token)->name('quote.paid');
        Route::post('q/{token}/account', [A::class, 'createFromQuote'])->where('token', $token)->name('quote.account');
        Route::get('r/{token}', [W::class, 'report'])->where('token', $token)->name('report');
        Route::post('r/{token}/account', [A::class, 'createFromReport'])->where('token', $token)->name('report.account');
        Route::get('password/reset/{token}', [A::class, 'reset'])->where('token', $token)->name('password.reset');
        Route::post('password/reset/{token}', [A::class, 'saveReset'])->where('token', $token)->name('password.save');
    });

    Route::middleware('advertiser.web:auth')->group(function () {
        Route::get('account', [A::class, 'home'])->name('account');
        Route::post('account/profile', [A::class, 'saveProfile'])->name('account.profile');
        Route::post('account/password', [A::class, 'changePassword'])->middleware('throttle:10,10,ad-password-change')->name('account.password');
        Route::post('logout', [A::class, 'logout'])->name('logout');
    });
});
