<?php

namespace Modules\Notification\Listeners;

use Illuminate\Support\Facades\Lang;
use Modules\Booking\Events\ReservationCancelled;
use Modules\Booking\Events\ReservationConfirmed;
use Modules\Booking\Events\ReservationModified;
use Modules\Booking\Models\Booking;
use Modules\Notification\Jobs\DeliverGuestNotification;
use Modules\Notification\Models\NotificationDelivery;
use Modules\Payment\Events\PaymentReceiptIssued;

class QueueGuestLifecycleNotification
{
    public function handle(object $event): void
    {
        $message = $this->messageFor($event);
        $locale = (string) config('app.locale', 'en');
        $subject = Lang::get("notifications.{$message['template_key']}.subject", $message['data'], $locale);
        $body = Lang::get("notifications.{$message['template_key']}.body", $message['data'], $locale);
        $delivery = NotificationDelivery::firstOrCreate(
            ['deduplication_key' => $message['deduplication_key']],
            [
                'property_id' => $message['booking']->property_id,
                'booking_id' => $message['booking']->id,
                'payment_id' => $message['payment_id'],
                'channel' => 'email',
                'template_key' => $message['template_key'],
                'locale' => $locale,
                'recipient' => $message['booking']->guest->email,
                'subject' => $subject,
                'payload' => [
                    'body' => $body,
                    'variables' => $message['data'],
                ],
                'status' => NotificationDelivery::STATUS_PENDING,
                'queued_at' => now(),
            ],
        );

        if ($delivery->wasRecentlyCreated) {
            DeliverGuestNotification::dispatch($delivery->id);
        }
    }

    private function messageFor(object $event): array
    {
        if ($event instanceof ReservationConfirmed) {
            $booking = $this->loadBooking($event->booking);

            return $this->message(
                $booking,
                'reservation_confirmation',
                "reservation.confirmation:{$booking->id}",
            );
        }

        if ($event instanceof ReservationModified) {
            $booking = $this->loadBooking($event->booking);

            return $this->message(
                $booking,
                'reservation_modification',
                "reservation.modification:{$event->modification->id}",
                ['rate_difference' => $event->modification->rate_difference],
            );
        }

        if ($event instanceof ReservationCancelled) {
            $booking = $this->loadBooking($event->booking);

            return $this->message(
                $booking,
                'reservation_cancellation',
                "reservation.cancellation:{$booking->id}",
                [
                    'policy' => $event->policy,
                    'penalty' => $event->penalty,
                ],
            );
        }

        if ($event instanceof PaymentReceiptIssued) {
            $payment = $event->payment->loadMissing('booking');
            $booking = $this->loadBooking($payment->booking);

            return $this->message(
                $booking,
                'payment_receipt',
                "payment.receipt:{$payment->id}",
                [
                    'amount' => $payment->captured_amount,
                    'currency' => $payment->currency,
                    'method' => $payment->method,
                    'payment_id' => $payment->id,
                ],
                $payment->id,
            );
        }

        throw new \InvalidArgumentException('Unsupported guest lifecycle notification event.');
    }

    private function message(
        Booking $booking,
        string $templateKey,
        string $deduplicationKey,
        array $data = [],
        ?int $paymentId = null,
    ): array {
        return [
            'booking' => $booking,
            'payment_id' => $paymentId,
            'template_key' => $templateKey,
            'deduplication_key' => $deduplicationKey,
            'data' => [
                'guest_name' => $booking->guest->name,
                'booking_id' => $booking->id,
                'property_name' => $booking->property->name,
                'room_type' => $booking->roomType->name,
                'check_in' => $booking->check_in->toDateString(),
                'check_out' => $booking->check_out->toDateString(),
                'guests' => $booking->guests,
                ...$data,
            ],
        ];
    }

    private function loadBooking(Booking $booking): Booking
    {
        return $booking->loadMissing(['guest', 'property', 'roomType']);
    }
}
