<?php

namespace Modules\Payment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Payment\Models\Payment;

class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $payments = Payment::where('user_id', $request->user()->id)
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::paginated($payments);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'booking_id' => ['required', 'exists:bookings,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'method' => ['required', 'string', 'in:card,bank_transfer,cash'],
        ]);

        $validated['user_id'] = $request->user()->id;
        $validated['status'] = 'pending';
        $payment = Payment::create($validated);

        return ApiResponse::success($payment, 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $payment = Payment::where('user_id', $request->user()->id)->findOrFail($id);

        return ApiResponse::success($payment);
    }
}
