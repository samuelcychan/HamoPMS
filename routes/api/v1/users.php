<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| User Routes (v1)
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->prefix('users')->name('users.')->group(function () {
    Route::get('/', [\Modules\User\Http\Controllers\UserController::class, 'index'])
        ->name('index');

    Route::get('/{id}', [\Modules\User\Http\Controllers\UserController::class, 'show'])
        ->name('show');

    Route::put('/{id}', [\Modules\User\Http\Controllers\UserController::class, 'update'])
        ->name('update');
});
