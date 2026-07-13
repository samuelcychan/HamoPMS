<?php

use Illuminate\Support\Facades\Route;
use Modules\Property\Http\Controllers\PropertyController;
use Modules\Property\Http\Controllers\RoomController;
use Modules\Property\Http\Controllers\RoomTypeController;

/*
|--------------------------------------------------------------------------
| Property Routes (v1)
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->prefix('properties')->name('properties.')->group(function () {
    Route::get('/', [PropertyController::class, 'index'])
        ->name('index');

    Route::post('/', [PropertyController::class, 'store'])
        ->name('store');

    Route::prefix('/{propertyId}')->group(function () {
        Route::get('/room-types', [RoomTypeController::class, 'index'])
            ->name('room-types.index');
        Route::post('/room-types', [RoomTypeController::class, 'store'])
            ->name('room-types.store');
        Route::get('/room-types/{roomTypeId}', [RoomTypeController::class, 'show'])
            ->name('room-types.show');
        Route::put('/room-types/{roomTypeId}', [RoomTypeController::class, 'update'])
            ->name('room-types.update');
        Route::delete('/room-types/{roomTypeId}', [RoomTypeController::class, 'destroy'])
            ->name('room-types.destroy');

        Route::get('/rooms', [RoomController::class, 'index'])
            ->name('rooms.index');
        Route::post('/rooms', [RoomController::class, 'store'])
            ->name('rooms.store');
        Route::get('/rooms/{roomId}', [RoomController::class, 'show'])
            ->name('rooms.show');
        Route::put('/rooms/{roomId}', [RoomController::class, 'update'])
            ->name('rooms.update');
        Route::delete('/rooms/{roomId}', [RoomController::class, 'destroy'])
            ->name('rooms.destroy');
        Route::patch('/rooms/{roomId}/status', [RoomController::class, 'updateStatus'])
            ->name('rooms.status.update');
        Route::get('/rooms/{roomId}/status-history', [RoomController::class, 'statusHistory'])
            ->name('rooms.status-history.index');
    });

    Route::get('/{id}', [PropertyController::class, 'show'])
        ->name('show');

    Route::put('/{id}', [PropertyController::class, 'update'])
        ->name('update');

    Route::delete('/{id}', [PropertyController::class, 'destroy'])
        ->name('destroy');
});
