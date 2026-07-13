<?php

use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\AuthController;

/*
|--------------------------------------------------------------------------
| Auth Routes (v1)
|--------------------------------------------------------------------------
*/

Route::post('/auth/register', [AuthController::class, 'register'])
    ->middleware('throttle:auth')
    ->name('auth.register');

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:auth')
    ->name('auth.login');

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout'])
        ->middleware('throttle:auth')
        ->name('auth.logout');

    Route::get('/auth/me', [AuthController::class, 'me'])
        ->name('auth.me');
});
