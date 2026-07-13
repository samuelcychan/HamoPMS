<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\Integration\Contracts\IntegrationAdapter;
use Modules\Integration\Jobs\ProcessIntegrationEvent;
use Modules\Integration\Models\IntegrationEvent;
use Modules\Integration\Services\IntegrationEventService;
use Modules\Property\Models\Property;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class IntegrationEventTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        RecordingIntegrationAdapter::$processed = [];
        RecordingIntegrationAdapter::$fail = false;
        config()->set('integrations.providers.ota', [
            'secret' => 'integration-secret',
            'adapter' => RecordingIntegrationAdapter::class,
        ]);
        $this->seed(RolePermissionSeeder::class);
        $this->property = Property::create([
            'name' => 'Integration Hotel',
            'address' => '1 Main Street',
            'type' => 'hotel',
        ]);
        $this->manager = User::factory()->create();
        RoleAssignment::create([
            'user_id' => $this->manager->id,
            'role_id' => Role::where('slug', 'manager')->value('id'),
            'property_id' => $this->property->id,
        ]);
    }

    public function test_signed_inbound_events_are_idempotent_and_processed_through_the_adapter(): void
    {
        $body = $this->payload('reservation.created');
        $this->receive('evt-1', $body)
            ->assertStatus(202)
            ->assertJsonPath('data.status', IntegrationEvent::STATUS_PENDING)
            ->assertJsonPath('data.occurred_at', '2026-07-13T12:00:00.000000Z')
            ->assertJsonPath('meta.idempotent_replay', false);
        Queue::assertPushed(ProcessIntegrationEvent::class, 1);

        $event = IntegrationEvent::firstOrFail();
        (new ProcessIntegrationEvent($event->id))->handle(app(IntegrationEventService::class));
        $this->assertSame(IntegrationEvent::STATUS_COMPLETED, $event->fresh()->status);
        $this->assertSame([$event->id], RecordingIntegrationAdapter::$processed);

        $this->receive('evt-1', $body)
            ->assertOk()
            ->assertJsonPath('data.status', IntegrationEvent::STATUS_COMPLETED)
            ->assertJsonPath('meta.idempotent_replay', true);
        Queue::assertPushed(ProcessIntegrationEvent::class, 1);
        $this->assertDatabaseCount('integration_events', 1);

        $conflictingBody = $this->payload('reservation.cancelled');
        $this->receive('evt-1', $conflictingBody)
            ->assertConflict()
            ->assertJsonPath('error.code', 'INTEGRATION_EVENT_CONFLICT');
        $this->receive('evt-invalid', $body, 'bad-signature')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_INTEGRATION_SIGNATURE');
        $this->call('POST', '/api/v1/integrations/bad.provider/events', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'INVALID_INTEGRATION_PROVIDER');
    }

    public function test_failed_events_are_observable_dead_lettered_and_retryable(): void
    {
        RecordingIntegrationAdapter::$fail = true;
        $this->receive('evt-fail', $this->payload('availability.changed'))->assertStatus(202);
        $event = IntegrationEvent::firstOrFail();
        $job = new ProcessIntegrationEvent($event->id);
        $exception = null;

        try {
            $job->handle(app(IntegrationEventService::class));
        } catch (Throwable $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $job->failed($exception);
        $this->assertDatabaseHas('integration_events', [
            'id' => $event->id,
            'status' => IntegrationEvent::STATUS_DEAD_LETTERED,
            'attempts' => 1,
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/integration-events?property_id={$this->property->id}&status=dead_lettered")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.payload')
            ->assertJsonPath('data.0.last_error', 'Adapter failure.');

        RecordingIntegrationAdapter::$fail = false;
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/integration-events/{$event->id}/retry?property_id={$this->property->id}")
            ->assertStatus(202)
            ->assertJsonPath('data.status', IntegrationEvent::STATUS_PENDING);
        Queue::assertPushed(ProcessIntegrationEvent::class, 2);
    }

    private function payload(string $type): string
    {
        return json_encode([
            'property_id' => $this->property->id,
            'type' => $type,
            'occurred_at' => '2026-07-13T12:00:00Z',
            'data' => ['reservation_id' => 'OTA-123'],
        ], JSON_THROW_ON_ERROR);
    }

    private function receive(string $eventId, string $body, ?string $signature = null)
    {
        return $this->call('POST', '/api/v1/integrations/ota/events', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_INTEGRATION_EVENT_ID' => $eventId,
            'HTTP_X_INTEGRATION_SIGNATURE' => $signature ?? hash_hmac('sha256', $body, 'integration-secret'),
        ], $body);
    }
}

class RecordingIntegrationAdapter implements IntegrationAdapter
{
    public static array $processed = [];

    public static bool $fail = false;

    public function process(IntegrationEvent $event): void
    {
        if (self::$fail) {
            throw new RuntimeException('Adapter failure.');
        }

        self::$processed[] = $event->id;
    }
}
