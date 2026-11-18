<?php

use App\Http\Controllers\StarttooArtworkController;
use App\Http\Controllers\StarttooPostController;
use App\Http\Middleware\StarttooDatabase;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/projects/starttoo/posts')->middleware([StarttooDatabase::class])->group(function () {
    Route::get('options', [StarttooPostController::class, 'options']);
    // 저장소·크기 검사·EXIF 제거·중복/만료 처리는 기존 사진 파이프라인과 공유한다.
    Route::post('media', [StarttooArtworkController::class, 'upload'])->middleware('throttle:30,1');
    Route::post('/', [StarttooPostController::class, 'store'])->middleware('throttle:30,1');
    Route::post('{post}/edit', [StarttooPostController::class, 'update'])->whereUuid('post')->middleware('throttle:30,1');
    Route::get('{post}', [StarttooPostController::class, 'show'])->whereUuid('post');
});
