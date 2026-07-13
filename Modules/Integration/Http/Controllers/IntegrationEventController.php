<?php

namespace Modules\Integration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Support\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Integration\Models\IntegrationEvent;
use Modules\Integration\Services\IntegrationEventService;

class IntegrationEventController extends Controller
{
    public function __construct(
        private readonly IntegrationEventService $events,
        private readonly PropertyContext $propertyContext,
    ) {}

    public function receive(Request $request, string $provider): JsonResponse
    {
        if (preg_match('/^[a-z0-9_-]{1,60}$/', $provider) !== 1) {
            return ApiResponse::error('INVALID_INTEGRATION_PROVIDER', 'The integration provider is invalid.', 422);
        }

        $eventId = $request->header('X-Integration-Event-ID');
        $signature = $request->header('X-Integration-Signature');

        if (! is_string($eventId) || $eventId === '' || mb_strlen($eventId) > 255) {
            return ApiResponse::error('INVALID_INTEGRATION_EVENT_ID', 'A valid integration event ID is required.', 422);
        }

        if (! $this->events->signatureIsValid($provider, $request->getContent(), $signature)) {
            return ApiResponse::error('INVALID_INTEGRATION_SIGNATURE', 'The integration signature is invalid.', 401);
        }

        $validated = $request->validate([
            'property_id' => ['required', 'integer', 'exists:properties,id'],
            'type' => ['required', 'string', 'max:100'],
            'occurred_at' => ['required', 'date'],
            'data' => ['required', 'array'],
        ]);
        $result = $this->events->ingest($provider, $eventId, $validated, $request->getContent());

        if ($result['conflict']) {
            return ApiResponse::error(
                'INTEGRATION_EVENT_CONFLICT',
                'This event ID was already used for a different payload.',
                409,
            );
        }

        return ApiResponse::success($this->eventData($result['event']), $result['replayed'] ? 200 : 202, [
            'idempotent_replay' => $result['replayed'],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'property_id' => ['sometimes', 'integer', 'min:1'],
            'provider' => ['sometimes', 'string', 'max:60'],
            'status' => ['sometimes', Rule::in(IntegrationEvent::STATUSES)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $events = IntegrationEvent::query()
            ->where('property_id', $this->propertyContext->id($request))
            ->when(isset($validated['provider']), fn ($query) => $query->where('provider', $validated['provider']))
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->latest('id')
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::paginated($events);
    }

    public function retry(Request $request, string $eventId): JsonResponse
    {
        $event = IntegrationEvent::query()
            ->where('property_id', $this->propertyContext->id($request))
            ->findOrFail($eventId);

        if (! in_array($event->status, [IntegrationEvent::STATUS_FAILED, IntegrationEvent::STATUS_DEAD_LETTERED], true)) {
            return ApiResponse::error('INTEGRATION_EVENT_NOT_RETRYABLE', 'Only failed events can be retried.', 409);
        }

        return ApiResponse::success($this->eventData($this->events->retry($event)), 202);
    }

    private function eventData(IntegrationEvent $event): array
    {
        return $event->only([
            'id',
            'property_id',
            'provider',
            'external_event_id',
            'type',
            'occurred_at',
            'status',
            'attempts',
            'last_error',
            'processed_at',
            'failed_at',
            'created_at',
            'updated_at',
        ]);
    }
}
