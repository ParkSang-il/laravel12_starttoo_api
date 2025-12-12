<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminController;

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

// 관리자 라우트
Route::prefix('admin')->group(function () {
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
});
