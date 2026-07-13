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
        ->middleware('permission:properties.read')
        ->name('index');

    Route::post('/', [PropertyController::class, 'store'])
        ->middleware('permission:properties.write')
        ->name('store');

    Route::middleware('property.context')->prefix('/{propertyId}')->group(function () {
        Route::get('/room-types', [RoomTypeController::class, 'index'])
            ->middleware('permission:rooms.read')
            ->name('room-types.index');
        Route::post('/room-types', [RoomTypeController::class, 'store'])
            ->middleware('permission:rooms.write')
            ->name('room-types.store');
        Route::get('/room-types/{roomTypeId}', [RoomTypeController::class, 'show'])
            ->middleware('permission:rooms.read')
            ->name('room-types.show');
        Route::put('/room-types/{roomTypeId}', [RoomTypeController::class, 'update'])
            ->middleware('permission:rooms.write')
            ->name('room-types.update');
        Route::delete('/room-types/{roomTypeId}', [RoomTypeController::class, 'destroy'])
            ->middleware('permission:rooms.write')
            ->name('room-types.destroy');

        Route::get('/rooms', [RoomController::class, 'index'])
            ->middleware('permission:rooms.read')
            ->name('rooms.index');
        Route::post('/rooms', [RoomController::class, 'store'])
            ->middleware('permission:rooms.write')
            ->name('rooms.store');
        Route::get('/rooms/{roomId}', [RoomController::class, 'show'])
            ->middleware('permission:rooms.read')
            ->name('rooms.show');
        Route::put('/rooms/{roomId}', [RoomController::class, 'update'])
            ->middleware('permission:rooms.write')
            ->name('rooms.update');
        Route::delete('/rooms/{roomId}', [RoomController::class, 'destroy'])
            ->middleware('permission:rooms.write')
            ->name('rooms.destroy');
        Route::patch('/rooms/{roomId}/status', [RoomController::class, 'updateStatus'])
            ->middleware('permission:rooms.write')
            ->name('rooms.status.update');
        Route::get('/rooms/{roomId}/status-history', [RoomController::class, 'statusHistory'])
            ->middleware('permission:rooms.read')
            ->name('rooms.status-history.index');

        Route::get('/', [PropertyController::class, 'show'])
            ->middleware('permission:properties.read')
            ->name('show');

        Route::put('/', [PropertyController::class, 'update'])
            ->middleware('permission:properties.write')
            ->name('update');

        Route::delete('/', [PropertyController::class, 'destroy'])
            ->middleware('permission:properties.write')
            ->name('destroy');
    });
});
