<?php

use App\Http\Controllers\StarttooFitController as C;
use App\Http\Middleware\StarttooDatabase;
use Illuminate\Support\Facades\Route;

// 가상 타투 피팅(FIT-01~08)
// throttle 의 세 번째 값(이름)을 주지 않으면 같은 IP의 모든 주소가 한 통에 세어져,
// 조회가 많은 피팅 흐름에서 합성 요청(분당 10)이 먼저 막힌다. 주소마다 이름을 달아 따로 센다.
Route::prefix('v1/projects/starttoo/fit')->middleware([StarttooDatabase::class])->group(function () {
    Route::get('/', [C::class, 'home']);
    Route::get('models', [C::class, 'models'])->middleware('throttle:120,1,fit-models');
    // 피부 영역 마스크(배치 화면에서 도안이 옷 · 배경으로 나가지 않게 자른다)
    Route::get('models/{model}/skin', [C::class, 'modelSkin'])->whereNumber('model')->middleware('throttle:60,1,fit-model-skin');
    Route::post('skin', [C::class, 'skin'])->middleware('throttle:20,1,fit-skin');
    Route::get('stencils', [C::class, 'stencils']);
    Route::get('results', [C::class, 'list']);
    Route::post('results', [C::class, 'create'])->middleware('throttle:10,1,fit-create');
    Route::get('results/{result}', [C::class, 'show'])->whereUuid('result')->middleware('throttle:120,1,fit-result');
    // 앱 공용 요청 함수가 GET/POST만 써서 삭제도 POST
    Route::post('results/{result}/delete', [C::class, 'delete'])->whereUuid('result');
});
