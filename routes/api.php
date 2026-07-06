<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| All API routes are prefixed with /api automatically by the router.
| Domain-specific versioned route files are loaded from routes/api/v1.
|
*/

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => config('app.name'),
        'version' => 'v1',
    ]);
});

/*
|--------------------------------------------------------------------------
| V1 Routes
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->name('api.v1.')->group(function () {

    // Auth routes
    require __DIR__.'/api/v1/auth.php';

    // Module routes (loaded per domain module)
    require __DIR__.'/api/v1/users.php';
    require __DIR__.'/api/v1/properties.php';
    require __DIR__.'/api/v1/bookings.php';
    require __DIR__.'/api/v1/payments.php';
    require __DIR__.'/api/v1/folios.php';
});
