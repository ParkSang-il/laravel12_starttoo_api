<?php

use App\Http\Controllers\StarttooArtworkController;
use App\Http\Controllers\StarttooCommentController;
use App\Http\Middleware\StarttooDatabase;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/projects/starttoo/artworks')->middleware([StarttooDatabase::class])->group(function () {
    Route::get('/', [StarttooArtworkController::class, 'index']);
    Route::get('options', [StarttooArtworkController::class, 'options']);
    Route::post('/', [StarttooArtworkController::class, 'store']);
    Route::post('media', [StarttooArtworkController::class, 'upload']);
    Route::get('{artwork}/comments', [StarttooCommentController::class, 'index'])->whereUuid('artwork');
    Route::get('{artwork}/comments/{comment}/replies', [StarttooCommentController::class, 'replies'])->whereUuid('artwork')->whereNumber('comment');
    Route::post('{artwork}/comments', [StarttooCommentController::class, 'store'])->whereUuid('artwork')->middleware('throttle:starttoo-comment-create');
    Route::post('{artwork}/comments/{comment}/delete', [StarttooCommentController::class, 'delete'])->whereUuid('artwork')->whereNumber('comment')->middleware('throttle:starttoo-comment-delete');
    Route::post('{artwork}/comments/{comment}/like', [StarttooCommentController::class, 'like'])->whereUuid('artwork')->whereNumber('comment')->middleware('throttle:starttoo-comment-like');
    Route::post('{artwork}/edit', [StarttooArtworkController::class, 'update'])->whereUuid('artwork');
    Route::post('{artwork}/delete', [StarttooArtworkController::class, 'destroy'])->whereUuid('artwork');
    Route::post('{artwork}/interaction', [StarttooArtworkController::class, 'interaction'])->whereUuid('artwork');
    Route::get('{artwork}', [StarttooArtworkController::class, 'show'])->whereUuid('artwork');
});
