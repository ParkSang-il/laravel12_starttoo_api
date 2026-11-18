<?php

use App\Http\Controllers\StarttooArtworkController;
use App\Http\Middleware\StarttooDatabase;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/projects/starttoo/artworks')->middleware([StarttooDatabase::class])->group(function () {
    Route::get('/', [StarttooArtworkController::class, 'index']);
    Route::get('options', [StarttooArtworkController::class, 'options']);
    Route::post('/', [StarttooArtworkController::class, 'store']);
    Route::post('media', [StarttooArtworkController::class, 'upload']);
    Route::post('{artwork}/edit', [StarttooArtworkController::class, 'update'])->whereUuid('artwork');
    Route::post('{artwork}/delete', [StarttooArtworkController::class, 'destroy'])->whereUuid('artwork');
    Route::post('{artwork}/interaction', [StarttooArtworkController::class, 'interaction'])->whereUuid('artwork');
    Route::get('{artwork}', [StarttooArtworkController::class, 'show'])->whereUuid('artwork');
});
