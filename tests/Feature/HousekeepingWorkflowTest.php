<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Booking\Models\Booking;
use Modules\Folio\Models\Folio;
use Modules\Folio\Models\FolioLineItem;
use Modules\Payment\Models\Payment;
use Modules\Property\Models\HousekeepingTask;
use Modules\Property\Models\Property;
use Modules\Property\Models\Room;
use Modules\Property\Models\RoomType;
use Tests\TestCase;

class HousekeepingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $receptionist;

    private User $housekeeper;

    private Property $property;

    private RoomType $roomType;

    private Room $room;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-13 10:00:00');
        CarbonImmutable::setTestNow('2026-07-13 10:00:00');
        config()->set('housekeeping.turnaround_minutes', 90);
        config()->set('checkout.late_fee_enabled', false);
        $this->seed(RolePermissionSeeder::class);
        $this->receptionist = User::factory()->create();
        $this->housekeeper = User::factory()->create();
        $guest = User::factory()->create();
        $this->property = Property::create([
            'name' => 'Housekeeping Hotel',
            'address' => '1 Main Street',
            'type' => 'hotel',
        ]);
        $this->roomType = RoomType::create([
            'property_id' => $this->property->id,
            'name' => 'Standard Room',
            'code' => 'STD',
            'max_occupancy' => 2,
            'base_rate' => '100.00',
            'is_active' => true,
        ]);
        $this->room = Room::create([
            'property_id' => $this->property->id,
            'room_type_id' => $this->roomType->id,
            'number' => '101',
            'status' => Room::STATUS_OCCUPIED,
        ]);
        $this->booking = Booking::create([
            'user_id' => $guest->id,
            'property_id' => $this->property->id,
            'room_type_id' => $this->roomType->id,
            'room_id' => $this->room->id,
            'check_in' => '2026-07-12',
            'check_out' => '2026-07-13',
            'guests' => 1,
            'status' => Booking::STATUS_CHECKED_IN,
            'checked_in_at' => now()->subDay(),
            'checked_in_by' => $this->receptionist->id,
            'nightly_rate' => '100.00',
        ]);
        $this->assignRole($this->receptionist, 'receptionist', $this->property);
        $this->assignRole($this->housekeeper, 'housekeeping', $this->property);
        $folio = Folio::create([
            'booking_id' => $this->booking->id,
            'status' => Folio::STATUS_OPEN,
            'currency' => 'USD',
        ]);
        $folio->lineItems()->create([
            'type' => FolioLineItem::TYPE_ROOM_RATE,
            'description' => 'Stay charge',
            'amount' => '100.00',
        ]);
        Payment::create([
            'user_id' => $this->receptionist->id,
            'booking_id' => $this->booking->id,
            'amount' => '100.00',
            'currency' => 'USD',
            'method' => 'cash',
            'status' => Payment::STATUS_COMPLETED,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_checkout_creates_a_pending_turnaround_task(): void
    {
        $taskId = $this->checkOut()
            ->assertOk()
            ->assertJsonPath('data.status', Booking::STATUS_COMPLETED)
            ->json('meta.checkout.housekeeping_task_id');

        $this->assertDatabaseHas('housekeeping_tasks', [
            'id' => $taskId,
            'property_id' => $this->property->id,
            'room_id' => $this->room->id,
            'booking_id' => $this->booking->id,
            'created_by' => $this->receptionist->id,
            'status' => HousekeepingTask::STATUS_PENDING,
            'priority' => HousekeepingTask::PRIORITY_NORMAL,
            'due_at' => '2026-07-13 11:30:00',
        ]);
        $this->assertSame(Room::STATUS_DIRTY, $this->room->fresh()->status);

        $this->actingAs($this->housekeeper, 'sanctum')
            ->getJson($this->tasksUrl().'?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $taskId)
            ->assertJsonPath('data.0.room.number', '101');
    }

    public function test_failed_checkout_does_not_create_a_housekeeping_task(): void
    {
        Payment::query()->update(['status' => Payment::STATUS_PENDING]);

        $this->checkOut()->assertConflict();

        $this->assertDatabaseCount('housekeeping_tasks', 0);
        $this->assertSame(Booking::STATUS_CHECKED_IN, $this->booking->fresh()->status);
        $this->assertSame(Room::STATUS_OCCUPIED, $this->room->fresh()->status);
    }

    public function test_assigned_housekeeper_cleans_room_and_makes_it_check_in_ready(): void
    {
        $taskId = $this->checkOut()->json('meta.checkout.housekeeping_task_id');
        $this->actingAs($this->receptionist, 'sanctum')
            ->patchJson("{$this->tasksUrl()}/{$taskId}/assignment", [
                'assigned_to' => $this->housekeeper->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', HousekeepingTask::STATUS_ASSIGNED)
            ->assertJsonPath('data.assigned_to', $this->housekeeper->id)
            ->assertJsonPath('data.assigned_by', $this->receptionist->id);

        $this->actingAs($this->housekeeper, 'sanctum')
            ->postJson("{$this->tasksUrl()}/{$taskId}/start")
            ->assertOk()
            ->assertJsonPath('data.status', HousekeepingTask::STATUS_IN_PROGRESS)
            ->assertJsonPath('data.room.status', Room::STATUS_CLEANING);
        $this->assertDatabaseHas('room_status_histories', [
            'room_id' => $this->room->id,
            'from_status' => Room::STATUS_DIRTY,
            'to_status' => Room::STATUS_CLEANING,
            'changed_by' => $this->housekeeper->id,
            'reason' => "Started housekeeping task {$taskId}.",
        ]);

        $this->actingAs($this->housekeeper, 'sanctum')
            ->postJson("{$this->tasksUrl()}/{$taskId}/complete", [
                'completion_notes' => 'Room inspected and stocked.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', HousekeepingTask::STATUS_COMPLETED)
            ->assertJsonPath('data.completed_by', $this->housekeeper->id)
            ->assertJsonPath('data.completion_notes', 'Room inspected and stocked.')
            ->assertJsonPath('data.room.status', Room::STATUS_CLEAN);
        $this->assertDatabaseHas('room_status_histories', [
            'room_id' => $this->room->id,
            'from_status' => Room::STATUS_CLEANING,
            'to_status' => Room::STATUS_CLEAN,
            'changed_by' => $this->housekeeper->id,
            'reason' => "Completed housekeeping task {$taskId}.",
        ]);

        $nextGuest = User::factory()->create();
        $nextBooking = Booking::create([
            'user_id' => $nextGuest->id,
            'property_id' => $this->property->id,
            'room_type_id' => $this->roomType->id,
            'check_in' => '2026-07-13',
            'check_out' => '2026-07-14',
            'guests' => 1,
            'status' => Booking::STATUS_CONFIRMED,
        ]);
        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/v1/bookings/{$nextBooking->id}/check-in", ['room_id' => $this->room->id])
            ->assertOk()
            ->assertJsonPath('data.status', Booking::STATUS_CHECKED_IN)
            ->assertJsonPath('data.room.status', Room::STATUS_OCCUPIED);
    }

    public function test_task_order_and_assignee_are_enforced(): void
    {
        $taskId = $this->checkOut()->json('meta.checkout.housekeeping_task_id');

        $this->actingAs($this->housekeeper, 'sanctum')
            ->postJson("{$this->tasksUrl()}/{$taskId}/start")
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['assigned_to']]]]);
        $this->actingAs($this->receptionist, 'sanctum')
            ->patchJson("{$this->tasksUrl()}/{$taskId}/assignment", [
                'assigned_to' => $this->housekeeper->id,
            ])->assertOk();
        $this->actingAs($this->housekeeper, 'sanctum')
            ->postJson("{$this->tasksUrl()}/{$taskId}/complete")
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['task']]]]);

        $otherHousekeeper = User::factory()->create();
        $this->assignRole($otherHousekeeper, 'housekeeping', $this->property);
        $this->actingAs($otherHousekeeper, 'sanctum')
            ->postJson("{$this->tasksUrl()}/{$taskId}/start")
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['assigned_to']]]]);

        $this->assertSame(HousekeepingTask::STATUS_ASSIGNED, HousekeepingTask::findOrFail($taskId)->status);
        $this->assertSame(Room::STATUS_DIRTY, $this->room->fresh()->status);
    }

    public function test_direct_room_updates_cannot_bypass_housekeeping_transitions(): void
    {
        $this->checkOut();

        foreach ([Room::STATUS_CLEANING, Room::STATUS_CLEAN] as $status) {
            $this->actingAs($this->receptionist, 'sanctum')
                ->patchJson("/api/v1/properties/{$this->property->id}/rooms/{$this->room->id}/status", [
                    'status' => $status,
                ])
                ->assertUnprocessable()
                ->assertJsonStructure(['error' => ['details' => ['fields' => ['status']]]]);
        }

        $this->assertSame(Room::STATUS_DIRTY, $this->room->fresh()->status);
    }

    public function test_assignee_must_have_access_to_the_task_property(): void
    {
        $taskId = $this->checkOut()->json('meta.checkout.housekeeping_task_id');
        $otherProperty = Property::create([
            'name' => 'Other Hotel',
            'address' => '2 Main Street',
            'type' => 'hotel',
        ]);
        $otherHousekeeper = User::factory()->create();
        $this->assignRole($otherHousekeeper, 'housekeeping', $otherProperty);

        $this->actingAs($this->receptionist, 'sanctum')
            ->patchJson("{$this->tasksUrl()}/{$taskId}/assignment", [
                'assigned_to' => $otherHousekeeper->id,
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['assigned_to']]]]);
    }

    private function checkOut()
    {
        return $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/v1/bookings/{$this->booking->id}/check-out");
    }

    private function tasksUrl(): string
    {
        return "/api/v1/properties/{$this->property->id}/housekeeping-tasks";
    }

    private function assignRole(User $user, string $role, Property $property): void
    {
        RoleAssignment::create([
            'user_id' => $user->id,
            'role_id' => Role::where('slug', $role)->value('id'),
            'property_id' => $property->id,
        ]);
    }
}
