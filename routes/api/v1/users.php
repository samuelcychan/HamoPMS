<?php

use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\UserController;

/*
|--------------------------------------------------------------------------
| User Routes (v1)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'active'])->prefix('users')->name('users.')->group(function () {
    Route::get('/', [UserController::class, 'index'])
        ->name('index');

    Route::get('/{id}', [UserController::class, 'show'])
        ->name('show');

    Route::put('/{id}', [UserController::class, 'update'])
        ->name('update');
});
