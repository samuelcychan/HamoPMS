<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Property\Models\MaintenanceTicket;
use Modules\Property\Models\Property;
use Modules\Property\Models\Room;
use Modules\Property\Models\RoomType;
use Tests\TestCase;

class MaintenanceTicketTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $technician;

    private Property $property;

    private RoomType $roomType;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->manager = User::factory()->create();
        $this->technician = User::factory()->create();
        $this->property = Property::create([
            'name' => 'Maintenance Hotel',
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
        $this->room = $this->createRoom('101');
        $this->createRoom('102');
        $this->assignRole($this->manager, 'manager', $this->property);
        $this->assignRole($this->technician, 'housekeeping', $this->property);
    }

    public function test_ticket_crud_and_escalation_are_property_scoped_and_audited(): void
    {
        $create = $this->createTicket([
            'priority' => MaintenanceTicket::PRIORITY_HIGH,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', MaintenanceTicket::STATUS_OPEN)
            ->assertJsonPath('data.priority', MaintenanceTicket::PRIORITY_HIGH)
            ->assertJsonPath('data.room.status', Room::STATUS_OUT_OF_SERVICE);
        $ticketId = $create->json('data.id');

        $this->assertDatabaseHas('maintenance_ticket_histories', [
            'maintenance_ticket_id' => $ticketId,
            'event' => 'created',
            'from_status' => null,
            'to_status' => MaintenanceTicket::STATUS_OPEN,
            'changed_by' => $this->manager->id,
        ]);
        $this->assertDatabaseHas('room_status_histories', [
            'room_id' => $this->room->id,
            'from_status' => Room::STATUS_CLEAN,
            'to_status' => Room::STATUS_OUT_OF_SERVICE,
            'changed_by' => $this->manager->id,
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->putJson("{$this->ticketsUrl()}/{$ticketId}", [
                'title' => 'Air conditioner compressor failure',
                'priority' => MaintenanceTicket::PRIORITY_URGENT,
                'escalation_status' => MaintenanceTicket::ESCALATION_CRITICAL,
                'escalation_reason' => 'No replacement rooms remain.',
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Air conditioner compressor failure')
            ->assertJsonPath('data.priority', MaintenanceTicket::PRIORITY_URGENT)
            ->assertJsonPath('data.escalation_status', MaintenanceTicket::ESCALATION_CRITICAL)
            ->assertJsonPath('data.escalated_by', $this->manager->id);

        $this->actingAs($this->manager, 'sanctum')
            ->getJson($this->ticketsUrl().'?escalation_status=critical&priority=urgent')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ticketId);
        $this->actingAs($this->manager, 'sanctum')
            ->getJson("{$this->ticketsUrl()}/{$ticketId}")
            ->assertOk()
            ->assertJsonPath('data.room.number', '101');
        $this->actingAs($this->manager, 'sanctum')
            ->getJson("{$this->ticketsUrl()}/{$ticketId}/history")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.event', 'escalated');

        $this->actingAs($this->manager, 'sanctum')
            ->deleteJson("{$this->ticketsUrl()}/{$ticketId}")
            ->assertNoContent();

        $this->assertSoftDeleted('maintenance_tickets', ['id' => $ticketId]);
        $this->assertSame(Room::STATUS_CLEAN, $this->room->fresh()->status);
        $this->assertDatabaseHas('maintenance_ticket_histories', [
            'maintenance_ticket_id' => $ticketId,
            'event' => 'deleted',
            'changed_by' => $this->manager->id,
        ]);
    }

    public function test_assignment_start_and_resolution_restore_sellable_inventory(): void
    {
        $this->availability()->assertJsonPath('data.0.available_inventory', 2);
        $ticketId = $this->createTicket()->json('data.id');
        $this->availability()
            ->assertJsonPath('data.0.blocked_inventory', 1)
            ->assertJsonPath('data.0.available_inventory', 1);

        $this->actingAs($this->manager, 'sanctum')
            ->patchJson("{$this->ticketsUrl()}/{$ticketId}/assignment", [
                'assigned_to' => $this->technician->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', MaintenanceTicket::STATUS_ASSIGNED)
            ->assertJsonPath('data.assigned_to', $this->technician->id);
        $this->actingAs($this->technician, 'sanctum')
            ->postJson("{$this->ticketsUrl()}/{$ticketId}/start")
            ->assertOk()
            ->assertJsonPath('data.status', MaintenanceTicket::STATUS_IN_PROGRESS);
        $this->actingAs($this->technician, 'sanctum')
            ->postJson("{$this->ticketsUrl()}/{$ticketId}/resolve", [
                'resolution_notes' => 'Compressor replaced and room inspected.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', MaintenanceTicket::STATUS_RESOLVED)
            ->assertJsonPath('data.resolved_by', $this->technician->id)
            ->assertJsonPath('data.room.status', Room::STATUS_CLEAN);

        $this->availability()
            ->assertJsonPath('data.0.blocked_inventory', 0)
            ->assertJsonPath('data.0.available_inventory', 2);
        $this->assertDatabaseHas('room_status_histories', [
            'room_id' => $this->room->id,
            'from_status' => Room::STATUS_OUT_OF_SERVICE,
            'to_status' => Room::STATUS_CLEAN,
            'changed_by' => $this->technician->id,
            'reason' => "Resolved maintenance ticket {$ticketId}: Compressor replaced and room inspected.",
        ]);
        $this->assertSame(
            ['created', 'assigned', 'started', 'resolved'],
            MaintenanceTicket::findOrFail($ticketId)->history()->orderBy('id')->pluck('event')->all(),
        );
    }

    public function test_active_ticket_prevents_direct_room_restoration_and_duplicates(): void
    {
        $ticketId = $this->createTicket()->json('data.id');

        $this->actingAs($this->manager, 'sanctum')
            ->patchJson("/api/v1/properties/{$this->property->id}/rooms/{$this->room->id}/status", [
                'status' => Room::STATUS_CLEAN,
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['status']]]]);
        $this->createTicket()
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['room_id']]]]);

        $this->assertSame(Room::STATUS_OUT_OF_SERVICE, $this->room->fresh()->status);
        $this->assertDatabaseCount('maintenance_tickets', 1);
        $this->assertDatabaseHas('maintenance_tickets', ['id' => $ticketId]);
    }

    public function test_only_assigned_property_staff_can_advance_a_ticket(): void
    {
        $ticketId = $this->createTicket()->json('data.id');
        $other = User::factory()->create();
        $otherProperty = Property::create([
            'name' => 'Other Hotel',
            'address' => '2 Main Street',
            'type' => 'hotel',
        ]);
        $this->assignRole($other, 'housekeeping', $otherProperty);

        $this->actingAs($this->manager, 'sanctum')
            ->patchJson("{$this->ticketsUrl()}/{$ticketId}/assignment", ['assigned_to' => $other->id])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['assigned_to']]]]);
        $this->actingAs($this->manager, 'sanctum')
            ->patchJson("{$this->ticketsUrl()}/{$ticketId}/assignment", ['assigned_to' => $this->technician->id])
            ->assertOk();
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("{$this->ticketsUrl()}/{$ticketId}/start")
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['assigned_to']]]]);

        $this->assertSame(MaintenanceTicket::STATUS_ASSIGNED, MaintenanceTicket::findOrFail($ticketId)->status);
    }

    public function test_occupied_or_cleaning_rooms_cannot_be_taken_out_of_service_by_ticket(): void
    {
        foreach ([Room::STATUS_OCCUPIED, Room::STATUS_CLEANING] as $status) {
            $room = $this->createRoom('20'.$status, $status);

            $this->createTicket(['room_id' => $room->id])
                ->assertUnprocessable()
                ->assertJsonStructure(['error' => ['details' => ['fields' => ['room_id']]]]);
        }

        $this->assertDatabaseCount('maintenance_tickets', 0);
    }

    private function createTicket(array $overrides = [])
    {
        return $this->actingAs($this->manager, 'sanctum')->postJson($this->ticketsUrl(), [
            'room_id' => $this->room->id,
            'title' => 'Air conditioner failure',
            'description' => 'The unit does not cool the room.',
            ...$overrides,
        ]);
    }

    private function availability()
    {
        return $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/availability?'.http_build_query([
                'property_id' => $this->property->id,
                'check_in' => now()->addDay()->toDateString(),
                'check_out' => now()->addDays(2)->toDateString(),
                'occupancy' => 2,
            ]))
            ->assertOk();
    }

    private function createRoom(string $number, string $status = Room::STATUS_CLEAN): Room
    {
        return Room::create([
            'property_id' => $this->property->id,
            'room_type_id' => $this->roomType->id,
            'number' => $number,
            'status' => $status,
        ]);
    }

    private function ticketsUrl(): string
    {
        return "/api/v1/properties/{$this->property->id}/maintenance-tickets";
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
