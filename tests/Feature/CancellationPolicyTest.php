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
use Modules\Booking\Events\ReservationCancelled;
use Modules\Booking\Models\Booking;
use Modules\Folio\Models\Folio;
use Modules\Folio\Models\FolioLineItem;
use Modules\Property\Models\Property;
use Modules\Property\Models\Room;
use Modules\Property\Models\RoomType;
use Tests\TestCase;

class CancellationPolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $guest;

    private Property $property;

    private RoomType $roomType;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-01 00:00:00');
        CarbonImmutable::setTestNow('2026-07-01 00:00:00');
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
            'name' => 'Standard Room',
            'code' => 'STD',
            'max_occupancy' => 2,
            'base_rate' => '100.00',
            'is_active' => true,
        ]);
        Room::create([
            'property_id' => $this->property->id,
            'room_type_id' => $this->roomType->id,
            'number' => '101',
            'status' => Room::STATUS_CLEAN,
        ]);
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

    public function test_cancellation_outside_the_penalty_window_is_free_and_releases_inventory(): void
    {
        Event::fake([ReservationCancelled::class]);
        Log::spy();
        $booking = $this->createBooking([
            'check_in' => now()->addDays(4)->toDateString(),
            'check_out' => now()->addDays(6)->toDateString(),
        ]);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', Booking::STATUS_CANCELLED)
            ->assertJsonPath('data.cancellation_penalty', '0.00')
            ->assertJsonPath('meta.cancellation.policy', 'standard')
            ->assertJsonPath('meta.cancellation.is_free', true)
            ->assertJsonPath('meta.cancellation.penalty', '0.00');

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => Booking::STATUS_CANCELLED,
            'cancelled_by' => $this->staff->id,
            'cancellation_penalty' => '0.00',
        ]);
        $this->assertNotNull($booking->fresh()->cancelled_at);

        $folio = Folio::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame('0.00', $folio->balance());
        $this->assertDatabaseHas('folio_line_items', [
            'folio_id' => $folio->id,
            'type' => FolioLineItem::TYPE_ROOM_RATE_REVERSAL,
            'amount' => '-200.00',
        ]);
        $this->assertDatabaseMissing('folio_line_items', [
            'folio_id' => $folio->id,
            'type' => FolioLineItem::TYPE_CANCELLATION_PENALTY,
        ]);

        Event::assertDispatched(
            ReservationCancelled::class,
            fn (ReservationCancelled $event): bool => $event->booking->is($booking)
                && $event->policy === 'standard'
                && $event->penalty === '0.00',
        );
        Log::shouldHaveReceived('info')->with('reservation.cancelled', [
            'actor_id' => $this->staff->id,
            'booking_id' => $booking->id,
            'property_id' => $this->property->id,
            'policy' => 'standard',
            'penalty' => '0.00',
            'hours_before_check_in' => 96,
        ])->once();

        $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'property_id' => $this->property->id,
                'room_type_id' => $this->roomType->id,
                'check_in' => $booking->check_in->toDateString(),
                'check_out' => $booking->check_out->toDateString(),
                'guests' => 2,
            ])
            ->assertCreated();
    }

    public function test_late_standard_cancellation_posts_a_first_night_penalty(): void
    {
        Carbon::setTestNow('2026-07-04 12:00:00');
        CarbonImmutable::setTestNow('2026-07-04 12:00:00');
        $booking = $this->createBooking([
            'check_in' => '2026-07-05',
            'check_out' => '2026-07-07',
        ]);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/cancel")
            ->assertOk()
            ->assertJsonPath('meta.cancellation.is_free', false)
            ->assertJsonPath('meta.cancellation.hours_before_check_in', 12)
            ->assertJsonPath('meta.cancellation.penalty', '100.00');

        $folio = Folio::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame('100.00', $folio->balance());
        $this->assertDatabaseHas('folio_line_items', [
            'folio_id' => $folio->id,
            'type' => FolioLineItem::TYPE_CANCELLATION_PENALTY,
            'amount' => '100.00',
        ]);
    }

    public function test_cancellation_at_the_exact_free_window_boundary_is_free(): void
    {
        Carbon::setTestNow('2026-07-03 00:00:00');
        CarbonImmutable::setTestNow('2026-07-03 00:00:00');
        $booking = $this->createBooking([
            'check_in' => '2026-07-05',
            'check_out' => '2026-07-07',
        ]);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/cancel")
            ->assertOk()
            ->assertJsonPath('meta.cancellation.hours_before_check_in', 48)
            ->assertJsonPath('meta.cancellation.is_free', true)
            ->assertJsonPath('meta.cancellation.penalty', '0.00');
    }

    public function test_non_refundable_policy_charges_the_full_room_total(): void
    {
        $booking = $this->createBooking([
            'check_in' => now()->addDays(10)->toDateString(),
            'check_out' => now()->addDays(12)->toDateString(),
            'cancellation_policy' => 'non_refundable',
        ]);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/cancel")
            ->assertOk()
            ->assertJsonPath('meta.cancellation.is_non_refundable', true)
            ->assertJsonPath('meta.cancellation.penalty', '200.00');

        $this->assertSame(
            '200.00',
            Folio::where('booking_id', $booking->id)->firstOrFail()->balance(),
        );
    }

    public function test_percentage_penalty_can_be_configured(): void
    {
        config()->set('cancellation.policies.half_rate', [
            'free_cancellation_hours' => 72,
            'penalty_type' => 'percentage',
            'penalty_value' => 50,
            'non_refundable' => false,
        ]);
        $booking = $this->createBooking([
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'cancellation_policy' => 'half_rate',
        ]);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/cancel")
            ->assertOk()
            ->assertJsonPath('meta.cancellation.policy', 'half_rate')
            ->assertJsonPath('meta.cancellation.penalty', '100.00');
    }

    public function test_penalty_uses_the_reservations_rate_snapshot(): void
    {
        $booking = $this->createBooking([
            'cancellation_policy' => 'non_refundable',
            'nightly_rate' => '100.00',
        ]);
        $this->roomType->update(['base_rate' => '250.00']);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/cancel")
            ->assertOk()
            ->assertJsonPath('meta.cancellation.penalty', '200.00');
    }

    public function test_api_created_reservation_keeps_its_policy_snapshot(): void
    {
        Carbon::setTestNow('2026-07-04 12:00:00');
        CarbonImmutable::setTestNow('2026-07-04 12:00:00');
        $response = $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'property_id' => $this->property->id,
                'room_type_id' => $this->roomType->id,
                'check_in' => '2026-07-05',
                'check_out' => '2026-07-07',
                'guests' => 2,
                'cancellation_policy' => 'standard',
            ])
            ->assertCreated();
        $booking = Booking::findOrFail($response->json('data.id'));

        config()->set('cancellation.policies.standard', [
            'free_cancellation_hours' => 0,
            'penalty_type' => 'none',
            'penalty_value' => null,
            'non_refundable' => false,
        ]);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/cancel")
            ->assertOk()
            ->assertJsonPath('meta.cancellation.penalty', '100.00');
    }

    public function test_non_cancellable_states_do_not_post_folio_entries_or_events(): void
    {
        Event::fake([ReservationCancelled::class]);
        $booking = $this->createBooking(['status' => Booking::STATUS_CHECKED_IN]);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/cancel")
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['status']]]]);

        $this->assertDatabaseMissing('folios', ['booking_id' => $booking->id]);
        Event::assertNotDispatched(ReservationCancelled::class);
    }

    private function createBooking(array $overrides = []): Booking
    {
        return Booking::create([
            'user_id' => $this->guest->id,
            'property_id' => $this->property->id,
            'room_type_id' => $this->roomType->id,
            'check_in' => now()->addDays(4)->toDateString(),
            'check_out' => now()->addDays(6)->toDateString(),
            'guests' => 2,
            'status' => Booking::STATUS_CONFIRMED,
            'nightly_rate' => '100.00',
            'cancellation_policy' => 'standard',
            ...$overrides,
        ]);
    }
}
