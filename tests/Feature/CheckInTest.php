<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Booking\Events\ReservationCheckedIn;
use Modules\Booking\Models\Booking;
use Modules\Property\Models\Property;
use Modules\Property\Models\Room;
use Modules\Property\Models\RoomType;
use Tests\TestCase;

class CheckInTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $guest;

    private Property $property;

    private RoomType $roomType;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->staff = User::factory()->create();
        $this->guest = User::factory()->create();
        $this->property = Property::create([
            'name' => 'Harbour Hotel',
            'address' => '1 Main Street',
            'type' => 'hotel',
        ]);
        $this->roomType = RoomType::create([
            'property_id' => $this->property->id,
            'name' => 'Deluxe Room',
            'code' => 'DLX',
            'max_occupancy' => 3,
            'base_rate' => '250.00',
            'is_active' => true,
        ]);
        $this->room = Room::create([
            'property_id' => $this->property->id,
            'room_type_id' => $this->roomType->id,
            'number' => '101',
            'status' => Room::STATUS_CLEAN,
        ]);

        $this->assignRole($this->staff, 'receptionist');
    }

    public function test_receptionist_can_check_in_a_confirmed_reservation_to_a_ready_room(): void
    {
        Event::fake([ReservationCheckedIn::class]);
        $booking = $this->createBooking();

        $this->actingAs($this->staff, 'sanctum')
            ->postJson($this->checkInUrl($booking), ['room_id' => $this->room->id])
            ->assertOk()
            ->assertJsonPath('data.id', $booking->id)
            ->assertJsonPath('data.status', Booking::STATUS_CHECKED_IN)
            ->assertJsonPath('data.room_id', $this->room->id)
            ->assertJsonPath('data.checked_in_by', $this->staff->id)
            ->assertJsonPath('data.room.id', $this->room->id)
            ->assertJsonPath('data.checked_in_by_user.id', $this->staff->id)
            ->assertJsonPath('error', null);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'room_id' => $this->room->id,
            'status' => Booking::STATUS_CHECKED_IN,
            'checked_in_by' => $this->staff->id,
        ]);
        $this->assertNotNull($booking->fresh()->checked_in_at);
        $this->assertDatabaseHas('rooms', [
            'id' => $this->room->id,
            'status' => Room::STATUS_OCCUPIED,
        ]);
        $this->assertDatabaseHas('room_status_histories', [
            'room_id' => $this->room->id,
            'from_status' => Room::STATUS_CLEAN,
            'to_status' => Room::STATUS_OCCUPIED,
            'changed_by' => $this->staff->id,
            'reason' => "Checked in reservation {$booking->id}.",
        ]);
        Event::assertDispatched(
            ReservationCheckedIn::class,
            fn (ReservationCheckedIn $event): bool => $event->booking->is($booking)
                && $event->booking->status === Booking::STATUS_CHECKED_IN,
        );
    }

    public function test_non_confirmed_reservation_cannot_be_checked_in_twice(): void
    {
        Event::fake([ReservationCheckedIn::class]);
        $booking = $this->createBooking();

        $this->actingAs($this->staff, 'sanctum')
            ->postJson($this->checkInUrl($booking), ['room_id' => $this->room->id])
            ->assertOk();

        $this->actingAs($this->staff, 'sanctum')
            ->postJson($this->checkInUrl($booking), ['room_id' => $this->room->id])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['status']]]]);

        Event::assertDispatchedTimes(ReservationCheckedIn::class, 1);
        $this->assertDatabaseCount('room_status_histories', 1);
    }

    public function test_room_must_be_clean_and_ready(): void
    {
        Event::fake([ReservationCheckedIn::class]);
        $booking = $this->createBooking();
        $this->room->update(['status' => Room::STATUS_DIRTY]);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson($this->checkInUrl($booking), ['room_id' => $this->room->id])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['room_id']]]]);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => Booking::STATUS_CONFIRMED,
            'room_id' => null,
        ]);
        Event::assertNotDispatched(ReservationCheckedIn::class);
    }

    public function test_room_must_match_the_reservations_property_and_room_type(): void
    {
        Event::fake([ReservationCheckedIn::class]);
        $booking = $this->createBooking();
        $otherRoomType = RoomType::create([
            'property_id' => $this->property->id,
            'name' => 'Standard Room',
            'code' => 'STD',
            'max_occupancy' => 2,
            'base_rate' => '150.00',
            'is_active' => true,
        ]);
        $otherRoom = Room::create([
            'property_id' => $this->property->id,
            'room_type_id' => $otherRoomType->id,
            'number' => '102',
            'status' => Room::STATUS_CLEAN,
        ]);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson($this->checkInUrl($booking), ['room_id' => $otherRoom->id])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => Booking::STATUS_CONFIRMED,
            'room_id' => null,
        ]);
        Event::assertNotDispatched(ReservationCheckedIn::class);
    }

    public function test_reservation_cannot_be_checked_in_before_arrival(): void
    {
        Event::fake([ReservationCheckedIn::class]);
        $booking = $this->createBooking([
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(2)->toDateString(),
        ]);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson($this->checkInUrl($booking), ['room_id' => $this->room->id])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['check_in']]]]);

        Event::assertNotDispatched(ReservationCheckedIn::class);
    }

    public function test_user_without_reservation_write_permission_is_forbidden(): void
    {
        Event::fake([ReservationCheckedIn::class]);
        $booking = $this->createBooking();
        $housekeeper = User::factory()->create();
        $this->assignRole($housekeeper, 'housekeeping');

        $this->actingAs($housekeeper, 'sanctum')
            ->postJson($this->checkInUrl($booking), ['room_id' => $this->room->id])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => Booking::STATUS_CONFIRMED,
            'room_id' => null,
        ]);
        Event::assertNotDispatched(ReservationCheckedIn::class);
    }

    private function createBooking(array $overrides = []): Booking
    {
        return Booking::create([
            'user_id' => $this->guest->id,
            'property_id' => $this->property->id,
            'room_type_id' => $this->roomType->id,
            'check_in' => now()->toDateString(),
            'check_out' => now()->addDays(2)->toDateString(),
            'guests' => 2,
            'status' => Booking::STATUS_CONFIRMED,
            ...$overrides,
        ]);
    }

    private function assignRole(User $user, string $role): void
    {
        RoleAssignment::create([
            'user_id' => $user->id,
            'role_id' => Role::where('slug', $role)->value('id'),
            'property_id' => $this->property->id,
        ]);
    }

    private function checkInUrl(Booking $booking): string
    {
        return "/api/v1/bookings/{$booking->id}/check-in";
    }
}
