<?php

namespace Modules\Payment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Payment\Models\Payment;

class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $payments = Payment::paginate(15);

        return response()->json($payments);
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

        return response()->json($payment, 201);
    }

    public function show(string $id): JsonResponse
    {
        $payment = Payment::findOrFail($id);

        return response()->json($payment);
    }
}
