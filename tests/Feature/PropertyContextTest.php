<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Booking\Models\Booking;
use Modules\Payment\Models\Payment;
use Modules\Property\Models\Property;
use Tests\TestCase;

class PropertyContextTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Property $property;

    private Property $otherProperty;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->user = User::factory()->create();
        $this->property = $this->createProperty('Harbour Hotel');
        $this->otherProperty = $this->createProperty('Airport Hotel');
        $this->assign($this->user, 'receptionist', $this->property);
    }

    public function test_property_context_is_required_on_scoped_collection_routes(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/bookings')
            ->assertBadRequest()
            ->assertExactJson([
                'error' => [
                    'code' => 'PROPERTY_CONTEXT_REQUIRED',
                    'message' => 'A property context is required for this request.',
                ],
            ]);
    }

    public function test_header_context_scopes_collection_queries_to_one_property(): void
    {
        $this->assign($this->user, 'receptionist', $this->otherProperty);
        $included = $this->createBooking($this->property);
        $this->createBooking($this->otherProperty);

        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Property-ID', (string) $this->property->id)
            ->getJson('/api/v1/bookings')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $included->id)
            ->assertJsonPath('data.0.property_id', $this->property->id)
            ->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_payment_collection_is_scoped_through_its_booking_property(): void
    {
        $this->assign($this->user, 'receptionist', $this->otherProperty);
        $included = Payment::create([
            ...$this->paymentAttributes(),
            'booking_id' => $this->createBooking($this->property)->id,
        ]);
        Payment::create([
            ...$this->paymentAttributes(),
            'booking_id' => $this->createBooking($this->otherProperty)->id,
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Property-ID', (string) $this->property->id)
            ->getJson('/api/v1/payments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $included->id)
            ->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_cross_property_access_is_blocked_by_default(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/properties/{$this->otherProperty->id}/rooms")
            ->assertForbidden()
            ->assertExactJson([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You are not allowed to perform this action.',
                ],
            ]);
    }

    public function test_resource_and_header_context_must_match(): void
    {
        $this->assign($this->user, 'receptionist', $this->otherProperty);
        $booking = $this->createBooking($this->property);

        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Property-ID', (string) $this->otherProperty->id)
            ->getJson("/api/v1/bookings/{$booking->id}")
            ->assertConflict()
            ->assertExactJson([
                'error' => [
                    'code' => 'PROPERTY_CONTEXT_MISMATCH',
                    'message' => 'The property context does not match the requested resource.',
                ],
            ]);
    }

    public function test_header_context_can_supply_availability_property(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Property-ID', (string) $this->property->id)
            ->getJson('/api/v1/availability?'.http_build_query([
                'check_in' => now()->addDay()->toDateString(),
                'check_out' => now()->addDays(2)->toDateString(),
                'occupancy' => 2,
            ]))
            ->assertOk()
            ->assertJsonPath('meta.search.property_id', $this->property->id);
    }

    public function test_invalid_property_context_uses_validation_contract(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Property-ID', 'invalid')
            ->getJson('/api/v1/bookings')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['property_id']]]]);
    }

    private function createBooking(Property $property): Booking
    {
        return Booking::create([
            'user_id' => $this->user->id,
            'property_id' => $property->id,
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(2)->toDateString(),
            'guests' => 1,
            'status' => Booking::STATUS_CONFIRMED,
        ]);
    }

    private function assign(User $user, string $role, Property $property): void
    {
        RoleAssignment::create([
            'user_id' => $user->id,
            'role_id' => Role::where('slug', $role)->value('id'),
            'property_id' => $property->id,
        ]);
    }

    private function paymentAttributes(): array
    {
        return [
            'user_id' => $this->user->id,
            'amount' => '100.00',
            'currency' => 'USD',
            'method' => 'card',
            'status' => 'pending',
        ];
    }

    private function createProperty(string $name): Property
    {
        return Property::create([
            'name' => $name,
            'address' => '1 Main Street',
            'type' => 'hotel',
        ]);
    }
}
