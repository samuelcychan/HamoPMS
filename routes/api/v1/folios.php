<?php

use Illuminate\Support\Facades\Route;
use Modules\Folio\Http\Controllers\FolioController;
use Modules\Folio\Http\Controllers\FolioLineItemController;

/*
|--------------------------------------------------------------------------
| Folio Routes (v1)
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->prefix('bookings/{booking_id}/folio')->name('folio.')->group(function () {
    Route::get('/', [FolioController::class, 'show'])
        ->middleware('permission:folios.read')
        ->name('show');

    Route::post('/line-items', [FolioLineItemController::class, 'store'])
        ->middleware('permission:folios.write')
        ->name('line-items.store');
});
