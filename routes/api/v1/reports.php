<?php

use Illuminate\Support\Facades\Route;
use Modules\Reporting\Http\Controllers\OperationalReportController;

Route::middleware(['auth:sanctum', 'active', 'property.context', 'permission:reservations.read'])
    ->prefix('reports')
    ->name('reports.')
    ->group(function () {
        Route::get('/arrivals', [OperationalReportController::class, 'arrivals'])->name('arrivals');
        Route::get('/departures', [OperationalReportController::class, 'departures'])->name('departures');
        Route::get('/in-house', [OperationalReportController::class, 'inHouse'])->name('in-house');
        Route::get('/occupancy', [OperationalReportController::class, 'occupancy'])->name('occupancy');
    });
