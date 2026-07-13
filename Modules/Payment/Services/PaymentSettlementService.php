<?php

namespace Modules\Payment\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Booking\Models\Booking;
use Modules\Booking\Services\ReservationRateCalculator;
use Modules\Payment\Contracts\PaymentGateway;
use Modules\Payment\Data\PaymentIntentRequest;
use Modules\Payment\Data\PaymentOperationRequest;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentOperation;

class PaymentSettlementService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly ReservationRateCalculator $rates,
    ) {}

    public function create(array $attributes, int $actorId, string $idempotencyKey): array
    {
        $existing = Payment::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            $sameRequest = (int) $existing->booking_id === (int) $attributes['booking_id']
                && $existing->amount === $this->rates->formatCents(
                    $this->rates->amountCents((string) $attributes['amount']),
                )
                && $existing->currency === strtoupper($attributes['currency'])
                && $existing->method === $attributes['method'];

            if (! $sameRequest) {
                throw ValidationException::withMessages([
                    'idempotency_key' => ['This idempotency key was already used for a different payment request.'],
                ]);
            }

            return ['payment' => $existing, 'replayed' => true];
        }

        $booking = Booking::findOrFail($attributes['booking_id']);

        if (in_array($booking->status, [Booking::STATUS_CANCELLED, Booking::STATUS_COMPLETED], true)) {
            throw ValidationException::withMessages([
                'booking_id' => ['Payments cannot be created for a closed reservation.'],
            ]);
        }

        $amountCents = $this->rates->amountCents((string) $attributes['amount']);
        $gatewayResult = null;

        if ($attributes['method'] === 'card') {
            $gatewayResult = $this->gateway->createPaymentIntent(new PaymentIntentRequest(
                $amountCents,
                strtoupper($attributes['currency']),
                $idempotencyKey,
                "Booking {$booking->id} payment",
                ['booking_id' => (string) $booking->id],
            ));
        }

        $payment = Payment::create([
            'user_id' => $actorId,
            'booking_id' => $booking->id,
            'amount' => $this->rates->formatCents($amountCents),
            'currency' => strtoupper($attributes['currency']),
            'method' => $attributes['method'],
            'gateway' => $gatewayResult?->gateway,
            'gateway_payment_id' => $gatewayResult?->transactionId,
            'idempotency_key' => $idempotencyKey,
            'status' => $gatewayResult?->status === Payment::STATUS_REQUIRES_CAPTURE
                ? Payment::STATUS_REQUIRES_CAPTURE
                : Payment::STATUS_PENDING,
            'settlement_status' => $gatewayResult?->status ?? Payment::STATUS_PENDING,
        ]);

        return ['payment' => $payment, 'replayed' => false];
    }

    public function capture(int $paymentId, ?string $amount, string $idempotencyKey, int $actorId): array
    {
        return $this->operate($paymentId, 'capture', $amount, $idempotencyKey, $actorId);
    }

    public function refund(int $paymentId, ?string $amount, string $idempotencyKey, int $actorId): array
    {
        return $this->operate($paymentId, 'refund', $amount, $idempotencyKey, $actorId);
    }

    public function void(int $paymentId, string $idempotencyKey, int $actorId): array
    {
        return $this->operate($paymentId, 'void', null, $idempotencyKey, $actorId);
    }

    private function operate(int $paymentId, string $type, ?string $amount, string $idempotencyKey, int $actorId): array
    {
        return DB::transaction(function () use ($paymentId, $type, $amount, $idempotencyKey, $actorId): array {
            $payment = Payment::query()->lockForUpdate()->findOrFail($paymentId);
            $existing = PaymentOperation::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                if ((int) $existing->payment_id !== (int) $payment->id || $existing->type !== $type) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['This idempotency key belongs to another payment operation.'],
                    ]);
                }

                return ['payment' => $payment, 'operation' => $existing, 'replayed' => true];
            }

            if ($payment->gateway !== $this->gateway->name() || $payment->gateway_payment_id === null) {
                throw ValidationException::withMessages([
                    'payment' => ['This payment is not attached to the configured gateway.'],
                ]);
            }

            $amountCents = $this->operationAmountCents($payment, $type, $amount);
            $operation = $payment->operations()->create([
                'performed_by' => $actorId,
                'type' => $type,
                'amount' => $amountCents === null ? null : $this->rates->formatCents($amountCents),
                'idempotency_key' => $idempotencyKey,
                'status' => PaymentOperation::STATUS_PROCESSING,
            ]);
            $request = new PaymentOperationRequest(
                $payment->gateway_payment_id,
                $idempotencyKey,
                $amountCents,
            );
            $result = match ($type) {
                'capture' => $this->gateway->capture($request),
                'refund' => $this->gateway->refund($request),
                'void' => $this->gateway->void($request),
            };

            $operation->update([
                'gateway_transaction_id' => $result->transactionId,
                'status' => PaymentOperation::STATUS_COMPLETED,
                'gateway_response' => $result->toArray(),
            ]);
            $this->applyOperationResult($payment, $type, $amountCents, $result->status);

            return [
                'payment' => $payment->refresh(),
                'operation' => $operation->refresh(),
                'replayed' => false,
            ];
        });
    }

    private function operationAmountCents(Payment $payment, string $type, ?string $amount): ?int
    {
        $paymentCents = $this->rates->amountCents($payment->amount);
        $capturedCents = $this->rates->amountCents($payment->captured_amount);
        $refundedCents = $this->rates->amountCents($payment->refunded_amount);

        if ($type === 'void') {
            if ($capturedCents > 0 || in_array($payment->status, [Payment::STATUS_VOIDED, Payment::STATUS_REFUNDED], true)) {
                throw ValidationException::withMessages([
                    'payment' => ['Only an uncaptured active payment can be voided.'],
                ]);
            }

            return null;
        }

        if ($type === 'capture' && $payment->status !== Payment::STATUS_REQUIRES_CAPTURE) {
            throw ValidationException::withMessages([
                'payment' => ['Only a payment that requires capture can be captured.'],
            ]);
        }

        $availableCents = $type === 'capture'
            ? $paymentCents - $capturedCents
            : $capturedCents - $refundedCents;
        $amountCents = $amount === null ? $availableCents : $this->rates->amountCents($amount);

        if ($type === 'capture' && $amountCents !== $availableCents) {
            throw ValidationException::withMessages([
                'amount' => ['Capture the full tender amount; use separate payments for split settlement.'],
            ]);
        }

        if ($amountCents < 1 || $amountCents > $availableCents) {
            throw ValidationException::withMessages([
                'amount' => ["The {$type} amount exceeds the available payment balance."],
            ]);
        }

        return $amountCents;
    }

    private function applyOperationResult(Payment $payment, string $type, ?int $amountCents, string $gatewayStatus): void
    {
        if ($type === 'capture' && $gatewayStatus === 'succeeded') {
            $capturedCents = $this->rates->amountCents($payment->captured_amount) + (int) $amountCents;
            $payment->update([
                'captured_amount' => $this->rates->formatCents($capturedCents),
                'status' => Payment::STATUS_COMPLETED,
                'settlement_status' => 'captured',
            ]);

            return;
        }

        if ($type === 'refund' && $gatewayStatus === 'succeeded') {
            $refundedCents = $this->rates->amountCents($payment->refunded_amount) + (int) $amountCents;
            $capturedCents = $this->rates->amountCents($payment->captured_amount);
            $payment->update([
                'refunded_amount' => $this->rates->formatCents($refundedCents),
                'status' => $refundedCents === $capturedCents
                    ? Payment::STATUS_REFUNDED
                    : Payment::STATUS_PARTIALLY_REFUNDED,
                'settlement_status' => $refundedCents === $capturedCents ? 'refunded' : 'partially_refunded',
            ]);

            return;
        }

        if ($type === 'void' && $gatewayStatus === 'canceled') {
            $payment->update([
                'status' => Payment::STATUS_VOIDED,
                'settlement_status' => 'voided',
            ]);

            return;
        }

        $payment->update(['settlement_status' => $gatewayStatus]);
    }
}
