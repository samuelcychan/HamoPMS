<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Booking\Models\Booking;
use Modules\Property\Models\Property;
use Tests\TestCase;

class ReservationCreationTest extends TestCase
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

    public function test_authenticated_guest_can_create_a_confirmed_reservation(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', $this->reservationPayload());

        $response
            ->assertCreated()
            ->assertJsonPath('data.user_id', $this->user->id)
            ->assertJsonPath('data.property_id', $this->property->id)
            ->assertJsonPath('data.status', Booking::STATUS_CONFIRMED);

        $this->assertDatabaseHas('bookings', [
            'user_id' => $this->user->id,
            'property_id' => $this->property->id,
            'status' => Booking::STATUS_CONFIRMED,
        ]);
    }

    public function test_overlapping_active_reservation_is_rejected_without_creating_a_booking(): void
    {
        Booking::create([
            ...$this->reservationPayload(),
            'user_id' => User::factory()->create()->id,
            'status' => Booking::STATUS_CONFIRMED,
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                ...$this->reservationPayload(),
                'check_in' => now()->addDays(2)->toDateString(),
                'check_out' => now()->addDays(4)->toDateString(),
            ])
            ->assertConflict()
            ->assertExactJson([
                'error' => [
                    'code' => 'INVENTORY_UNAVAILABLE',
                    'message' => 'The property is unavailable for the requested dates.',
                ],
            ]);

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_adjacent_reservation_does_not_count_as_an_overlap(): void
    {
        Booking::create([
            ...$this->reservationPayload(),
            'user_id' => User::factory()->create()->id,
            'status' => Booking::STATUS_CONFIRMED,
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                ...$this->reservationPayload(),
                'check_in' => now()->addDays(3)->toDateString(),
                'check_out' => now()->addDays(5)->toDateString(),
            ])
            ->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_cancelled_reservation_releases_inventory(): void
    {
        Booking::create([
            ...$this->reservationPayload(),
            'user_id' => User::factory()->create()->id,
            'status' => Booking::STATUS_CANCELLED,
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', $this->reservationPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', Booking::STATUS_CONFIRMED);

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_invalid_reservation_dates_use_the_validation_contract(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                ...$this->reservationPayload(),
                'check_out' => now()->addDay()->toDateString(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['check_out']]]]);
    }

    private function reservationPayload(): array
    {
        return [
            'property_id' => $this->property->id,
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'guests' => 2,
            'notes' => 'Late arrival',
        ];
    }
}
