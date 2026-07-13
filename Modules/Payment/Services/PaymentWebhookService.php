<?php

namespace Modules\Payment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Booking\Services\ReservationRateCalculator;
use Modules\Payment\Events\PaymentReceiptIssued;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentWebhookEvent;

class PaymentWebhookService
{
    public function __construct(private readonly ReservationRateCalculator $rates) {}

    public function handle(string $gateway, array $payload): PaymentWebhookEvent
    {
        $result = DB::transaction(function () use ($gateway, $payload): array {
            $eventId = (string) ($payload['id'] ?? '');
            $type = (string) ($payload['type'] ?? '');
            $event = PaymentWebhookEvent::query()
                ->where('gateway', $gateway)
                ->where('event_id', $eventId)
                ->lockForUpdate()
                ->first();

            if ($event !== null) {
                return ['event' => $event, 'receipt_payment' => null];
            }

            $event = PaymentWebhookEvent::create([
                'gateway' => $gateway,
                'event_id' => $eventId,
                'type' => $type,
                'payload' => $payload,
            ]);
            $object = $payload['data']['object'] ?? [];
            $gatewayPaymentId = (string) ($object['payment_intent'] ?? $object['id'] ?? '');
            $payment = Payment::query()
                ->where('gateway', $gateway)
                ->where('gateway_payment_id', $gatewayPaymentId)
                ->lockForUpdate()
                ->first();

            if ($payment !== null) {
                $wasCompleted = $payment->status === Payment::STATUS_COMPLETED;
                $this->reconcile($payment, $type, is_array($object) ? $object : []);
                $payment->refresh();
            }

            $event->update(['processed_at' => now()]);

            return [
                'event' => $event->refresh(),
                'receipt_payment' => isset($wasCompleted)
                    && ! $wasCompleted
                    && $payment->status === Payment::STATUS_COMPLETED
                        ? $payment
                        : null,
            ];
        });

        if ($result['receipt_payment'] !== null) {
            PaymentReceiptIssued::dispatch($result['receipt_payment']);
        }

        return $result['event'];
    }

    private function reconcile(Payment $payment, string $type, array $object): void
    {
        if ($type === 'payment_intent.succeeded') {
            $capturedMinor = (int) ($object['amount_received'] ?? $object['amount'] ?? 0);
            $payment->update([
                'captured_amount' => $this->rates->formatCents($capturedMinor),
                'status' => Payment::STATUS_COMPLETED,
                'settlement_status' => 'captured',
            ]);

            return;
        }

        if ($type === 'payment_intent.amount_capturable_updated') {
            $payment->update([
                'status' => Payment::STATUS_REQUIRES_CAPTURE,
                'settlement_status' => Payment::STATUS_REQUIRES_CAPTURE,
            ]);

            return;
        }

        if ($type === 'payment_intent.canceled') {
            $payment->update([
                'status' => Payment::STATUS_VOIDED,
                'settlement_status' => 'voided',
            ]);

            return;
        }

        if ($type === 'charge.refunded') {
            $refundedMinor = (int) ($object['amount_refunded'] ?? 0);
            $capturedMinor = max(
                $this->rates->amountCents($payment->captured_amount),
                (int) ($object['amount'] ?? 0),
            );
            $payment->update([
                'captured_amount' => $this->rates->formatCents($capturedMinor),
                'refunded_amount' => $this->rates->formatCents($refundedMinor),
                'status' => $refundedMinor >= $capturedMinor
                    ? Payment::STATUS_REFUNDED
                    : Payment::STATUS_PARTIALLY_REFUNDED,
                'settlement_status' => $refundedMinor >= $capturedMinor ? 'refunded' : 'partially_refunded',
            ]);

            return;
        }

        if ($type === 'refund.updated') {
            $payment->update([
                'settlement_status' => 'refund_'.(string) ($object['status'] ?? 'unknown'),
            ]);
        }
    }
}
