<?php

namespace App\Services\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookSubscription;

/**
 * Central dispatch point for HamoPMS domain events. Modules call
 * `WebhookDispatcher::dispatch('reservation.created', $property->id, $payload)`
 * whenever a tracked state change occurs; this fans the event out to every
 * active subscription listening for that event type on that property.
 *
 * Mirrors HAIP's webhook engine: event format `entity.action`, per-property
 * subscription management, and at-least-once delivery via a queued job.
 */
class WebhookDispatcher
{
    public static function dispatch(string $eventType, int $propertyId, array $payload): void
    {
        WebhookSubscription::query()
            ->withoutPropertyScope()
            ->where('property_id', $propertyId)
            ->where('is_active', true)
            ->get()
            ->filter(fn (WebhookSubscription $subscription) => $subscription->listensFor($eventType))
            ->each(function (WebhookSubscription $subscription) use ($eventType, $payload) {
                DeliverWebhook::dispatch($subscription, $eventType, $payload);
            });
    }
}
