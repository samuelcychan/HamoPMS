<?php

use Illuminate\Support\Facades\Route;
use Modules\Payment\Http\Controllers\PaymentController;

/*
|--------------------------------------------------------------------------
| Payment Routes (v1)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'property.context'])->prefix('payments')->name('payments.')->group(function () {
    Route::get('/', [PaymentController::class, 'index'])
        ->middleware('permission:payments.read')
        ->name('index');

    Route::post('/', [PaymentController::class, 'store'])
        ->middleware('permission:payments.write')
        ->name('store');

    Route::get('/{id}', [PaymentController::class, 'show'])
        ->middleware('permission:payments.read')
        ->name('show');
});
