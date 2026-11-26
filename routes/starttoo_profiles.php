<?php

use App\Http\Controllers\StarttooCollectionsController;
use App\Http\Controllers\StarttooPublicProfileController;
use App\Http\Middleware\StarttooDatabase;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/projects/starttoo/users')->middleware([StarttooDatabase::class])->group(function () {
    Route::get('{user}/follows', [StarttooPublicProfileController::class, 'follows'])->where('user', 'me|[0-9]+');
    Route::post('{user}/follow', [StarttooPublicProfileController::class, 'follow'])->whereNumber('user');
    Route::get('{user}', [StarttooPublicProfileController::class, 'show'])->whereNumber('user');
    Route::get('{user}/artworks', [StarttooPublicProfileController::class, 'artworks'])->whereNumber('user');
    Route::get('{user}/feed', [StarttooPublicProfileController::class, 'feed'])->whereNumber('user');
    Route::get('{user}/saved', [StarttooPublicProfileController::class, 'saved'])->whereNumber('user');
    // 1-14 저장 컬렉션
    Route::get('{user}/collections', [StarttooCollectionsController::class, 'index'])->where('user', 'me|[0-9]+');
    Route::post('me/collections', [StarttooCollectionsController::class, 'store'])->middleware('throttle:30,1,collection-create');
    Route::post('me/collections/{collection}', [StarttooCollectionsController::class, 'update'])->whereNumber('collection');
    Route::post('me/collections/{collection}/delete', [StarttooCollectionsController::class, 'destroy'])->whereNumber('collection');
    Route::post('me/collections/{collection}/items', [StarttooCollectionsController::class, 'item'])->whereNumber('collection');
});
