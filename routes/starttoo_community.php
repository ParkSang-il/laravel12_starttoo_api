<?php

use App\Http\Controllers\StarttooCommunityController as C;
use App\Http\Middleware\StarttooDatabase;
use Illuminate\Support\Facades\Route;

// Phase 5 커뮤니티(5-04 피드 · 5-05 게시판 · 5-06 상세 · 5-07 글쓰기)
// throttle 세 번째 값(이름)으로 주소마다 따로 센다.
Route::prefix('v1/projects/starttoo/community')->middleware([StarttooDatabase::class])->group(function () {
    Route::get('/', [C::class, 'feed']);
    Route::get('artists', [C::class, 'artists']);
    Route::post('/', [C::class, 'create'])->middleware('throttle:10,1,community-create');
    Route::get('{post}', [C::class, 'show'])->whereNumber('post');
    Route::post('{post}/edit', [C::class, 'update'])->whereNumber('post')->middleware('throttle:20,1,community-edit');
    Route::post('{post}/delete', [C::class, 'delete'])->whereNumber('post');
    Route::post('{post}/like', [C::class, 'like'])->whereNumber('post')->middleware('throttle:60,1,community-like');
    Route::post('{post}/comments', [C::class, 'comment'])->whereNumber('post')->middleware('throttle:20,1,community-comment');
    Route::post('comments/{comment}/delete', [C::class, 'deleteComment'])->whereNumber('comment');
});
