<?php

use Illuminate\Support\Facades\Route;
use Modules\Booking\Http\Controllers\BookingController;

/*
|--------------------------------------------------------------------------
| Booking Routes (v1)
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->prefix('bookings')->name('bookings.')->group(function () {
    Route::get('/', [BookingController::class, 'index'])
        ->middleware('permission:reservations.read')
        ->name('index');

    Route::post('/', [BookingController::class, 'store'])
        ->middleware('permission:reservations.write')
        ->name('store');

    Route::get('/{id}', [BookingController::class, 'show'])
        ->middleware('permission:reservations.read')
        ->name('show');

    Route::put('/{id}', [BookingController::class, 'update'])
        ->middleware('permission:reservations.write')
        ->name('update');

    Route::delete('/{id}', [BookingController::class, 'destroy'])
        ->middleware('permission:reservations.write')
        ->name('destroy');
});
