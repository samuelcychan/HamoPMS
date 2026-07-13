<?php

namespace Modules\Integration\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Integration\Models\IntegrationEvent;
use Modules\Integration\Services\IntegrationEventService;
use Throwable;

class ProcessIntegrationEvent implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public function __construct(public readonly int $eventId)
    {
        $this->tries = max(1, (int) config('integrations.max_attempts', 3));
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(IntegrationEventService $events): void
    {
        $events->process($this->eventId);
    }

    public function failed(?Throwable $exception): void
    {
        IntegrationEvent::query()->whereKey($this->eventId)->update([
            'status' => IntegrationEvent::STATUS_DEAD_LETTERED,
            'last_error' => mb_substr($exception?->getMessage() ?? 'Integration event processing failed.', 0, 4000),
            'failed_at' => now(),
        ]);
    }
}
