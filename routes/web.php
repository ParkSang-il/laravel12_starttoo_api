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
});
