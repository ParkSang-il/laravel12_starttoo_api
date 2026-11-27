<?php

use App\Http\Controllers\StarttooFeedController;
use App\Http\Middleware\StarttooDatabase;
use Illuminate\Support\Facades\Route;

Route::get('v1/projects/starttoo/feed', [StarttooFeedController::class, 'index'])->middleware([StarttooDatabase::class]);
// 홈 추천용 카드 노출 · 체류 · 열기 기록(여러 개를 모아 한 번에) — docs/feed-ranking.md
Route::post('v1/projects/starttoo/feed/events', [StarttooFeedController::class, 'events'])
    ->middleware([StarttooDatabase::class, 'throttle:30,1,feed-events']);
