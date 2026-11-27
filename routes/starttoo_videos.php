<?php

use App\Http\Controllers\StarttooVideoController as C;
use App\Http\Middleware\StarttooDatabase;
use Illuminate\Support\Facades\Route;

// 무빙(동영상) — docs/video.md. 앱 공용 요청 함수가 GET/POST만 써서 수정 · 삭제도 POST.
Route::prefix('v1/projects/starttoo/videos')->middleware([StarttooDatabase::class])->group(function () {
    // 업로드 세션(메타 → 조각별 서명 주소) · 만료된 조각 주소 다시 받기 · 다 올림
    Route::post('/', [C::class, 'create'])->middleware('throttle:20,1,video-create');
    Route::post('{video}/parts', [C::class, 'parts'])->whereUuid('video')->middleware('throttle:60,1,video-parts');
    Route::post('{video}/complete', [C::class, 'complete'])->whereUuid('video')->middleware('throttle:30,1,video-complete');
    // 게시 대기함 · 미니바(pending=1) · 한 개
    Route::get('mine', [C::class, 'mine'])->middleware('throttle:120,1,video-mine');
    // VID-06 무빙 피드 · VID-06B 작가 무빙 이어 보기(?artist=&from=)
    Route::get('feed', [C::class, 'feed'])->middleware('throttle:120,1,video-feed');
    Route::get('{video}', [C::class, 'show'])->whereUuid('video')->middleware('throttle:240,1,video-show');
    Route::post('{video}', [C::class, 'update'])->whereUuid('video');
    Route::post('{video}/publish', [C::class, 'publish'])->whereUuid('video');
    Route::post('{video}/delete', [C::class, 'delete'])->whereUuid('video');
    // 시청 기록(여러 개를 모아 한 번에)
    Route::post('views', [C::class, 'views'])->middleware('throttle:60,1,video-views');
});
