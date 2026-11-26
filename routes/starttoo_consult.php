<?php

use App\Http\Controllers\StarttooConsultationController;
use App\Http\Middleware\StarttooDatabase;
use Illuminate\Support\Facades\Route;

// Phase 3 상담·채팅
Route::prefix('v1/projects/starttoo/consultations')->middleware([StarttooDatabase::class])->group(function () {
    Route::get('/', [StarttooConsultationController::class, 'list']);
    Route::post('/', [StarttooConsultationController::class, 'start'])->middleware('throttle:10,1,consult-start');
    Route::get('cases', [StarttooConsultationController::class, 'cases']);
    Route::get('unread', [StarttooConsultationController::class, 'unread']);
    Route::get('realtime', [StarttooConsultationController::class, 'realtime']);
    Route::post('realtime/auth', [StarttooConsultationController::class, 'realtimeAuth'])->middleware('throttle:60,1,consult-realtime-auth');
    Route::get('availability/{artist}', [StarttooConsultationController::class, 'availability'])->whereNumber('artist');
    Route::get('{consultation}', [StarttooConsultationController::class, 'show'])->whereNumber('consultation');
    Route::get('{consultation}/messages', [StarttooConsultationController::class, 'messages'])->whereNumber('consultation');
    Route::post('{consultation}/messages', [StarttooConsultationController::class, 'send'])->whereNumber('consultation')->middleware('throttle:60,1,consult-send');
    Route::post('{consultation}/quotes', [StarttooConsultationController::class, 'quote'])->whereNumber('consultation')->middleware('throttle:20,1,consult-quote');
    Route::get('{consultation}/quotes/latest', [StarttooConsultationController::class, 'latestQuote'])->whereNumber('consultation');
    Route::get('{consultation}/quotes/{quote}', [StarttooConsultationController::class, 'quoteDetail'])->whereNumber('consultation')->whereNumber('quote');
    Route::post('{consultation}/close', [StarttooConsultationController::class, 'close'])->whereNumber('consultation')->middleware('throttle:20,1,consult-close');
    Route::post('{consultation}/read', [StarttooConsultationController::class, 'read'])->whereNumber('consultation')->middleware('throttle:120,1,consult-read');
});
