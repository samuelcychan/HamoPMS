<?php

use Illuminate\Support\Facades\Route;
use Modules\Booking\Http\Controllers\AvailabilityController;

Route::middleware('auth:sanctum')
    ->get('/availability', [AvailabilityController::class, 'index'])
    ->name('availability.index');
