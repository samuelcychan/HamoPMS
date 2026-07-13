<?php

use Illuminate\Support\Facades\Route;
use Modules\Reporting\Http\Controllers\OperationalReportController;
use Modules\Reporting\Http\Controllers\RevenueReportController;

Route::middleware(['auth:sanctum', 'active', 'property.context', 'permission:reservations.read'])
    ->prefix('reports')
    ->name('reports.')
    ->group(function () {
        Route::get('/arrivals', [OperationalReportController::class, 'arrivals'])->name('arrivals');
        Route::get('/departures', [OperationalReportController::class, 'departures'])->name('departures');
        Route::get('/in-house', [OperationalReportController::class, 'inHouse'])->name('in-house');
        Route::get('/occupancy', [OperationalReportController::class, 'occupancy'])->name('occupancy');

        Route::get('/revenue', [RevenueReportController::class, 'index'])
            ->middleware('permission:folios.read')
            ->name('revenue');
        Route::post('/revenue/period-close', [RevenueReportController::class, 'close'])
            ->middleware('permission:folios.adjust')
            ->name('revenue.period-close');
        Route::get('/revenue/period-close/{id}', [RevenueReportController::class, 'show'])
            ->middleware('permission:folios.read')
            ->name('revenue.period-close.show');
    });
