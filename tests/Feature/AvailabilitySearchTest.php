<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Booking\Models\Booking;
use Modules\Booking\Services\AvailabilitySearchService;
use Modules\Property\Models\Property;
use Modules\Property\Models\Room;
use Modules\Property\Models\RoomType;
use Tests\TestCase;

class AvailabilitySearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->property = Property::create([
            'name' => 'Harbour Hotel',
            'address' => '1 Main Street',
            'type' => 'hotel',
        ]);
    }

    public function test_search_returns_room_types_with_inventory_counts_and_timing(): void
    {
        $roomType = $this->createRoomType();
        $this->createRoom($roomType, '101', Room::STATUS_CLEAN);
        $this->createRoom($roomType, '102', Room::STATUS_DIRTY);
        $this->createRoom($roomType, '103', Room::STATUS_OUT_OF_SERVICE);

        $this->search()
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.room_type_id', $roomType->id)
            ->assertJsonPath('data.0.total_inventory', 3)
            ->assertJsonPath('data.0.blocked_inventory', 1)
            ->assertJsonPath('data.0.reserved_inventory', 0)
            ->assertJsonPath('data.0.available_inventory', 2)
            ->assertJsonPath('meta.performance.target_ms', AvailabilitySearchService::RESPONSE_TIME_TARGET_MS)
            ->assertJsonStructure(['meta' => ['performance' => ['duration_ms', 'meets_target']]])
            ->assertHeader('X-Response-Time-Target', '250ms')
            ->assertHeader('Server-Timing');
    }

    public function test_search_is_scoped_to_the_requested_property(): void
    {
        $roomType = $this->createRoomType();
        $this->createRoom($roomType, '101');

        $otherProperty = Property::create([
            'name' => 'Other Hotel',
            'address' => '2 Main Street',
            'type' => 'hotel',
        ]);
        $otherType = $this->createRoomType($otherProperty, 'OTHER');
        $this->createRoom($otherType, '201');

        $this->search()
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.property_id', $this->property->id);
    }

    public function test_search_filters_room_types_by_occupancy(): void
    {
        $small = $this->createRoomType(maxOccupancy: 2);
        $large = $this->createRoomType(code: 'FAM', maxOccupancy: 4);
        $this->createRoom($small, '101');
        $this->createRoom($large, '201');

        $this->search(['occupancy' => 3])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.room_type_id', $large->id);
    }

    public function test_sold_out_room_type_is_not_returned(): void
    {
        $roomType = $this->createRoomType();
        $this->createRoom($roomType, '101');
        $this->createRoom($roomType, '102');
        $this->createBooking($roomType, now()->addDay()->toDateString(), now()->addDays(3)->toDateString());
        $this->createBooking($roomType, now()->addDays(2)->toDateString(), now()->addDays(4)->toDateString());

        $this->search()
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_adjacent_booking_does_not_reduce_inventory(): void
    {
        $roomType = $this->createRoomType();
        $this->createRoom($roomType, '101');
        $this->createBooking($roomType, now()->addDay()->toDateString(), now()->addDays(3)->toDateString());

        $this->search([
            'check_in' => now()->addDays(3)->toDateString(),
            'check_out' => now()->addDays(5)->toDateString(),
        ])
            ->assertOk()
            ->assertJsonPath('data.0.reserved_inventory', 0)
            ->assertJsonPath('data.0.available_inventory', 1);
    }

    public function test_inventory_uses_peak_nightly_reservations_instead_of_total_overlaps(): void
    {
        $roomType = $this->createRoomType();
        $this->createRoom($roomType, '101');
        $this->createRoom($roomType, '102');
        $this->createBooking($roomType, now()->addDay()->toDateString(), now()->addDays(2)->toDateString());
        $this->createBooking($roomType, now()->addDays(3)->toDateString(), now()->addDays(4)->toDateString());

        $this->search([
            'check_out' => now()->addDays(5)->toDateString(),
        ])
            ->assertOk()
            ->assertJsonPath('data.0.reserved_inventory', 1)
            ->assertJsonPath('data.0.available_inventory', 1);
    }

    public function test_inactive_and_zero_inventory_room_types_are_not_returned(): void
    {
        $inactive = $this->createRoomType(active: false);
        $this->createRoom($inactive, '101');
        $this->createRoomType(code: 'EMPTY');

        $this->search()
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_search_parameters_use_the_validation_contract(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/availability?occupancy=0')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure([
                'error' => ['details' => ['fields' => ['property_id', 'check_in', 'check_out', 'occupancy']]],
            ]);
    }

    private function search(array $overrides = []): TestResponse
    {
        $query = [
            'property_id' => $this->property->id,
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'occupancy' => 2,
            ...$overrides,
        ];

        return $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/availability?'.http_build_query($query));
    }

    private function createRoomType(
        ?Property $property = null,
        string $code = 'DLX',
        int $maxOccupancy = 4,
        bool $active = true,
    ): RoomType {
        $property ??= $this->property;

        return RoomType::create([
            'property_id' => $property->id,
            'name' => "Room {$code}",
            'code' => $code,
            'max_occupancy' => $maxOccupancy,
            'base_rate' => '250.00',
            'is_active' => $active,
        ]);
    }

    private function createRoom(RoomType $roomType, string $number, string $status = Room::STATUS_CLEAN): Room
    {
        return Room::create([
            'property_id' => $roomType->property_id,
            'room_type_id' => $roomType->id,
            'number' => $number,
            'status' => $status,
        ]);
    }

    private function createBooking(RoomType $roomType, string $checkIn, string $checkOut): Booking
    {
        return Booking::create([
            'user_id' => $this->user->id,
            'property_id' => $roomType->property_id,
            'room_type_id' => $roomType->id,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'guests' => 2,
            'status' => Booking::STATUS_CONFIRMED,
        ]);
    }
}
