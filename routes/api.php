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
            Route::put('me/push-device', [\App\Http\Controllers\PushDeviceController::class, 'put'])->middleware('throttle:push-device');
            Route::delete('me/push-device', [\App\Http\Controllers\PushDeviceController::class, 'delete']);
            Route::get('me', [ProjectAuthController::class, 'me']);
            Route::patch('me', [ProjectAuthController::class, 'updateProfile']);
            Route::post('me/avatar', [ProjectAuthController::class, 'uploadAvatar'])->middleware('throttle:10,1');
            Route::delete('me/avatar', [ProjectAuthController::class, 'deleteAvatar']);
            Route::post('auth/logout', [ProjectAuthController::class, 'logout']);
            Route::get('support-messages', [\App\Http\Controllers\SupportMessageController::class, 'index']);
            Route::post('support-messages', [\App\Http\Controllers\SupportMessageController::class, 'store'])
                ->middleware('throttle:5,1');
            require __DIR__.'/groups.php';
        });
    });
