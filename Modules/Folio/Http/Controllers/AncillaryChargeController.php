<?php

namespace Modules\Folio\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Folio\Services\AncillaryChargeService;

class AncillaryChargeController extends Controller
{
    public function __construct(private readonly AncillaryChargeService $charges) {}

    public function store(Request $request, string $bookingId): JsonResponse
    {
        $validated = $request->validate([
            'charge_type_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        $result = $this->charges->post(
            (int) $bookingId,
            (int) $validated['charge_type_id'],
            (string) $validated['amount'],
            $validated['description'] ?? null,
            $request->user()->id,
        );

        return ApiResponse::success([
            'charge' => $result['charge'],
            'tax' => $result['tax'],
            'balance' => $result['balance'],
        ], 201);
    }

    public function adjust(Request $request, string $bookingId, string $lineItemId): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['adjustment', 'void'])],
            'amount' => ['required_if:action,adjustment', 'nullable', 'numeric', 'not_in:0', 'min:-99999999.99', 'max:99999999.99'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $result = $this->charges->adjust(
            (int) $bookingId,
            (int) $lineItemId,
            $validated['action'],
            isset($validated['amount']) ? (string) $validated['amount'] : null,
            $validated['reason'],
            $request->user()->id,
        );

        return ApiResponse::success($result, 201);
    }
}
