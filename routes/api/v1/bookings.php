<?php

use Illuminate\Support\Facades\Route;
use Modules\Booking\Http\Controllers\BookingController;
use Modules\Booking\Http\Controllers\CheckInController;
use Modules\Booking\Http\Controllers\CheckOutController;
use Modules\Booking\Http\Controllers\InStayModificationController;
use Modules\Booking\Http\Controllers\ReservationCancellationController;

/*
|--------------------------------------------------------------------------
| Booking Routes (v1)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'active', 'property.context'])->prefix('bookings')->name('bookings.')->group(function () {
    Route::get('/', [BookingController::class, 'index'])
        ->middleware('permission:reservations.read')
        ->name('index');

    Route::post('/', [BookingController::class, 'store'])
        ->middleware('permission:reservations.write')
        ->name('store');

    Route::post('/{id}/check-in', [CheckInController::class, 'store'])
        ->middleware('permission:reservations.write')
        ->name('check-in.store');

    Route::post('/{id}/check-out', [CheckOutController::class, 'store'])
        ->middleware('permission:reservations.write')
        ->name('check-out.store');

    Route::post('/{id}/cancel', [ReservationCancellationController::class, 'store'])
        ->middleware('permission:reservations.write')
        ->name('cancel.store');

    Route::post('/{id}/room-move', [InStayModificationController::class, 'moveRoom'])
        ->middleware('permission:reservations.write')
        ->name('room-move.store');

    Route::post('/{id}/departure-adjustment', [InStayModificationController::class, 'adjustDeparture'])
        ->middleware('permission:reservations.write')
        ->name('departure-adjustment.store');

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
