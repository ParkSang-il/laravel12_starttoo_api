<?php

use App\Http\Controllers\StarttooAnnouncementController as C;
use App\Http\Middleware\StarttooDatabase;
use Illuminate\Support\Facades\Route;

// MY 「공지사항」. 등록 · 수정은 서버 명령 starttoo:announce
Route::prefix('v1/projects/starttoo/announcements')->middleware([StarttooDatabase::class, 'throttle:120,1,announcements'])->group(function () {
    Route::get('/', [C::class, 'index']);
    Route::get('{announcement}', [C::class, 'show'])->whereNumber('announcement');
});
