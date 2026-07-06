<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Payment Routes (v1)
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->prefix('payments')->name('payments.')->group(function () {
    Route::get('/', [\Modules\Payment\Http\Controllers\PaymentController::class, 'index'])
        ->name('index');

    Route::post('/', [\Modules\Payment\Http\Controllers\PaymentController::class, 'store'])
        ->name('store');

    Route::get('/{id}', [\Modules\Payment\Http\Controllers\PaymentController::class, 'show'])
        ->name('show');
});
