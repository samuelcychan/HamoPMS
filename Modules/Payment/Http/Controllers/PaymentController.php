<?php

namespace Modules\Payment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Support\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Payment\Models\Payment;
use Modules\Payment\Services\PaymentSettlementService;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PropertyContext $propertyContext,
        private readonly PaymentSettlementService $settlements,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $payments = Payment::query()
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
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'method' => ['required', 'string', 'in:card,bank_transfer,cash'],
        ]);

        $result = $this->settlements->create(
            $validated,
            $request->user()->id,
            $this->idempotencyKey($request),
        );

        return ApiResponse::success(
            $result['payment']->refresh(),
            $result['replayed'] ? 200 : 201,
            ['idempotent_replay' => $result['replayed']],
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $payment = Payment::query()
            ->whereHas(
                'booking',
                fn ($query) => $this->propertyContext->scope($query, $request),
            )
            ->findOrFail($id);

        return ApiResponse::success($payment);
    }

    public function capture(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['sometimes', 'numeric', 'gt:0', 'max:9999999999.99'],
        ]);
        $result = $this->settlements->capture(
            $this->scopedPaymentId($request, $id),
            isset($validated['amount']) ? (string) $validated['amount'] : null,
            $this->idempotencyKey($request),
            $request->user()->id,
        );

        return $this->operationResponse($result);
    }

    public function refund(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['sometimes', 'numeric', 'gt:0', 'max:9999999999.99'],
        ]);
        $result = $this->settlements->refund(
            $this->scopedPaymentId($request, $id),
            isset($validated['amount']) ? (string) $validated['amount'] : null,
            $this->idempotencyKey($request),
            $request->user()->id,
        );

        return $this->operationResponse($result);
    }

    public function void(Request $request, string $id): JsonResponse
    {
        return $this->operationResponse($this->settlements->void(
            $this->scopedPaymentId($request, $id),
            $this->idempotencyKey($request),
            $request->user()->id,
        ));
    }

    private function operationResponse(array $result): JsonResponse
    {
        return ApiResponse::success($result['payment'], 200, [
            'operation' => $result['operation'],
            'idempotent_replay' => $result['replayed'],
        ]);
    }

    private function idempotencyKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || trim($key) === '' || strlen($key) > 255) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['A valid Idempotency-Key header is required.'],
            ]);
        }

        return trim($key);
    }

    private function scopedPaymentId(Request $request, string $id): int
    {
        return (int) Payment::query()
            ->whereHas(
                'booking',
                fn ($query) => $this->propertyContext->scope($query, $request),
            )
            ->findOrFail($id)
            ->getKey();
    }
}
