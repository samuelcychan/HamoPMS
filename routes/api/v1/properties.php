<?php

use Illuminate\Support\Facades\Route;
use Modules\Folio\Http\Controllers\AncillaryChargeTypeController;
use Modules\Property\Http\Controllers\AmenityController;
use Modules\Property\Http\Controllers\HousekeepingTaskController;
use Modules\Property\Http\Controllers\MaintenanceTicketController;
use Modules\Property\Http\Controllers\PropertyController;
use Modules\Property\Http\Controllers\RoomController;
use Modules\Property\Http\Controllers\RoomTypeController;

/*
|--------------------------------------------------------------------------
| Property Routes (v1)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'active'])->prefix('properties')->name('properties.')->group(function () {
    Route::get('/', [PropertyController::class, 'index'])
        ->middleware('permission:properties.read')
        ->name('index');

    Route::post('/', [PropertyController::class, 'store'])
        ->middleware('permission:properties.write')
        ->name('store');

    Route::middleware('property.context')->prefix('/{propertyId}')->group(function () {
        Route::get('/maintenance-tickets', [MaintenanceTicketController::class, 'index'])
            ->middleware('permission:rooms.read')
            ->name('maintenance-tickets.index');
        Route::post('/maintenance-tickets', [MaintenanceTicketController::class, 'store'])
            ->middleware('permission:rooms.write')
            ->name('maintenance-tickets.store');
        Route::get('/maintenance-tickets/{ticketId}', [MaintenanceTicketController::class, 'show'])
            ->middleware('permission:rooms.read')
            ->name('maintenance-tickets.show');
        Route::put('/maintenance-tickets/{ticketId}', [MaintenanceTicketController::class, 'update'])
            ->middleware('permission:rooms.write')
            ->name('maintenance-tickets.update');
        Route::delete('/maintenance-tickets/{ticketId}', [MaintenanceTicketController::class, 'destroy'])
            ->middleware('permission:rooms.write')
            ->name('maintenance-tickets.destroy');
        Route::patch('/maintenance-tickets/{ticketId}/assignment', [MaintenanceTicketController::class, 'assign'])
            ->middleware('permission:rooms.write')
            ->name('maintenance-tickets.assignment.update');
        Route::post('/maintenance-tickets/{ticketId}/start', [MaintenanceTicketController::class, 'start'])
            ->middleware('permission:rooms.write')
            ->name('maintenance-tickets.start');
        Route::post('/maintenance-tickets/{ticketId}/resolve', [MaintenanceTicketController::class, 'resolve'])
            ->middleware('permission:rooms.write')
            ->name('maintenance-tickets.resolve');
        Route::get('/maintenance-tickets/{ticketId}/history', [MaintenanceTicketController::class, 'history'])
            ->middleware('permission:rooms.read')
            ->name('maintenance-tickets.history.index');

        Route::get('/housekeeping-tasks', [HousekeepingTaskController::class, 'index'])
            ->middleware('permission:rooms.read')
            ->name('housekeeping-tasks.index');
        Route::get('/housekeeping-tasks/shift-report', [HousekeepingTaskController::class, 'shiftReport'])
            ->middleware('permission:rooms.read')
            ->name('housekeeping-tasks.shift-report');
        Route::get('/housekeeping-tasks/{taskId}', [HousekeepingTaskController::class, 'show'])
            ->middleware('permission:rooms.read')
            ->name('housekeeping-tasks.show');
        Route::patch('/housekeeping-tasks/{taskId}/assignment', [HousekeepingTaskController::class, 'assign'])
            ->middleware('permission:rooms.write')
            ->name('housekeeping-tasks.assignment.update');
        Route::post('/housekeeping-tasks/{taskId}/start', [HousekeepingTaskController::class, 'start'])
            ->middleware('permission:rooms.write')
            ->name('housekeeping-tasks.start');
        Route::post('/housekeeping-tasks/{taskId}/complete', [HousekeepingTaskController::class, 'complete'])
            ->middleware('permission:rooms.write')
            ->name('housekeeping-tasks.complete');

        Route::get('/ancillary-charge-types', [AncillaryChargeTypeController::class, 'index'])
            ->middleware('permission:folios.read')
            ->name('ancillary-charge-types.index');
        Route::post('/ancillary-charge-types', [AncillaryChargeTypeController::class, 'store'])
            ->middleware('permission:properties.write')
            ->name('ancillary-charge-types.store');
        Route::put('/ancillary-charge-types/{chargeTypeId}', [AncillaryChargeTypeController::class, 'update'])
            ->middleware('permission:properties.write')
            ->name('ancillary-charge-types.update');

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

        Route::get('/amenities', [AmenityController::class, 'index'])
            ->middleware('permission:rooms.read')
            ->name('amenities.index');
        Route::post('/amenities', [AmenityController::class, 'store'])
            ->middleware('permission:rooms.write')
            ->name('amenities.store');
        Route::get('/amenities/{amenityId}', [AmenityController::class, 'show'])
            ->middleware('permission:rooms.read')
            ->name('amenities.show');
        Route::put('/amenities/{amenityId}', [AmenityController::class, 'update'])
            ->middleware('permission:rooms.write')
            ->name('amenities.update');
        Route::delete('/amenities/{amenityId}', [AmenityController::class, 'destroy'])
            ->middleware('permission:rooms.write')
            ->name('amenities.destroy');

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
        Route::put('/rooms/{roomId}/amenities', [RoomController::class, 'syncAmenities'])
            ->middleware('permission:rooms.write')
            ->name('rooms.amenities.update');

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
