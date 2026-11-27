<?php

use App\Http\Controllers\StarttooInquiryController as C;
use App\Http\Middleware\StarttooDatabase;
use Illuminate\Support\Facades\Route;

// MY 「1:1 문의」. 답변은 운영 콘솔 「1:1 문의」
Route::prefix('v1/projects/starttoo/inquiries')->middleware([StarttooDatabase::class, 'throttle:120,1,inquiries'])->group(function () {
    Route::get('/', [C::class, 'index']);
    Route::get('options', [C::class, 'options']);
    Route::post('/', [C::class, 'store'])->middleware('throttle:10,1,inquiry-write');
    Route::get('{inquiry}', [C::class, 'show'])->whereNumber('inquiry');
    Route::post('{inquiry}', [C::class, 'update'])->whereNumber('inquiry')->middleware('throttle:20,1,inquiry-write');
    Route::post('{inquiry}/messages', [C::class, 'followUp'])->whereNumber('inquiry')->middleware('throttle:10,1,inquiry-write');
});
