<?php

use App\Http\Controllers\StarttooArtworkController;
use App\Http\Controllers\StarttooPostController;
use App\Http\Controllers\StarttooPostCommentController;
use App\Http\Middleware\StarttooDatabase;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/projects/starttoo/posts')->middleware([StarttooDatabase::class])->group(function () {
    Route::get('options', [StarttooPostController::class, 'options']);
    // 저장소·크기 검사·EXIF 제거·중복/만료 처리는 기존 사진 파이프라인과 공유한다.
    Route::post('media', [StarttooArtworkController::class, 'upload'])->middleware('throttle:30,1');
    Route::post('/', [StarttooPostController::class, 'store'])->middleware('throttle:30,1');
    Route::post('{post}/edit', [StarttooPostController::class, 'update'])->whereUuid('post')->middleware('throttle:30,1');
    Route::post('{post}/interaction', [StarttooPostController::class, 'interaction'])->whereUuid('post')->middleware('throttle:60,1');
    Route::get('{post}/comments', [StarttooPostCommentController::class, 'index'])->whereUuid('post');
    Route::get('{post}/comments/{comment}/replies', [StarttooPostCommentController::class, 'replies'])->whereUuid('post')->whereNumber('comment');
    Route::post('{post}/comments', [StarttooPostCommentController::class, 'store'])->whereUuid('post')->middleware('throttle:starttoo-comment-create');
    Route::post('{post}/comments/{comment}/delete', [StarttooPostCommentController::class, 'delete'])->whereUuid('post')->whereNumber('comment')->middleware('throttle:starttoo-comment-delete');
    Route::post('{post}/comments/{comment}/like', [StarttooPostCommentController::class, 'like'])->whereUuid('post')->whereNumber('comment')->middleware('throttle:starttoo-comment-like');
    Route::get('{post}', [StarttooPostController::class, 'show'])->whereUuid('post');
});
