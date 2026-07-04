<?php

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (v1)
|--------------------------------------------------------------------------
|
| All HamoPMS API endpoints are versioned under /api/v1. Authenticated
| routes use Sanctum tokens and are scoped to a property via the
| ResolvePropertyContext middleware (see bootstrap/app.php).
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('/health', HealthController::class)->name('health');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', function (\Illuminate\Http\Request $request) {
            return $request->user();
        })->name('me');

        // Feature module routes (reservations, rooms, folios, ...) are
        // registered here as each module is implemented. See BACKLOG.md
        // for the planned Epic/Feature/Task breakdown.
    });
});
