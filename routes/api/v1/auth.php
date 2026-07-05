<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth Routes (v1)
|--------------------------------------------------------------------------
*/

Route::post('/auth/register', [\Modules\User\Http\Controllers\AuthController::class, 'register'])
    ->name('auth.register');

Route::post('/auth/login', [\Modules\User\Http\Controllers\AuthController::class, 'login'])
    ->name('auth.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [\Modules\User\Http\Controllers\AuthController::class, 'logout'])
        ->name('auth.logout');

    Route::get('/auth/me', [\Modules\User\Http\Controllers\AuthController::class, 'me'])
        ->name('auth.me');
});
