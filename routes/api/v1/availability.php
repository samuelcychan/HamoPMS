<?php

use Illuminate\Support\Facades\Route;
use Modules\Booking\Http\Controllers\AvailabilityController;

Route::middleware(['auth:sanctum', 'property.context'])
    ->get('/availability', [AvailabilityController::class, 'index'])
    ->middleware('permission:rooms.read')
    ->name('availability.index');
