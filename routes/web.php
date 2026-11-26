<?php

// Legacy administrative web routes have been retired. Health: GET /up.

use App\Http\Controllers\StarttooShareController;
use Illuminate\Support\Facades\Route;

// STARTTOO 공유 링크: 앱 연결 파일(iOS Universal Links · Android App Links)과 미리보기 페이지.
Route::get('.well-known/apple-app-site-association', [StarttooShareController::class, 'appleAssociation']);
Route::get('apple-app-site-association', [StarttooShareController::class, 'appleAssociation']);
Route::get('.well-known/assetlinks.json', [StarttooShareController::class, 'assetLinks']);
Route::middleware('throttle:120,1,share-page')->group(function () {
    foreach (StarttooShareController::PATHS as $path) {
        Route::get(ltrim($path, '/'), [StarttooShareController::class, 'show']);
    }
});
