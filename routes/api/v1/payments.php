<?php

use Illuminate\Support\Facades\Route;
use Modules\Payment\Http\Controllers\PaymentController;
use Modules\Payment\Http\Controllers\StripeWebhookController;

/*
|--------------------------------------------------------------------------
| Payment Routes (v1)
|--------------------------------------------------------------------------
*/

Route::post('/webhooks/stripe', [StripeWebhookController::class, 'store'])
    ->name('payment-webhooks.stripe');

Route::middleware(['auth:sanctum', 'active', 'property.context'])->prefix('payments')->name('payments.')->group(function () {
    Route::get('/', [PaymentController::class, 'index'])
        ->middleware('permission:payments.read')
        ->name('index');

    Route::post('/', [PaymentController::class, 'store'])
        ->middleware('permission:payments.write')
        ->name('store');

    Route::get('/{id}', [PaymentController::class, 'show'])
        ->middleware('permission:payments.read')
        ->name('show');

    Route::post('/{id}/capture', [PaymentController::class, 'capture'])
        ->middleware('permission:payments.write')
        ->name('capture');

    Route::post('/{id}/refund', [PaymentController::class, 'refund'])
        ->middleware('permission:payments.write')
        ->name('refund');

    Route::post('/{id}/void', [PaymentController::class, 'void'])
        ->middleware('permission:payments.write')
        ->name('void');
});
