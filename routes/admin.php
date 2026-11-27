<?php

use App\Http\Controllers\Admin\AdminAdSalesController;
use App\Http\Controllers\Admin\AdminAiController;
use App\Http\Controllers\Admin\AdminAppVersionController;
use App\Http\Controllers\Admin\AdminArtistAdsController;
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminCommsController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AdminInquiryController;
use App\Http\Controllers\Admin\AdminModerationController;
use Illuminate\Support\Facades\Route;

/*
| STARTTOO 운영 콘솔 (docs/admin.md, 디자인 M-01 ~ M-09)
| bootstrap/app.php 에서 'web' 미들웨어(세션 · CSRF)로 등록한다.
| admin.console      : 접속 IP 제한 · 스타투 DB · 검색엔진 차단 헤더
| admin.console:auth : + 관리자 로그인 확인(앱 회원 로그인과 완전히 분리)
| 처리 권한은 컨트롤러에서 역할별로 확인한다(AdminAccounts::ABILITIES).
*/

Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('admin.console')->group(function () {
        Route::get('login', [AdminAuthController::class, 'login'])->name('login');
        Route::post('login', [AdminAuthController::class, 'authenticate'])->name('login.submit')->middleware('throttle:5,30,admin-login');
    });

    Route::middleware('admin.console:auth')->group(function () {
        Route::post('logout', [AdminAuthController::class, 'logout'])->name('logout');

        Route::get('/', fn () => redirect()->route('admin.dashboard'));
        Route::get('dashboard', [AdminController::class, 'dashboard'])->name('dashboard');
        Route::get('members', [AdminController::class, 'members'])->name('members');
        Route::get('members/export', [AdminController::class, 'exportMembers'])->name('members.export');

        Route::get('artists', [AdminModerationController::class, 'artistReview'])->name('artist-review');
        Route::post('artists/{id}/decide', [AdminModerationController::class, 'decideArtist'])->whereNumber('id')->name('artist-review.decide');

        Route::get('contents', [AdminModerationController::class, 'contents'])->name('contents');
        Route::post('contents/bulk', [AdminModerationController::class, 'bulkContents'])->name('contents.bulk');

        Route::get('reports', [AdminModerationController::class, 'reports'])->name('reports');
        Route::post('reports/{id}/resolve', [AdminModerationController::class, 'resolveReport'])->whereNumber('id')->name('reports.resolve');

        Route::get('payments', [AdminController::class, 'payments'])->name('payments');
        Route::get('payments/export', [AdminController::class, 'exportPayments'])->name('payments.export');

        Route::get('ads', [AdminController::class, 'ads'])->name('ads');
        Route::post('ads/{id}/toggle', [AdminController::class, 'toggleAd'])->whereNumber('id')->name('ads.toggle');
        Route::post('ads', [AdminController::class, 'storeAd'])->name('ads.store');
        Route::post('ads/admob', [AdminController::class, 'saveAdmob'])->name('ads.admob');
        // 작가가 앱에서 신청한 광고: 심사(승인 · 반려) · 상품 설정
        Route::post('ads/artist/products', [AdminArtistAdsController::class, 'products'])->name('ads.artist.products');
        Route::post('ads/artist/guide', [AdminArtistAdsController::class, 'guide'])->name('ads.artist.guide');
        Route::post('ads/artist/policy', [AdminArtistAdsController::class, 'policy'])->name('ads.artist.policy');
        Route::post('ads/artist/{id}/approve', [AdminArtistAdsController::class, 'approve'])->whereNumber('id')->name('ads.artist.approve');
        Route::post('ads/artist/{id}/reject', [AdminArtistAdsController::class, 'reject'])->whereNumber('id')->name('ads.artist.reject');
        Route::post('ads/artist/{id}', [AdminController::class, 'updateArtistAd'])->whereNumber('id')->name('ads.artist.update');
        Route::post('ads/artist/{id}/suspend', [AdminController::class, 'suspendArtistAd'])->whereNumber('id')->name('ads.artist.suspend');
        Route::post('ads/artist/{id}/resume', [AdminController::class, 'resumeArtistAd'])->whereNumber('id')->name('ads.artist.resume');
        Route::post('ads/{id}', [AdminController::class, 'updateAd'])->whereNumber('id')->name('ads.update');

        // 외부 광고 영업: 광고주 웹(/ads) 문의 → 견적(CPM) → 입금 확인 → 광고 만들기 → 성과 보고
        Route::get('ad-sales', [AdminAdSalesController::class, 'index'])->name('ad-sales');
        Route::post('ad-sales/quote', [AdminAdSalesController::class, 'quote'])->name('ad-sales.quote.new');
        Route::post('ad-sales/rates', [AdminAdSalesController::class, 'rates'])->name('ad-sales.rates');
        Route::post('ad-sales/inquiries/{id}/quote', [AdminAdSalesController::class, 'quote'])->whereNumber('id')->name('ad-sales.quote');
        Route::post('ad-sales/inquiries/{id}/reject', [AdminAdSalesController::class, 'reject'])->whereNumber('id')->name('ad-sales.reject');
        Route::post('ad-sales/inquiries/{id}/close', [AdminAdSalesController::class, 'close'])->whereNumber('id')->name('ad-sales.close');
        Route::get('ad-sales/inquiries/{id}/files/{n}', [AdminAdSalesController::class, 'file'])->whereNumber(['id', 'n'])->name('ad-sales.file');
        Route::post('ad-sales/contracts/{id}/resend', [AdminAdSalesController::class, 'resend'])->whereNumber('id')->name('ad-sales.resend');
        Route::post('ad-sales/contracts/{id}/paid', [AdminAdSalesController::class, 'paid'])->whereNumber('id')->name('ad-sales.paid');
        Route::post('ad-sales/contracts/{id}/tax', [AdminAdSalesController::class, 'tax'])->whereNumber('id')->name('ad-sales.tax');
        Route::post('ad-sales/contracts/{id}/rotate/{kind}', [AdminAdSalesController::class, 'rotate'])->whereNumber('id')->name('ad-sales.rotate');
        Route::post('ad-sales/contracts/{id}/report-mail', [AdminAdSalesController::class, 'reportMail'])->whereNumber('id')->name('ad-sales.report-mail');
        Route::post('ad-sales/contracts/{id}/ads', [AdminAdSalesController::class, 'createAd'])->whereNumber('id')->name('ad-sales.ad');
        Route::post('ad-sales/contracts/{id}/end', [AdminAdSalesController::class, 'end'])->whereNumber('id')->name('ad-sales.end');
        Route::post('ad-sales/contracts/{id}/cancel', [AdminAdSalesController::class, 'cancel'])->whereNumber('id')->name('ad-sales.cancel');
        Route::post('ad-sales/contracts/{id}/memo', [AdminAdSalesController::class, 'memo'])->whereNumber('id')->name('ad-sales.memo');
        Route::get('ad-sales/contracts/{id}/cert', [AdminAdSalesController::class, 'cert'])->whereNumber('id')->name('ad-sales.cert');
        Route::post('ad-sales/advertisers/{id}/link', [AdminAdSalesController::class, 'advertiserLink'])->whereNumber('id')->name('ad-sales.advertiser.link');

        Route::get('settings', [AdminController::class, 'settings'])->name('settings');
        Route::get('settings/logs/export', [AdminController::class, 'exportLogs'])->name('settings.logs.export');
        Route::post('settings/policies/{id}', [AdminController::class, 'togglePolicy'])->name('settings.policy');

        // M-10 ~ M-13 AI 서비스
        Route::get('ai/usage', [AdminAiController::class, 'usage'])->name('ai-usage');
        Route::post('ai/usage/limit/{key}/{dir}', [AdminAiController::class, 'stepLimit'])->name('ai-usage.limit');
        Route::post('ai/usage/guard/{id}', [AdminAiController::class, 'toggleGuard'])->name('ai-usage.guard');
        Route::get('ai/jobs', [AdminAiController::class, 'jobs'])->name('ai-jobs');
        Route::post('ai/jobs/{id}/restore', [AdminAiController::class, 'restoreQuota'])->name('ai-jobs.restore');
        Route::get('virtual-models', [AdminAiController::class, 'virtualModels'])->name('virtual-models');
        Route::post('virtual-models/{id}/toggle', [AdminAiController::class, 'toggleModel'])->whereNumber('id')->name('virtual-models.toggle');
        Route::post('virtual-models/{id}/redo', [AdminAiController::class, 'regenerateModel'])->whereNumber('id')->name('virtual-models.redo');

        // M-14 상담 · 채팅 모니터링(신고된 방만 · 사유 기록 후 열람)
        Route::get('chats', [AdminCommsController::class, 'chats'])->name('chats');
        Route::post('chats/{id}/open', [AdminCommsController::class, 'openChat'])->whereNumber('id')->name('chats.open');
        Route::post('chats/{id}/warn', [AdminCommsController::class, 'warnChat'])->whereNumber('id')->name('chats.warn');
        // M-15 1:1 문의(답변하면 회원에게 앱 알림)
        Route::get('inquiries', [AdminInquiryController::class, 'index'])->name('inquiries');
        Route::post('inquiries/{id}/answer', [AdminInquiryController::class, 'answer'])->whereNumber('id')->name('inquiries.answer');

        // M-16 · M-17 소통
        Route::get('push', [AdminCommsController::class, 'push'])->name('push');
        Route::post('push/send', [AdminCommsController::class, 'sendPush'])->middleware('throttle:10,1,admin-push')->name('push.send');
        Route::post('push/{id}/cancel', [AdminCommsController::class, 'cancelPush'])->whereUuid('id')->name('push.cancel');
        Route::get('notices', [AdminCommsController::class, 'notices'])->name('notices');
        Route::post('notices', [AdminCommsController::class, 'storeNotice'])->name('notices.store');
        Route::post('notices/{id}', [AdminCommsController::class, 'saveNotice'])->whereNumber('id')->name('notices.save');
        Route::post('notices/{id}/toggle', [AdminCommsController::class, 'toggleNotice'])->whereNumber('id')->name('notices.toggle');

        // M-19 앱 버전
        Route::get('app-version', [AdminAppVersionController::class, 'show'])->name('app-version');
        Route::post('app-version/{pf}/step/{key}/{dir}', [AdminAppVersionController::class, 'step'])->name('app-version.step');
        Route::post('app-version/{pf}/option/{id}', [AdminAppVersionController::class, 'option'])->name('app-version.option');
        Route::post('app-version/{pf}/save', [AdminAppVersionController::class, 'save'])->name('app-version.save');
    });
});
