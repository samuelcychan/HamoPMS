<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Property\Models\Amenity;
use Modules\Property\Models\Property;
use Modules\Property\Models\Room;
use Modules\Property\Models\RoomType;
use Tests\TestCase;

class RoomInventoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->user = User::factory()->create();
        $this->property = Property::create([
            'name' => 'Harbour Hotel',
            'address' => '1 Main Street',
            'type' => 'hotel',
        ]);
        RoleAssignment::create([
            'user_id' => $this->user->id,
            'role_id' => Role::where('slug', 'manager')->value('id'),
            'property_id' => $this->property->id,
        ]);
    }

    public function test_room_type_crud_is_available_within_a_property(): void
    {
        $create = $this->actingAs($this->user, 'sanctum')
            ->postJson($this->roomTypesUrl(), $this->roomTypePayload())
            ->assertCreated()
            ->assertJsonPath('data.code', 'DLX')
            ->assertJsonPath('data.max_occupancy', 3);

        $roomTypeId = $create->json('data.id');

        $this->actingAs($this->user, 'sanctum')
            ->getJson($this->roomTypesUrl())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.rooms_count', 0);

        $this->actingAs($this->user, 'sanctum')
            ->putJson("{$this->roomTypesUrl()}/{$roomTypeId}", ['name' => 'Deluxe Harbour View'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Deluxe Harbour View');

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("{$this->roomTypesUrl()}/{$roomTypeId}")
            ->assertNoContent();

        $this->assertSoftDeleted('room_types', ['id' => $roomTypeId]);
    }

    public function test_room_crud_initializes_clean_status_and_an_audit_record(): void
    {
        $roomType = $this->createRoomType();

        $create = $this->actingAs($this->user, 'sanctum')
            ->postJson($this->roomsUrl(), [
                'room_type_id' => $roomType->id,
                'number' => '101',
                'floor' => '1',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', Room::STATUS_CLEAN)
            ->assertJsonPath('data.room_type.id', $roomType->id);

        $roomId = $create->json('data.id');

        $this->assertDatabaseHas('room_status_histories', [
            'room_id' => $roomId,
            'from_status' => null,
            'to_status' => Room::STATUS_CLEAN,
            'changed_by' => $this->user->id,
            'reason' => 'Room created.',
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->putJson("{$this->roomsUrl()}/{$roomId}", ['number' => '102', 'floor' => '2'])
            ->assertOk()
            ->assertJsonPath('data.number', '102')
            ->assertJsonPath('data.floor', '2');

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("{$this->roomsUrl()}/{$roomId}")
            ->assertNoContent();

        $this->assertSoftDeleted('rooms', ['id' => $roomId]);
    }

    public function test_amenity_crud_and_room_assignment_are_property_scoped(): void
    {
        $room = $this->createRoom();
        $create = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/properties/{$this->property->id}/amenities", [
                'name' => 'Ocean View',
                'code' => 'OCEAN_VIEW',
                'description' => 'Unobstructed ocean view.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'OCEAN_VIEW')
            ->assertJsonPath('data.is_active', true);
        $amenityId = $create->json('data.id');

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/properties/{$this->property->id}/amenities/{$amenityId}")
            ->assertOk()
            ->assertJsonPath('data.rooms_count', 0);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/properties/{$this->property->id}/amenities")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.rooms_count', 0);

        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/v1/properties/{$this->property->id}/amenities/{$amenityId}", [
                'name' => 'Premium Ocean View',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Premium Ocean View');

        $this->actingAs($this->user, 'sanctum')
            ->putJson("{$this->roomsUrl()}/{$room->id}/amenities", ['amenity_ids' => [$amenityId]])
            ->assertOk()
            ->assertJsonPath('data.amenities.0.id', $amenityId);
        $this->assertDatabaseHas('amenity_room', ['amenity_id' => $amenityId, 'room_id' => $room->id]);

        $otherProperty = Property::create([
            'name' => 'Other Hotel',
            'address' => '2 Main Street',
            'type' => 'hotel',
        ]);
        $otherAmenity = Amenity::create([
            'property_id' => $otherProperty->id,
            'name' => 'Private Pool',
            'code' => 'PRIVATE_POOL',
        ]);
        $this->actingAs($this->user, 'sanctum')
            ->putJson("{$this->roomsUrl()}/{$room->id}/amenities", [
                'amenity_ids' => [$otherAmenity->id],
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['amenity_ids.0']]]]);

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/properties/{$this->property->id}/amenities/{$amenityId}")
            ->assertNoContent();
        $this->assertSoftDeleted('amenities', ['id' => $amenityId]);
        $this->assertDatabaseMissing('amenity_room', ['amenity_id' => $amenityId, 'room_id' => $room->id]);
    }

    public function test_room_type_must_belong_to_the_same_property_as_the_room(): void
    {
        $otherProperty = Property::create([
            'name' => 'Other Hotel',
            'address' => '2 Main Street',
            'type' => 'hotel',
        ]);
        $otherRoomType = RoomType::create([
            ...$this->roomTypePayload(),
            'property_id' => $otherProperty->id,
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson($this->roomsUrl(), [
                'room_type_id' => $otherRoomType->id,
                'number' => '101',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['room_type_id']]]]);
    }

    public function test_valid_status_transition_is_audited_with_actor_and_reason(): void
    {
        $room = $this->createRoom();

        $this->actingAs($this->user, 'sanctum')
            ->patchJson("{$this->roomsUrl()}/{$room->id}/status", [
                'status' => Room::STATUS_OUT_OF_SERVICE,
                'reason' => 'Air conditioner repair.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Room::STATUS_OUT_OF_SERVICE);

        $this->assertDatabaseHas('room_status_histories', [
            'room_id' => $room->id,
            'from_status' => Room::STATUS_CLEAN,
            'to_status' => Room::STATUS_OUT_OF_SERVICE,
            'changed_by' => $this->user->id,
            'reason' => 'Air conditioner repair.',
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("{$this->roomsUrl()}/{$room->id}/status-history")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.to_status', Room::STATUS_OUT_OF_SERVICE)
            ->assertJsonPath('meta.pagination.total', 2);
    }

    public function test_invalid_status_transition_is_rejected_without_an_audit_record(): void
    {
        $room = $this->createRoom();

        $this->actingAs($this->user, 'sanctum')
            ->patchJson("{$this->roomsUrl()}/{$room->id}/status", [
                'status' => Room::STATUS_CLEAN,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['status']]]]);

        $this->assertDatabaseCount('room_status_histories', 1);
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'status' => Room::STATUS_CLEAN]);
    }

    public function test_out_of_service_status_requires_an_audit_reason(): void
    {
        $room = $this->createRoom();

        $this->actingAs($this->user, 'sanctum')
            ->patchJson("{$this->roomsUrl()}/{$room->id}/status", [
                'status' => Room::STATUS_OUT_OF_SERVICE,
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['reason']]]]);
    }

    public function test_room_type_with_rooms_cannot_be_deleted(): void
    {
        $room = $this->createRoom();

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("{$this->roomTypesUrl()}/{$room->room_type_id}")
            ->assertConflict()
            ->assertJsonPath('error.code', 'ROOM_TYPE_IN_USE');

        $this->assertDatabaseHas('room_types', [
            'id' => $room->room_type_id,
            'deleted_at' => null,
        ]);
    }

    private function createRoomType(): RoomType
    {
        return RoomType::create([
            ...$this->roomTypePayload(),
            'property_id' => $this->property->id,
        ]);
    }

    private function createRoom(): Room
    {
        $roomType = $this->createRoomType();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson($this->roomsUrl(), [
                'room_type_id' => $roomType->id,
                'number' => '101',
            ])
            ->assertCreated();

        return Room::findOrFail($response->json('data.id'));
    }

    private function roomTypePayload(): array
    {
        return [
            'name' => 'Deluxe Room',
            'code' => 'DLX',
            'description' => 'Harbour view room.',
            'max_occupancy' => 3,
            'base_rate' => '250.00',
            'is_active' => true,
        ];
    }

    private function roomTypesUrl(): string
    {
        return "/api/v1/properties/{$this->property->id}/room-types";
    }

    private function roomsUrl(): string
    {
        return "/api/v1/properties/{$this->property->id}/rooms";
    }
}
