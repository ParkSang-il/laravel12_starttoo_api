<?php

use App\Http\Controllers\StarttooArtistController;
use App\Http\Controllers\StarttooShopController;
use App\Http\Controllers\StarttooStudioController;
use App\Http\Middleware\StarttooDatabase;
use Illuminate\Support\Facades\Route;

// 2-12·2-13 아티스트 전환 신청
Route::prefix('v1/projects/starttoo/artist')->middleware([StarttooDatabase::class])->group(function () {
    Route::get('application', [StarttooArtistController::class, 'show']);
    Route::post('application', [StarttooArtistController::class, 'submit'])->middleware('throttle:10,1,artist-application');
    Route::post('identity', [StarttooArtistController::class, 'identity'])->middleware('throttle:10,1,artist-identity');
});

// 2-14·2-15·2-16 아티스트 스튜디오
Route::prefix('v1/projects/starttoo/studio')->middleware([StarttooDatabase::class])->group(function () {
    Route::get('/', [StarttooStudioController::class, 'dashboard']);
    Route::post('shop', [StarttooStudioController::class, 'updateShop'])->middleware('throttle:30,1,studio-shop');
    Route::get('address-search', [StarttooStudioController::class, 'addressSearch'])->middleware('throttle:30,1,studio-address');
    Route::get('artworks', [StarttooStudioController::class, 'artworks']);
    Route::get('customers', [StarttooStudioController::class, 'customers'])->middleware('throttle:60,1,studio-customers');
    Route::get('stats', [StarttooStudioController::class, 'stats'])->middleware('throttle:60,1,studio-stats');
    Route::post('artworks/{artwork}', [StarttooStudioController::class, 'updateArtwork'])->whereUuid('artwork')->middleware('throttle:60,1,studio-artwork');
    Route::get('news', [StarttooShopController::class, 'myNews']);
    Route::post('news', [StarttooShopController::class, 'createNews'])->middleware('throttle:20,1,studio-news');
    Route::post('news/{news}/delete', [StarttooShopController::class, 'deleteNews'])->whereNumber('news');
    // 6-05 ~ 6-09 광고(신청 → 운영 콘솔 심사 → 승인되면 노출)
    Route::get('ads', [StarttooStudioController::class, 'ads']);
    Route::get('ads/products', [StarttooStudioController::class, 'adProducts']);
    Route::post('ads', [StarttooStudioController::class, 'createAd'])->middleware('throttle:20,1,studio-ads');
    Route::get('ads/{ad}', [StarttooStudioController::class, 'ad'])->whereNumber('ad');
    Route::post('ads/{ad}', [StarttooStudioController::class, 'editAd'])->whereNumber('ad')->middleware('throttle:20,1,studio-ads');
    Route::post('ads/{ad}/pause', [StarttooStudioController::class, 'pauseAd'])->whereNumber('ad')->middleware('throttle:30,1,studio-ads-state');
    Route::post('ads/{ad}/extend', [StarttooStudioController::class, 'extendAd'])->whereNumber('ad')->middleware('throttle:30,1,studio-ads-state');
    Route::post('ads/{ad}/end', [StarttooStudioController::class, 'endAd'])->whereNumber('ad')->middleware('throttle:30,1,studio-ads-state');
});

// 2-07~2-11 고객용 상점
Route::prefix('v1/projects/starttoo/shops')->middleware([StarttooDatabase::class])->group(function () {
    Route::get('{user}', [StarttooShopController::class, 'show'])->whereNumber('user');
    Route::get('{user}/portfolio', [StarttooShopController::class, 'portfolio'])->whereNumber('user');
    Route::get('{user}/news', [StarttooShopController::class, 'news'])->whereNumber('user');
});
