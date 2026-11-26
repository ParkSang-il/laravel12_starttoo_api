<?php

use App\Http\Controllers\StarttooAppController;
use Illuminate\Support\Facades\Route;

// 앱 시작 시 업데이트 안내(로그인 · DB 필요 없음)
Route::prefix('v1/projects/starttoo/app')->middleware('throttle:60,1,app-version')->group(function () {
    Route::get('version', [StarttooAppController::class, 'version']);
});
