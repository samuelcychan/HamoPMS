<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Booking Routes (v1)
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->prefix('bookings')->name('bookings.')->group(function () {
    Route::get('/', [\Modules\Booking\Http\Controllers\BookingController::class, 'index'])
        ->name('index');

    Route::post('/', [\Modules\Booking\Http\Controllers\BookingController::class, 'store'])
        ->name('store');

    Route::get('/{id}', [\Modules\Booking\Http\Controllers\BookingController::class, 'show'])
        ->name('show');

    Route::put('/{id}', [\Modules\Booking\Http\Controllers\BookingController::class, 'update'])
        ->name('update');

    Route::delete('/{id}', [\Modules\Booking\Http\Controllers\BookingController::class, 'destroy'])
        ->name('destroy');
});
