<?php

use App\Http\Controllers\StarttooAdController as C;
use App\Http\Middleware\StarttooDatabase;
use Illuminate\Support\Facades\Route;

// 자체 광고(커뮤니티 피드 맨 위 카드 등). 등록 · 끄기는 서버 명령 starttoo:ad
Route::prefix('v1/projects/starttoo/ads')->middleware([StarttooDatabase::class])->group(function () {
    Route::get('/', [C::class, 'show'])->middleware('throttle:120,1,ads-show');
    Route::post('{ad}/click', [C::class, 'click'])->whereNumber('ad')->middleware('throttle:30,1,ads-click');
});
