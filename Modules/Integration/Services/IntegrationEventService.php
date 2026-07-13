<?php

namespace Modules\Integration\Services;

use Illuminate\Database\QueryException;
use Modules\Integration\Jobs\ProcessIntegrationEvent;
use Modules\Integration\Models\IntegrationEvent;
use Throwable;

class IntegrationEventService
{
    public function __construct(private readonly IntegrationAdapterManager $adapters) {}

    public function signatureIsValid(string $provider, string $rawBody, ?string $signature): bool
    {
        $secret = config("integrations.providers.{$provider}.secret");

        return is_string($secret)
            && $secret !== ''
            && is_string($signature)
            && hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    public function ingest(string $provider, string $eventId, array $payload, string $rawBody): array
    {
        $hash = hash('sha256', $rawBody);
        $existing = IntegrationEvent::query()
            ->where('provider', $provider)
            ->where('external_event_id', $eventId)
            ->first();

        if ($existing !== null) {
            return [
                'event' => $existing,
                'replayed' => $existing->payload_hash === $hash,
                'conflict' => $existing->payload_hash !== $hash,
            ];
        }

        try {
            $event = IntegrationEvent::create([
                'property_id' => $payload['property_id'],
                'provider' => $provider,
                'external_event_id' => $eventId,
                'type' => $payload['type'],
                'occurred_at' => $payload['occurred_at'],
                'payload_hash' => $hash,
                'payload' => $payload,
                'status' => IntegrationEvent::STATUS_PENDING,
            ]);
        } catch (QueryException $exception) {
            $existing = IntegrationEvent::query()
                ->where('provider', $provider)
                ->where('external_event_id', $eventId)
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            return [
                'event' => $existing,
                'replayed' => $existing->payload_hash === $hash,
                'conflict' => $existing->payload_hash !== $hash,
            ];
        }
        ProcessIntegrationEvent::dispatch($event->id);

        return ['event' => $event, 'replayed' => false, 'conflict' => false];
    }

    public function process(int $eventId): void
    {
        $event = IntegrationEvent::findOrFail($eventId);

        if (in_array($event->status, [
            IntegrationEvent::STATUS_COMPLETED,
            IntegrationEvent::STATUS_DEAD_LETTERED,
        ], true)) {
            return;
        }

        $event->update([
            'status' => IntegrationEvent::STATUS_PROCESSING,
            'attempts' => $event->attempts + 1,
            'last_error' => null,
            'failed_at' => null,
        ]);

        try {
            $this->adapters->for($event->provider)->process($event);
        } catch (Throwable $exception) {
            $event->update([
                'status' => IntegrationEvent::STATUS_FAILED,
                'last_error' => mb_substr($exception->getMessage(), 0, 4000),
                'failed_at' => now(),
            ]);

            throw $exception;
        }

        $event->update([
            'status' => IntegrationEvent::STATUS_COMPLETED,
            'processed_at' => now(),
        ]);
    }

    public function retry(IntegrationEvent $event): IntegrationEvent
    {
        $event->update([
            'status' => IntegrationEvent::STATUS_PENDING,
            'last_error' => null,
            'failed_at' => null,
        ]);
        ProcessIntegrationEvent::dispatch($event->id);

        return $event->refresh();
    }
}
