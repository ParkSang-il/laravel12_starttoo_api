<?php

use App\Http\Controllers\StarttooReviewController as C;
use App\Http\Middleware\StarttooDatabase;
use Illuminate\Support\Facades\Route;

// Phase 5 인증 리뷰(5-01 상점 리뷰 목록 · 5-02 작성 · 5-03 상세)
// throttle 세 번째 값(이름)으로 주소마다 따로 센다.
Route::prefix('v1/projects/starttoo/reviews')->middleware([StarttooDatabase::class])->group(function () {
    Route::get('shop/{user}', [C::class, 'shop'])->whereNumber('user');
    Route::get('mine', [C::class, 'mine']);
    Route::get('form', [C::class, 'form']);
    Route::post('/', [C::class, 'create'])->middleware('throttle:10,1,review-create');
    Route::get('{review}', [C::class, 'show'])->whereNumber('review');
    Route::post('{review}/edit', [C::class, 'update'])->whereNumber('review')->middleware('throttle:20,1,review-edit');
    Route::post('{review}/delete', [C::class, 'delete'])->whereNumber('review');
    Route::post('{review}/reply', [C::class, 'reply'])->whereNumber('review')->middleware('throttle:20,1,review-reply');
    Route::post('{review}/helpful', [C::class, 'helpful'])->whereNumber('review')->middleware('throttle:60,1,review-helpful');
});
