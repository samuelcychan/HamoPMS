<?php

namespace Modules\Booking\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Booking\Services\CheckOutService;

class CheckOutController extends Controller
{
    public function __construct(private readonly CheckOutService $checkOut) {}

    public function store(Request $request, string $id): JsonResponse
    {
        $result = $this->checkOut->checkOut((int) $id, $request->user()->id);

        if (! $result['checked_out']) {
            return ApiResponse::error(
                'FOLIO_BALANCE_OUTSTANDING',
                'The folio must have a zero balance before check-out.',
                409,
                [
                    'folio_id' => $result['folio']->id,
                    'outstanding_balance' => $result['outstanding_balance'],
                    'late_checkout_fee' => $result['late_checkout_fee'],
                ],
            );
        }

        return ApiResponse::success($result['booking'], 200, [
            'checkout' => [
                'folio_id' => $result['folio']->id,
                'outstanding_balance' => $result['outstanding_balance'],
                'late_checkout_fee' => $result['late_checkout_fee'],
                'housekeeping_task_id' => $result['housekeeping_task']->id,
            ],
        ]);
    }
}
