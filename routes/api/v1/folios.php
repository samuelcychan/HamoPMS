<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Folio Routes (v1)
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->prefix('bookings/{booking_id}/folio')->name('folio.')->group(function () {
    Route::get('/', [\Modules\Folio\Http\Controllers\FolioController::class, 'show'])
        ->name('show');

    Route::post('/line-items', [\Modules\Folio\Http\Controllers\FolioLineItemController::class, 'store'])
        ->name('line-items.store');
});
