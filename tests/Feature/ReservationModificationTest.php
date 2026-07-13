<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Modules\Booking\Models\Booking;
use Modules\Booking\Models\BookingModification;
use Modules\Folio\Models\Folio;
use Modules\Folio\Models\FolioLineItem;
use Modules\Property\Models\Property;
use Modules\Property\Models\Room;
use Modules\Property\Models\RoomType;
use Tests\TestCase;

class ReservationModificationTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $guest;

    private Property $property;

    private RoomType $standard;

    private RoomType $deluxe;

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
        $this->standard = $this->createRoomType('STD', '100.00', 2);
        $this->deluxe = $this->createRoomType('DLX', '150.00', 4);
        $this->createRoom($this->standard, '101');
        $this->createRoom($this->deluxe, '201');
        RoleAssignment::create([
            'user_id' => $this->staff->id,
            'role_id' => Role::where('slug', 'receptionist')->value('id'),
            'property_id' => $this->property->id,
        ]);
    }

    public function test_staff_can_modify_dates_room_type_occupancy_and_requests_with_rate_delta(): void
    {
        Log::spy();
        $booking = $this->createBooking();

        $response = $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}", [
                'room_type_id' => $this->deluxe->id,
                'check_out' => now()->addDays(4)->toDateString(),
                'occupancy' => 3,
                'notes' => 'Late arrival',
                'special_requests' => ['High floor', 'Feather-free room'],
            ])
            ->assertOk()
            ->assertJsonPath('data.room_type_id', $this->deluxe->id)
            ->assertJsonPath('data.nightly_rate', '150.00')
            ->assertJsonPath('data.guests', 3)
            ->assertJsonPath('data.notes', 'Late arrival')
            ->assertJsonPath('data.special_requests.0', 'High floor')
            ->assertJsonPath('meta.modification.rate_difference', '250.00');

        $modificationId = $response->json('meta.modification.id');
        $modification = BookingModification::findOrFail($modificationId);
        $this->assertSame($this->standard->id, $modification->before['room_type_id']);
        $this->assertSame($this->deluxe->id, $modification->after['room_type_id']);
        $this->assertSame(2, $modification->before['guests']);
        $this->assertSame(3, $modification->after['guests']);
        $this->assertSame('250.00', $modification->rate_difference);

        $folio = Folio::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame('450.00', $folio->balance());
        $this->assertDatabaseHas('folio_line_items', [
            'folio_id' => $folio->id,
            'type' => FolioLineItem::TYPE_ROOM_RATE,
            'amount' => '200.00',
        ]);
        $this->assertDatabaseHas('folio_line_items', [
            'folio_id' => $folio->id,
            'type' => FolioLineItem::TYPE_ROOM_RATE_ADJUSTMENT,
            'amount' => '250.00',
        ]);

        Log::shouldHaveReceived('info')->with('reservation.modified', [
            'actor_id' => $this->staff->id,
            'booking_id' => $booking->id,
            'property_id' => $this->property->id,
            'modification_id' => $modification->id,
            'rate_difference' => '250.00',
        ])->once();
    }

    public function test_shorter_stay_posts_a_negative_rate_adjustment(): void
    {
        $booking = $this->createBooking([
            'check_out' => now()->addDays(4)->toDateString(),
        ]);

        $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}", [
                'check_out' => now()->addDays(2)->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('meta.modification.rate_difference', '-200.00');

        $folio = Folio::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame('100.00', $folio->balance());
        $this->assertDatabaseHas('folio_line_items', [
            'folio_id' => $folio->id,
            'type' => FolioLineItem::TYPE_ROOM_RATE_ADJUSTMENT,
            'amount' => '-200.00',
        ]);
    }

    public function test_modification_rejects_unavailable_inventory_without_partial_changes(): void
    {
        $booking = $this->createBooking();
        $this->createBooking([
            'user_id' => User::factory()->create()->id,
            'room_type_id' => $this->deluxe->id,
        ]);

        $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}", [
                'room_type_id' => $this->deluxe->id,
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'INVENTORY_UNAVAILABLE');

        $this->assertSame($this->standard->id, $booking->fresh()->room_type_id);
        $this->assertDatabaseCount('booking_modifications', 0);
        $this->assertDatabaseMissing('folios', ['booking_id' => $booking->id]);
    }

    public function test_reservation_does_not_count_itself_against_inventory(): void
    {
        $booking = $this->createBooking();

        $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}", [
                'check_out' => now()->addDays(4)->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('meta.modification.rate_difference', '100.00');

        $this->assertDatabaseCount('booking_modifications', 1);
    }

    public function test_notes_can_be_edited_without_rechecking_unchanged_inventory(): void
    {
        $booking = $this->createBooking();
        $this->createBooking(['user_id' => User::factory()->create()->id]);

        $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}", [
                'notes' => 'Late arrival note',
            ])
            ->assertOk()
            ->assertJsonPath('data.notes', 'Late arrival note')
            ->assertJsonPath('meta.modification.rate_difference', '0.00');
    }

    public function test_checked_in_or_cancelled_reservations_cannot_use_pre_stay_modification(): void
    {
        foreach ([Booking::STATUS_CHECKED_IN, Booking::STATUS_CANCELLED] as $status) {
            $booking = $this->createBooking([
                'status' => $status,
                'user_id' => User::factory()->create()->id,
            ]);

            $this->actingAs($this->staff, 'sanctum')
                ->putJson("/api/v1/bookings/{$booking->id}", ['notes' => 'Denied'])
                ->assertUnprocessable()
                ->assertJsonStructure(['error' => ['details' => ['fields' => ['status']]]]);
        }

        $this->assertDatabaseCount('booking_modifications', 0);
    }

    public function test_occupancy_must_fit_the_selected_room_type(): void
    {
        $booking = $this->createBooking();

        $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}", ['occupancy' => 3])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['occupancy']]]]);

        $this->assertSame(2, $booking->fresh()->guests);
        $this->assertDatabaseCount('booking_modifications', 0);
    }

    public function test_status_changes_and_empty_payloads_are_rejected(): void
    {
        $booking = $this->createBooking();

        $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}", ['status' => 'cancelled'])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['status']]]]);

        $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}", [])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['reservation']]]]);
    }

    private function createBooking(array $overrides = []): Booking
    {
        return Booking::create([
            'user_id' => $this->guest->id,
            'property_id' => $this->property->id,
            'room_type_id' => $this->standard->id,
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'guests' => 2,
            'status' => Booking::STATUS_CONFIRMED,
            ...$overrides,
        ]);
    }

    private function createRoomType(string $code, string $baseRate, int $maxOccupancy): RoomType
    {
        return RoomType::create([
            'property_id' => $this->property->id,
            'name' => "Room {$code}",
            'code' => $code,
            'max_occupancy' => $maxOccupancy,
            'base_rate' => $baseRate,
            'is_active' => true,
        ]);
    }

    private function createRoom(RoomType $roomType, string $number): Room
    {
        return Room::create([
            'property_id' => $this->property->id,
            'room_type_id' => $roomType->id,
            'number' => $number,
            'status' => Room::STATUS_CLEAN,
        ]);
    }
}
