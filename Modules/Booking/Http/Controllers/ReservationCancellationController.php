<?php

namespace Modules\Booking\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Booking\Services\ReservationCancellationService;

class ReservationCancellationController extends Controller
{
    public function __construct(private readonly ReservationCancellationService $cancellations) {}

    public function store(Request $request, string $id): JsonResponse
    {
        $result = $this->cancellations->cancel((int) $id, $request->user()->id);

        return ApiResponse::success($result['booking'], 200, [
            'cancellation' => [
                'policy' => $result['evaluation']['policy'],
                'is_free' => $result['evaluation']['is_free'],
                'is_non_refundable' => $result['evaluation']['is_non_refundable'],
                'hours_before_check_in' => $result['evaluation']['hours_before_check_in'],
                'penalty' => $result['penalty'],
            ],
        ]);
    }
}
