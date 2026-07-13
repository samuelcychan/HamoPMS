<?php

namespace Modules\Booking\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Support\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Booking\Services\AvailabilitySearchService;

class AvailabilityController extends Controller
{
    public function __construct(
        private readonly AvailabilitySearchService $availability,
        private readonly PropertyContext $propertyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'property_id' => ['sometimes', 'integer', 'exists:properties,id'],
            'check_in' => ['required', 'date', 'after_or_equal:today'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'occupancy' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $propertyId = $this->propertyContext->id($request);
        $startedAt = hrtime(true);
        $roomTypes = $this->availability->search(
            $propertyId,
            $validated['check_in'],
            $validated['check_out'],
            $validated['occupancy'],
        );
        $durationMs = round((hrtime(true) - $startedAt) / 1_000_000, 2);

        return ApiResponse::success($roomTypes, 200, [
            'search' => [
                'property_id' => $propertyId,
                'check_in' => $validated['check_in'],
                'check_out' => $validated['check_out'],
                'occupancy' => $validated['occupancy'],
            ],
            'performance' => [
                'target_ms' => AvailabilitySearchService::RESPONSE_TIME_TARGET_MS,
                'duration_ms' => $durationMs,
                'meets_target' => $durationMs <= AvailabilitySearchService::RESPONSE_TIME_TARGET_MS,
            ],
        ])->withHeaders([
            'Server-Timing' => "availability;dur={$durationMs}",
            'X-Response-Time-Target' => AvailabilitySearchService::RESPONSE_TIME_TARGET_MS.'ms',
        ]);
    }
}
