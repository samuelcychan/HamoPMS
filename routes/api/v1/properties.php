<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Property Routes (v1)
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->prefix('properties')->name('properties.')->group(function () {
    Route::get('/', [\Modules\Property\Http\Controllers\PropertyController::class, 'index'])
        ->name('index');

    Route::post('/', [\Modules\Property\Http\Controllers\PropertyController::class, 'store'])
        ->name('store');

    Route::get('/{id}', [\Modules\Property\Http\Controllers\PropertyController::class, 'show'])
        ->name('show');

    Route::put('/{id}', [\Modules\Property\Http\Controllers\PropertyController::class, 'update'])
        ->name('update');

    Route::delete('/{id}', [\Modules\Property\Http\Controllers\PropertyController::class, 'destroy'])
        ->name('destroy');
});
