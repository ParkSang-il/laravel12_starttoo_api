<?php

use App\Http\Controllers\StarttooActivityController;
use App\Http\Middleware\StarttooDatabase;
use Illuminate\Support\Facades\Route;

Route::get('v1/projects/starttoo/me/activity', [StarttooActivityController::class, 'index'])->middleware([StarttooDatabase::class]);
