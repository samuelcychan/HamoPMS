<?php

namespace Modules\Booking\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Booking\Services\InStayModificationService;

class InStayModificationController extends Controller
{
    public function __construct(private readonly InStayModificationService $modifications) {}

    public function moveRoom(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'room_id' => ['required', 'integer'],
        ]);
        $result = $this->modifications->moveRoom(
            (int) $id,
            (int) $validated['room_id'],
            $request->user()->id,
        );

        if ($result === null) {
            return ApiResponse::error(
                'INVENTORY_UNAVAILABLE',
                'The property is unavailable for the requested dates.',
                409,
            );
        }

        return ApiResponse::success($result['booking'], 200, [
            'stay_change' => [
                'type' => 'room_move',
                'modification_id' => $result['modification_id'],
                'rate_difference' => $result['rate_difference'],
            ],
        ]);
    }

    public function adjustDeparture(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'check_out' => ['required', 'date'],
        ]);
        $result = $this->modifications->adjustDeparture(
            (int) $id,
            $validated['check_out'],
            $request->user()->id,
        );

        if ($result === null) {
            return ApiResponse::error(
                'INVENTORY_UNAVAILABLE',
                'The property is unavailable for the requested dates.',
                409,
            );
        }

        return ApiResponse::success($result['booking'], 200, [
            'stay_change' => [
                'type' => $result['change_type'],
                'modification_id' => $result['modification_id'],
                'rate_difference' => $result['rate_difference'],
            ],
        ]);
    }
}
