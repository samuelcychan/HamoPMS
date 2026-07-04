<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /**
     * Lightweight, unauthenticated health/version endpoint for uptime
     * checks and CI smoke tests.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'hamopms',
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
