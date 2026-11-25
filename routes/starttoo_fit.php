<?php

use App\Http\Controllers\StarttooFitController as C;
use App\Http\Middleware\StarttooDatabase;
use Illuminate\Support\Facades\Route;

// 가상 타투 피팅(FIT-01~08)
Route::prefix('v1/projects/starttoo/fit')->middleware([StarttooDatabase::class])->group(function () {
    Route::get('/', [C::class, 'home']);
    Route::get('models', [C::class, 'models'])->middleware('throttle:60,1');
    Route::get('stencils', [C::class, 'stencils']);
    Route::get('results', [C::class, 'list']);
    Route::post('results', [C::class, 'create'])->middleware('throttle:10,1');
    Route::get('results/{result}', [C::class, 'show'])->whereUuid('result')->middleware('throttle:120,1');
    // 앱 공용 요청 함수가 GET/POST만 써서 삭제도 POST
    Route::post('results/{result}/delete', [C::class, 'delete'])->whereUuid('result');
});
