<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminAuthController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

// 관리자 인증 라우트 (인증 불필요)
Route::prefix('admin')->group(function () {
    // 로그인 페이지
    Route::get('/login', [AdminAuthController::class, 'showLoginForm'])->name('admin.login');
    Route::post('/login', [AdminAuthController::class, 'login'])->name('admin.auth.login');
    Route::post('/logout', [AdminAuthController::class, 'logout'])->name('admin.auth.logout');
});

// 관리자 라우트 (인증 필요)
Route::prefix('admin')->middleware('admin.auth')->group(function () {
    // 대시보드 (임시로 포트폴리오 관리로 리다이렉트)
    Route::get('/', function () {
        return redirect()->route('admin.portfolio.index');
    })->name('admin.dashboard');
    
    // 관리자 계정 추가 (최고 관리자만)
    Route::get('/auth/create', [AdminAuthController::class, 'showCreateForm'])->name('admin.auth.create');
    Route::post('/auth/create', [AdminAuthController::class, 'create'])->name('admin.auth.create');
    
    // 사업자 가입신청 관리 페이지
    Route::get('/business-verifications', [AdminController::class, 'businessVerificationIndex'])->name('admin.business-verification.index');
    
    // 사업자 가입신청 API
    Route::get('/api/business-verifications', [AdminController::class, 'getBusinessVerificationList'])->name('admin.business-verification.list');
    Route::post('/api/business-verifications/{id}/approve', [AdminController::class, 'approveBusinessVerification'])->name('admin.business-verification.approve');
    Route::post('/api/business-verifications/{id}/reject', [AdminController::class, 'rejectBusinessVerification'])->name('admin.business-verification.reject');
    
    // 사업자 정보 수정요청 관리 페이지
    Route::get('/business-edit-requests', [AdminController::class, 'businessEditRequestIndex'])->name('admin.business-edit-request.index');
    
    // 사업자 정보 수정요청 API
    Route::get('/api/business-edit-requests', [AdminController::class, 'getBusinessEditRequestList'])->name('admin.business-edit-request.list');
    Route::post('/api/business-edit-requests/{id}/approve', [AdminController::class, 'approveBusinessEditRequest'])->name('admin.business-edit-request.approve');
    Route::post('/api/business-edit-requests/{id}/reject', [AdminController::class, 'rejectBusinessEditRequest'])->name('admin.business-edit-request.reject');
    
    // 포트폴리오 관리 페이지
    Route::get('/portfolios', [AdminController::class, 'portfolioIndex'])->name('admin.portfolio.index');
    
    // 포트폴리오 관리 API
    Route::get('/api/portfolios', [AdminController::class, 'getPortfolioList'])->name('admin.portfolio.list');
    Route::get('/api/portfolios/{id}', [AdminController::class, 'getPortfolioDetail'])->name('admin.portfolio.detail');
    Route::put('/api/portfolios/{id}', [AdminController::class, 'updatePortfolio'])->name('admin.portfolio.update');
    Route::delete('/api/portfolios/{id}', [AdminController::class, 'deletePortfolio'])->name('admin.portfolio.delete');
    Route::post('/api/portfolios/{id}/toggle-sensitive', [AdminController::class, 'toggleSensitive'])->name('admin.portfolio.toggle-sensitive');
    
    // 댓글 관리 페이지
    Route::get('/comments', [AdminController::class, 'commentIndex'])->name('admin.comment.index');
    
    // 댓글 관리 API
    Route::get('/api/comments', [AdminController::class, 'getCommentList'])->name('admin.comment.list');
    Route::get('/api/comments/{id}', [AdminController::class, 'getCommentDetail'])->name('admin.comment.detail');
    Route::put('/api/comments/{id}', [AdminController::class, 'updateComment'])->name('admin.comment.update');
    Route::delete('/api/comments/{id}', [AdminController::class, 'deleteComment'])->name('admin.comment.delete');
    Route::post('/api/comments/{id}/restore', [AdminController::class, 'restoreComment'])->name('admin.comment.restore');
    
    // 신고 카운트 API
    Route::get('/api/report-counts', [AdminController::class, 'getReportCounts'])->name('admin.report.counts');
    
    // 신고 리스트 API
    Route::get('/api/portfolios/{id}/reports', [AdminController::class, 'getPortfolioReports'])->name('admin.portfolio.reports');
    Route::get('/api/comments/{id}/reports', [AdminController::class, 'getCommentReports'])->name('admin.comment.reports');
    
    // 신고 상태 변경 API
    Route::put('/api/portfolio-reports/{id}/status', [AdminController::class, 'updatePortfolioReportStatus'])->name('admin.portfolio-report.update-status');
    Route::put('/api/comment-reports/{id}/status', [AdminController::class, 'updateCommentReportStatus'])->name('admin.comment-report.update-status');
    
    // 회원 관리 페이지
    Route::get('/users', [AdminController::class, 'userIndex'])->name('admin.user.index');

    // 관리자 로그인 로그 페이지
    Route::get('/admin-login-logs', [AdminController::class, 'adminLoginLogIndex'])->name('admin.login-log.index');
    
    // 회원 관리 API
    Route::get('/api/users', [AdminController::class, 'getUserList'])->name('admin.user.list');
    Route::get('/api/users/{id}', [AdminController::class, 'getUserDetail'])->name('admin.user.detail');
    Route::post('/api/users/{id}/suspend', [AdminController::class, 'suspendUser'])->name('admin.user.suspend');
    Route::post('/api/users/{id}/unsuspend', [AdminController::class, 'unsuspendUser'])->name('admin.user.unsuspend');
    Route::get('/api/users/{id}/login-logs', [AdminController::class, 'getUserLoginLogs'])->name('admin.user.login-logs');
    Route::get('/api/users/{id}/business-verification', [AdminController::class, 'getUserBusinessVerification'])->name('admin.user.business-verification');

    // 관리자 로그인 로그 API (필터: 아이디, IP, 일자)
    Route::get('/api/admin/login-logs', [AdminController::class, 'getAdminLoginLogs'])->name('admin.login-logs');
});
