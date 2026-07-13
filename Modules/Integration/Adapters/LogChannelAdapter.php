<?php

namespace Modules\Integration\Adapters;

use Illuminate\Support\Facades\Log;
use Modules\Integration\Contracts\IntegrationAdapter;
use Modules\Integration\Models\IntegrationEvent;

class LogChannelAdapter implements IntegrationAdapter
{
    public function process(IntegrationEvent $event): void
    {
        Log::info('integration.event.processed', [
            'integration_event_id' => $event->id,
            'property_id' => $event->property_id,
            'provider' => $event->provider,
            'type' => $event->type,
        ]);
    }
}
