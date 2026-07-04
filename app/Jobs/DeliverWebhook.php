<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [10, 30, 60, 300, 900];

    public function __construct(
        public WebhookSubscription $subscription,
        public string $eventType,
        public array $payload,
    ) {}

    public function handle(): void
    {
        $body = [
            'event' => $this->eventType,
            'data' => $this->payload,
            'timestamp' => now()->toIso8601String(),
        ];

        $signature = hash_hmac('sha256', json_encode($body), $this->subscription->secret);

        $response = Http::withHeaders([
            'X-HamoPMS-Signature' => $signature,
            'X-HamoPMS-Event' => $this->eventType,
        ])->post($this->subscription->url, $body);

        WebhookDelivery::create([
            'webhook_subscription_id' => $this->subscription->id,
            'event_type' => $this->eventType,
            'payload' => $this->payload,
            'response_status' => $response->status(),
            'attempts' => $this->attempts(),
            'delivered_at' => $response->successful() ? now() : null,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException("Webhook delivery failed with status {$response->status()}");
        }
    }
}
