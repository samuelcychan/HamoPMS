<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\StayLifecycleCiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Modules\Booking\Models\Booking;
use Modules\Folio\Models\Folio;
use Modules\Payment\Models\Payment;
use Modules\Property\Models\HousekeepingTask;
use Modules\Property\Models\Property;
use Modules\Property\Models\Room;
use Modules\Property\Models\RoomType;
use Tests\TestCase;

class FullStayLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Property $property;

    private Property $otherProperty;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-13 09:00:00');
        CarbonImmutable::setTestNow('2026-07-13 09:00:00');
        config()->set('checkout.late_fee_enabled', false);
        config()->set('payment.default', 'stripe');
        config()->set('payment.gateways.stripe.secret', 'sk_test_ci');
        config()->set('payment.gateways.stripe.base_url', 'https://api.stripe.test/v1');
        Mail::fake();
        $this->fakeStripe();
        $this->seed(StayLifecycleCiSeeder::class);
        $this->staff = User::where('email', StayLifecycleCiSeeder::STAFF_EMAIL)->firstOrFail();
        $this->property = Property::where('name', StayLifecycleCiSeeder::PRIMARY_PROPERTY)->firstOrFail();
        $this->otherProperty = Property::where('name', StayLifecycleCiSeeder::OTHER_PROPERTY)->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_full_stay_payment_and_multi_property_lifecycle(): void
    {
        $standard = RoomType::where('property_id', $this->property->id)->where('code', 'STD')->firstOrFail();
        $deluxe = RoomType::where('property_id', $this->property->id)->where('code', 'DLX')->firstOrFail();
        $standardRoom = Room::where('room_type_id', $standard->id)->where('number', '101')->firstOrFail();
        $deluxeRoom = Room::where('room_type_id', $deluxe->id)->where('number', '201')->firstOrFail();
        $search = $this->actingAs($this->staff, 'sanctum')
            ->getJson('/api/v1/availability?'.http_build_query([
                'property_id' => $this->property->id,
                'check_in' => now()->toDateString(),
                'check_out' => now()->addDays(2)->toDateString(),
                'occupancy' => 2,
            ]))
            ->assertOk();
        $this->assertSame(2, collect($search->json('data'))->firstWhere('room_type_id', $standard->id)['available_inventory']);

        $bookingId = $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'property_id' => $this->property->id,
                'room_type_id' => $standard->id,
                'check_in' => now()->toDateString(),
                'check_out' => now()->addDays(2)->toDateString(),
                'guests' => 2,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', Booking::STATUS_CONFIRMED)
            ->json('data.id');
        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/bookings/{$bookingId}/check-in", ['room_id' => $standardRoom->id])
            ->assertOk()
            ->assertJsonPath('data.status', Booking::STATUS_CHECKED_IN)
            ->assertJsonPath('data.room.status', Room::STATUS_OCCUPIED);
        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/bookings/{$bookingId}/room-move", [
                'room_id' => $deluxeRoom->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.room_id', $deluxeRoom->id)
            ->assertJsonPath('data.room_type_id', $deluxe->id)
            ->assertJsonPath('meta.stay_change.rate_difference', '100.00');

        $this->actingAs($this->staff, 'sanctum')
            ->getJson("/api/v1/bookings/{$bookingId}/folio")
            ->assertOk()
            ->assertJsonPath('data.balance', '300.00')
            ->assertJsonPath('data.outstanding_balance', '300.00');
        $paymentId = $this->actingAs($this->staff, 'sanctum')
            ->withHeader('Idempotency-Key', 'e2e-create-payment')
            ->postJson('/api/v1/payments', [
                'booking_id' => $bookingId,
                'amount' => '300.00',
                'currency' => 'USD',
                'method' => 'card',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', Payment::STATUS_REQUIRES_CAPTURE)
            ->json('data.id');
        $this->actingAs($this->staff, 'sanctum')
            ->withHeader('Idempotency-Key', 'e2e-capture-payment')
            ->postJson("/api/v1/payments/{$paymentId}/capture")
            ->assertOk()
            ->assertJsonPath('data.status', Payment::STATUS_COMPLETED)
            ->assertJsonPath('data.captured_amount', '300.00');
        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/bookings/{$bookingId}/check-out")
            ->assertOk()
            ->assertJsonPath('data.status', Booking::STATUS_COMPLETED)
            ->assertJsonPath('data.room.status', Room::STATUS_DIRTY)
            ->assertJsonPath('meta.checkout.outstanding_balance', '0.00');
        $this->actingAs($this->staff, 'sanctum')
            ->withHeader('Idempotency-Key', 'e2e-refund-payment')
            ->postJson("/api/v1/payments/{$paymentId}/refund", ['amount' => '50.00'])
            ->assertOk()
            ->assertJsonPath('data.status', Payment::STATUS_PARTIALLY_REFUNDED)
            ->assertJsonPath('data.refunded_amount', '50.00');

        $this->assertSame(Room::STATUS_DIRTY, $deluxeRoom->fresh()->status);
        $this->assertSame(Room::STATUS_DIRTY, $standardRoom->fresh()->status);
        $this->assertDatabaseHas('housekeeping_tasks', [
            'booking_id' => $bookingId,
            'room_id' => $deluxeRoom->id,
            'status' => HousekeepingTask::STATUS_PENDING,
        ]);
        $this->assertSame('closed', Folio::where('booking_id', $bookingId)->value('status'));
        $this->assertDatabaseCount('payment_operations', 2);

        $otherType = RoomType::where('property_id', $this->otherProperty->id)->firstOrFail();
        $otherBooking = Booking::create([
            'user_id' => User::where('email', StayLifecycleCiSeeder::OTHER_STAFF_EMAIL)->value('id'),
            'property_id' => $this->otherProperty->id,
            'room_type_id' => $otherType->id,
            'check_in' => now()->toDateString(),
            'check_out' => now()->addDay()->toDateString(),
            'guests' => 1,
            'status' => Booking::STATUS_CONFIRMED,
        ]);
        $this->actingAs($this->staff, 'sanctum')
            ->getJson('/api/v1/availability?'.http_build_query([
                'property_id' => $this->otherProperty->id,
                'check_in' => now()->toDateString(),
                'check_out' => now()->addDay()->toDateString(),
                'occupancy' => 1,
            ]))
            ->assertForbidden();
        $this->actingAs($this->staff, 'sanctum')
            ->getJson("/api/v1/bookings/{$otherBooking->id}")
            ->assertForbidden();
        $this->actingAs($this->staff, 'sanctum')
            ->withHeader('X-Property-ID', $this->otherProperty->id)
            ->getJson('/api/v1/payments')
            ->assertForbidden();
    }

    public function test_ci_seed_data_is_idempotent_and_clock_independent(): void
    {
        $this->seed(StayLifecycleCiSeeder::class);

        $this->assertDatabaseCount('properties', 2);
        $this->assertDatabaseCount('room_types', 3);
        $this->assertDatabaseCount('rooms', 4);
        $this->assertDatabaseCount('role_assignments', 2);
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    private function fakeStripe(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_ends_with($url, '/capture')) {
                return Http::response(['id' => basename(dirname($url)), 'status' => 'succeeded']);
            }

            if (str_ends_with($url, '/refunds')) {
                return Http::response(['id' => 're_e2e', 'status' => 'succeeded']);
            }

            return Http::response([
                'id' => 'pi_'.$request['amount'],
                'status' => Payment::STATUS_REQUIRES_CAPTURE,
            ]);
        });
    }
}
