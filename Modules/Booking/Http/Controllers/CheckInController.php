<?php

namespace Modules\Booking\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Booking\Services\CheckInService;

class CheckInController extends Controller
{
    public function __construct(private readonly CheckInService $checkIn) {}

    public function store(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'room_id' => ['required', 'integer'],
        ]);

        return ApiResponse::success(
            $this->checkIn->checkIn(
                (int) $id,
                $validated['room_id'],
                $request->user()->id,
            ),
        );
    }
}
