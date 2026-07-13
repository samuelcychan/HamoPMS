<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Modules\Booking\Events\EarlyDepartureScheduled;
use Modules\Booking\Events\ReservationRoomMoved;
use Modules\Booking\Events\StayExtended;
use Modules\Booking\Models\Booking;
use Modules\Booking\Models\BookingModification;
use Modules\Folio\Models\Folio;
use Modules\Property\Models\Property;
use Modules\Property\Models\Room;
use Modules\Property\Models\RoomType;
use Tests\TestCase;

class InStayModificationTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $guest;

    private Property $property;

    private RoomType $standard;

    private RoomType $deluxe;

    private Room $currentRoom;

    private Room $deluxeRoom;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-10 10:00:00');
        CarbonImmutable::setTestNow('2026-07-10 10:00:00');
        $this->seed(RolePermissionSeeder::class);
        $this->staff = User::factory()->create();
        $this->guest = User::factory()->create();
        $this->property = Property::create([
            'name' => 'Harbour Hotel',
            'address' => '1 Main Street',
            'type' => 'hotel',
        ]);
        $this->standard = $this->createRoomType('STD', '100.00', 2);
        $this->deluxe = $this->createRoomType('DLX', '150.00', 4);
        $this->currentRoom = $this->createRoom($this->standard, '101', Room::STATUS_OCCUPIED);
        $this->deluxeRoom = $this->createRoom($this->deluxe, '201', Room::STATUS_CLEAN);
        RoleAssignment::create([
            'user_id' => $this->staff->id,
            'role_id' => Role::where('slug', 'receptionist')->value('id'),
            'property_id' => $this->property->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_room_upgrade_moves_inventory_reprices_remaining_nights_and_emits_event(): void
    {
        Event::fake([ReservationRoomMoved::class]);
        Log::spy();
        $booking = $this->createCheckedInBooking();

        $response = $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/room-move", [
                'room_id' => $this->deluxeRoom->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.room_id', $this->deluxeRoom->id)
            ->assertJsonPath('data.room_type_id', $this->deluxe->id)
            ->assertJsonPath('data.nightly_rate', '150.00')
            ->assertJsonPath('meta.stay_change.type', 'room_move')
            ->assertJsonPath('meta.stay_change.rate_difference', '150.00');

        $modification = BookingModification::findOrFail(
            $response->json('meta.stay_change.modification_id'),
        );
        $this->assertSame($this->currentRoom->id, $modification->before['room_id']);
        $this->assertSame($this->deluxeRoom->id, $modification->after['room_id']);
        $this->assertSame('150.00', $modification->rate_difference);
        $this->assertDatabaseHas('rooms', [
            'id' => $this->currentRoom->id,
            'status' => Room::STATUS_DIRTY,
        ]);
        $this->assertDatabaseHas('rooms', [
            'id' => $this->deluxeRoom->id,
            'status' => Room::STATUS_OCCUPIED,
        ]);
        $this->assertDatabaseHas('room_status_histories', [
            'room_id' => $this->currentRoom->id,
            'from_status' => Room::STATUS_OCCUPIED,
            'to_status' => Room::STATUS_DIRTY,
            'changed_by' => $this->staff->id,
        ]);
        $this->assertDatabaseHas('room_status_histories', [
            'room_id' => $this->deluxeRoom->id,
            'from_status' => Room::STATUS_CLEAN,
            'to_status' => Room::STATUS_OCCUPIED,
            'changed_by' => $this->staff->id,
        ]);
        $this->assertSame(
            '550.00',
            Folio::where('booking_id', $booking->id)->firstOrFail()->balance(),
        );
        Event::assertDispatched(
            ReservationRoomMoved::class,
            fn (ReservationRoomMoved $event): bool => $event->booking->is($booking)
                && $event->fromRoomId === $this->currentRoom->id
                && $event->toRoomId === $this->deluxeRoom->id
                && $event->rateDifference === '150.00',
        );
        Log::shouldHaveReceived('info')->with('stay.room_moved', [
            'actor_id' => $this->staff->id,
            'booking_id' => $booking->id,
            'property_id' => $this->property->id,
            'from_room_id' => $this->currentRoom->id,
            'to_room_id' => $this->deluxeRoom->id,
            'rate_difference' => '150.00',
        ])->once();
    }

    public function test_room_downgrade_posts_a_negative_remaining_night_adjustment(): void
    {
        $booking = $this->createCheckedInBooking([
            'room_type_id' => $this->deluxe->id,
            'room_id' => $this->deluxeRoom->id,
            'nightly_rate' => '150.00',
        ]);
        $this->deluxeRoom->update(['status' => Room::STATUS_OCCUPIED]);
        $this->currentRoom->update(['status' => Room::STATUS_CLEAN]);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/room-move", [
                'room_id' => $this->currentRoom->id,
            ])
            ->assertOk()
            ->assertJsonPath('meta.stay_change.rate_difference', '-150.00');

        $this->assertSame(
            '450.00',
            Folio::where('booking_id', $booking->id)->firstOrFail()->balance(),
        );
    }

    public function test_invalid_destination_rolls_back_both_room_states(): void
    {
        Event::fake([ReservationRoomMoved::class]);
        $booking = $this->createCheckedInBooking();
        $this->deluxeRoom->update(['status' => Room::STATUS_DIRTY]);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/room-move", [
                'room_id' => $this->deluxeRoom->id,
            ])
            ->assertUnprocessable();

        $this->assertSame(Room::STATUS_OCCUPIED, $this->currentRoom->fresh()->status);
        $this->assertSame(Room::STATUS_DIRTY, $this->deluxeRoom->fresh()->status);
        $this->assertDatabaseCount('booking_modifications', 0);
        $this->assertDatabaseCount('room_status_histories', 0);
        Event::assertNotDispatched(ReservationRoomMoved::class);
    }

    public function test_room_move_rejects_a_destination_type_reserved_for_the_remaining_stay(): void
    {
        Event::fake([ReservationRoomMoved::class]);
        $booking = $this->createCheckedInBooking();
        Booking::create([
            'user_id' => User::factory()->create()->id,
            'property_id' => $this->property->id,
            'room_type_id' => $this->deluxe->id,
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(4)->toDateString(),
            'guests' => 1,
            'status' => Booking::STATUS_CONFIRMED,
        ]);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/room-move", [
                'room_id' => $this->deluxeRoom->id,
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'INVENTORY_UNAVAILABLE');

        $this->assertSame($this->currentRoom->id, $booking->fresh()->room_id);
        $this->assertSame(Room::STATUS_OCCUPIED, $this->currentRoom->fresh()->status);
        $this->assertSame(Room::STATUS_CLEAN, $this->deluxeRoom->fresh()->status);
        $this->assertDatabaseCount('booking_modifications', 0);
        $this->assertDatabaseCount('room_status_histories', 0);
        $this->assertDatabaseMissing('folios', ['booking_id' => $booking->id]);
        Event::assertNotDispatched(ReservationRoomMoved::class);
    }

    public function test_room_move_rejects_a_destination_that_cannot_fit_the_party(): void
    {
        Event::fake([ReservationRoomMoved::class]);
        $booking = $this->createCheckedInBooking();
        $singleType = $this->createRoomType('SGL', '80.00', 1);
        $singleRoom = $this->createRoom($singleType, '301', Room::STATUS_CLEAN);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/room-move", [
                'room_id' => $singleRoom->id,
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['room_id']]]]);

        $this->assertSame($this->currentRoom->id, $booking->fresh()->room_id);
        $this->assertSame(Room::STATUS_OCCUPIED, $this->currentRoom->fresh()->status);
        $this->assertSame(Room::STATUS_CLEAN, $singleRoom->fresh()->status);
        $this->assertDatabaseCount('booking_modifications', 0);
        $this->assertDatabaseCount('room_status_histories', 0);
        $this->assertDatabaseMissing('folios', ['booking_id' => $booking->id]);
        Event::assertNotDispatched(ReservationRoomMoved::class);
    }

    public function test_stay_extension_checks_inventory_reprices_and_emits_event(): void
    {
        Event::fake([StayExtended::class]);
        $booking = $this->createCheckedInBooking();

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/departure-adjustment", [
                'check_out' => now()->addDays(5)->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('meta.stay_change.type', 'extension')
            ->assertJsonPath('meta.stay_change.rate_difference', '200.00');

        $this->assertSame(now()->addDays(5)->toDateString(), $booking->fresh()->check_out->toDateString());
        $this->assertSame(
            '600.00',
            Folio::where('booking_id', $booking->id)->firstOrFail()->balance(),
        );
        Event::assertDispatched(
            StayExtended::class,
            fn (StayExtended $event): bool => $event->booking->is($booking)
                && $event->previousCheckOut === now()->addDays(3)->toDateString()
                && $event->rateDifference === '200.00',
        );
    }

    public function test_extension_conflict_does_not_change_the_stay(): void
    {
        Event::fake([StayExtended::class]);
        $booking = $this->createCheckedInBooking();
        Booking::create([
            'user_id' => User::factory()->create()->id,
            'property_id' => $this->property->id,
            'room_type_id' => $this->standard->id,
            'check_in' => now()->addDays(3)->toDateString(),
            'check_out' => now()->addDays(6)->toDateString(),
            'guests' => 1,
            'status' => Booking::STATUS_CONFIRMED,
        ]);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/departure-adjustment", [
                'check_out' => now()->addDays(5)->toDateString(),
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'INVENTORY_UNAVAILABLE');

        $this->assertSame(now()->addDays(3)->toDateString(), $booking->fresh()->check_out->toDateString());
        $this->assertDatabaseCount('booking_modifications', 0);
        $this->assertDatabaseMissing('folios', ['booking_id' => $booking->id]);
        Event::assertNotDispatched(StayExtended::class);
    }

    public function test_early_departure_removes_unused_nights_and_emits_event(): void
    {
        Event::fake([EarlyDepartureScheduled::class]);
        $booking = $this->createCheckedInBooking();

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/departure-adjustment", [
                'check_out' => now()->addDay()->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('meta.stay_change.type', 'early_departure')
            ->assertJsonPath('meta.stay_change.rate_difference', '-200.00');

        $this->assertSame(
            '200.00',
            Folio::where('booking_id', $booking->id)->firstOrFail()->balance(),
        );
        Event::assertDispatched(
            EarlyDepartureScheduled::class,
            fn (EarlyDepartureScheduled $event): bool => $event->booking->is($booking)
                && $event->previousCheckOut === now()->addDays(3)->toDateString()
                && $event->rateDifference === '-200.00',
        );
    }

    public function test_only_checked_in_stays_can_use_in_stay_operations(): void
    {
        Event::fake([
            ReservationRoomMoved::class,
            StayExtended::class,
            EarlyDepartureScheduled::class,
        ]);
        $booking = $this->createCheckedInBooking([
            'status' => Booking::STATUS_CONFIRMED,
            'room_id' => null,
        ]);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/departure-adjustment", [
                'check_out' => now()->addDays(4)->toDateString(),
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['status']]]]);

        Event::assertNothingDispatched();
    }

    private function createCheckedInBooking(array $overrides = []): Booking
    {
        return Booking::create([
            'user_id' => $this->guest->id,
            'property_id' => $this->property->id,
            'room_type_id' => $this->standard->id,
            'room_id' => $this->currentRoom->id,
            'check_in' => now()->subDay()->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'guests' => 2,
            'status' => Booking::STATUS_CHECKED_IN,
            'checked_in_at' => now()->subDay(),
            'checked_in_by' => $this->staff->id,
            'nightly_rate' => '100.00',
            ...$overrides,
        ]);
    }

    private function createRoomType(string $code, string $rate, int $occupancy): RoomType
    {
        return RoomType::create([
            'property_id' => $this->property->id,
            'name' => "Room {$code}",
            'code' => $code,
            'max_occupancy' => $occupancy,
            'base_rate' => $rate,
            'is_active' => true,
        ]);
    }

    private function createRoom(RoomType $roomType, string $number, string $status): Room
    {
        return Room::create([
            'property_id' => $this->property->id,
            'room_type_id' => $roomType->id,
            'number' => $number,
            'status' => $status,
        ]);
    }
}
