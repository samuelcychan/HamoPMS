<?php

namespace Modules\Notification\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Modules\Notification\Mail\GuestLifecycleMail;
use Modules\Notification\Models\NotificationDelivery;
use Throwable;

class DeliverGuestNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $deliveryId) {}

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $delivery = NotificationDelivery::findOrFail($this->deliveryId);

        if ($delivery->status === NotificationDelivery::STATUS_SENT) {
            return;
        }

        $delivery->update([
            'status' => NotificationDelivery::STATUS_SENDING,
            'attempts' => $delivery->attempts + 1,
            'failed_at' => null,
            'error_message' => null,
        ]);

        try {
            Mail::to($delivery->recipient)
                ->locale($delivery->locale)
                ->send(new GuestLifecycleMail(
                    $delivery->subject,
                    (string) $delivery->payload['body'],
                ));
        } catch (Throwable $exception) {
            $delivery->update([
                'status' => NotificationDelivery::STATUS_PENDING,
                'error_message' => mb_substr($exception->getMessage(), 0, 4000),
            ]);

            throw $exception;
        }

        $delivery->update([
            'status' => NotificationDelivery::STATUS_SENT,
            'sent_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        NotificationDelivery::query()->whereKey($this->deliveryId)->update([
            'status' => NotificationDelivery::STATUS_FAILED,
            'failed_at' => now(),
            'error_message' => mb_substr($exception?->getMessage() ?? 'Notification delivery failed.', 0, 4000),
        ]);
    }
}
