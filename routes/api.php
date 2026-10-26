<?php

use App\Http\Controllers\ProjectAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/projects/{projectCode}')
    ->where(['projectCode' => '[a-z][a-z0-9-]{1,49}'])
    ->middleware(['project.resolve'])->group(function () {
        Route::post('auth/phone/request', [ProjectAuthController::class, 'requestCode'])
            ->middleware('throttle:phone-send');
        Route::post('auth/phone/verify', [ProjectAuthController::class, 'verify'])
            ->middleware('throttle:phone-verify');

        Route::middleware(['auth:api', 'project.account', 'throttle:project-api'])->group(function () {
            Route::get('me', [ProjectAuthController::class, 'me']);
            Route::patch('me', [ProjectAuthController::class, 'updateProfile']);
            Route::post('auth/logout', [ProjectAuthController::class, 'logout']);
        });
    });
