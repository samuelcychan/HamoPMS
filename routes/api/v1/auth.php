<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth Routes (v1)
|--------------------------------------------------------------------------
*/

Route::post('/auth/register', [\Modules\User\Http\Controllers\AuthController::class, 'register'])
    ->middleware('throttle:auth')
    ->name('auth.register');

Route::post('/auth/login', [\Modules\User\Http\Controllers\AuthController::class, 'login'])
    ->middleware('throttle:auth')
    ->name('auth.login');

Route::middleware(['auth:sanctum', 'throttle:auth'])->group(function () {
    Route::post('/auth/logout', [\Modules\User\Http\Controllers\AuthController::class, 'logout'])
        ->name('auth.logout');

    Route::get('/auth/me', [\Modules\User\Http\Controllers\AuthController::class, 'me'])
        ->name('auth.me');
});
