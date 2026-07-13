<?php

namespace Modules\Payment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Support\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Payment\Models\Payment;

class PaymentController extends Controller
{
    public function __construct(private readonly PropertyContext $propertyContext) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $payments = Payment::query()
            ->where('user_id', $request->user()->id)
            ->whereHas(
                'booking',
                fn ($query) => $this->propertyContext->scope($query, $request),
            )
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::paginated($payments);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'booking_id' => [
                'required',
                Rule::exists('bookings', 'id')->where(
                    fn ($query) => $query
                        ->where('property_id', $this->propertyContext->id($request))
                        ->whereNull('deleted_at'),
                ),
            ],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'method' => ['required', 'string', 'in:card,bank_transfer,cash'],
        ]);

        $validated['user_id'] = $request->user()->id;
        $validated['status'] = Payment::STATUS_PENDING;
        $payment = Payment::create($validated);

        return ApiResponse::success($payment, 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $payment = Payment::query()
            ->where('user_id', $request->user()->id)
            ->whereHas(
                'booking',
                fn ($query) => $this->propertyContext->scope($query, $request),
            )
            ->findOrFail($id);

        return ApiResponse::success($payment);
    }
}
