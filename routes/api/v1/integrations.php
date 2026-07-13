<?php

use Illuminate\Support\Facades\Route;
use Modules\Integration\Http\Controllers\IntegrationEventController;

Route::post('/integrations/{provider}/events', [IntegrationEventController::class, 'receive'])
    ->middleware('throttle:integration')
    ->name('integrations.events.receive');

Route::middleware(['auth:sanctum', 'active', 'property.context'])->prefix('integration-events')->group(function () {
    Route::get('/', [IntegrationEventController::class, 'index'])
        ->middleware('permission:properties.read')
        ->name('integration-events.index');
    Route::post('/{eventId}/retry', [IntegrationEventController::class, 'retry'])
        ->middleware('permission:properties.write')
        ->name('integration-events.retry');
});
