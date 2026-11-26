<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminController;
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

        Route::get('settings', [AdminController::class, 'settings'])->name('settings');
        Route::get('settings/logs/export', [AdminController::class, 'exportLogs'])->name('settings.logs.export');
        Route::post('settings/policies/{id}', [AdminController::class, 'togglePolicy'])->name('settings.policy');
    });
});
