<?php

use App\Http\Controllers\CareGroupController;
use App\Http\Controllers\GroupPlaceController;
use App\Http\Controllers\GroupCareController;
use App\Http\Controllers\GroupAnimalController;
use Illuminate\Support\Facades\Route;

// Loaded inside the authenticated, project-scoped API route group.
Route::get('groups/discover', [CareGroupController::class, 'discover']);
Route::post('groups/nearby', [CareGroupController::class, 'nearby'])->middleware('throttle:group-discover');
Route::get('me/groups', [CareGroupController::class, 'mine']);
Route::get('me/group-requests', [CareGroupController::class, 'myRequests']);
Route::post('groups', [CareGroupController::class, 'create'])->middleware('throttle:group-create');
Route::post('invitations/preview', [CareGroupController::class, 'previewInvitation'])->middleware('throttle:group-invite');

Route::prefix('groups/{group}')->whereUuid('group')->group(function () {
    Route::prefix('places/{place}/animals')->whereUuid('place')->group(function () {
        Route::get('/', [GroupAnimalController::class, 'index']);
        Route::get('available', [GroupAnimalController::class, 'available']);
        Route::post('/', [GroupAnimalController::class, 'create']);
        Route::post('import', [GroupAnimalController::class, 'importLocal']);
        Route::post('link', [GroupAnimalController::class, 'link']);
        Route::patch('{animal}', [GroupAnimalController::class, 'update'])->whereUuid('animal');
        Route::delete('{animal}', [GroupAnimalController::class, 'unlink'])->whereUuid('animal');
    });
    Route::get('places/{place}/care-records', [GroupCareController::class, 'index'])->whereUuid('place');
    Route::post('places/{place}/care-records', [GroupCareController::class, 'create'])->whereUuid('place');
    Route::get('places', [GroupPlaceController::class, 'index']);
    Route::post('places', [GroupPlaceController::class, 'create']);
    Route::get('places/{place}', [GroupPlaceController::class, 'show'])->whereUuid('place');
    Route::patch('places/{place}', [GroupPlaceController::class, 'update'])->whereUuid('place');
    Route::delete('places/{place}', [GroupPlaceController::class, 'archive'])->whereUuid('place');
    Route::get('places/{place}/permissions', [GroupPlaceController::class, 'permissions'])->whereUuid('place');
    Route::patch('places/{place}/permissions/{account}', [GroupPlaceController::class, 'grant'])
        ->whereUuid('place')->whereNumber('account');
    Route::get('/', [CareGroupController::class, 'show']);
    Route::patch('/', [CareGroupController::class, 'update']);
    Route::post('invitations', [CareGroupController::class, 'createInvitation']);
    Route::delete('invitations/{invitation}', [CareGroupController::class, 'revokeInvitation'])->whereUuid('invitation');
    Route::post('join-requests', [CareGroupController::class, 'apply'])->middleware('throttle:group-join');
    Route::get('join-requests', [CareGroupController::class, 'requests']);
    Route::post('join-requests/{joinRequest}/review', [CareGroupController::class, 'review'])->whereNumber('joinRequest');
    Route::post('join-requests/{joinRequest}/withdraw', [CareGroupController::class, 'withdraw'])->whereNumber('joinRequest');
    Route::get('members', [CareGroupController::class, 'members']);
    Route::post('leave', [CareGroupController::class, 'leave']);
    Route::delete('members/{account}', [CareGroupController::class, 'removeMember'])->whereNumber('account');
});
