<?php

use App\Http\Controllers\StarttooFeedController;
use App\Http\Middleware\StarttooDatabase;
use Illuminate\Support\Facades\Route;

Route::get('v1/projects/starttoo/feed', [StarttooFeedController::class, 'index'])->middleware([StarttooDatabase::class]);
