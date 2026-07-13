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
use Modules\Booking\Events\ReservationCheckedOut;
use Modules\Booking\Models\Booking;
use Modules\Folio\Models\Folio;
use Modules\Folio\Models\FolioLineItem;
use Modules\Payment\Models\Payment;
use Modules\Property\Models\Property;
use Modules\Property\Models\Room;
use Modules\Property\Models\RoomType;
use Tests\TestCase;

class CheckOutTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $guest;

    private Property $property;

    private Room $room;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-10 10:00:00');
        CarbonImmutable::setTestNow('2026-07-10 10:00:00');
        config()->set('checkout.departure_time', '11:00');
        config()->set('checkout.late_fee', '50.00');
        config()->set('checkout.late_fee_enabled', true);
        $this->seed(RolePermissionSeeder::class);
        $this->staff = User::factory()->create();
        $this->guest = User::factory()->create();
        $this->property = Property::create([
            'name' => 'Harbour Hotel',
            'address' => '1 Main Street',
            'type' => 'hotel',
        ]);
        $roomType = RoomType::create([
            'property_id' => $this->property->id,
            'name' => 'Standard Room',
            'code' => 'STD',
            'max_occupancy' => 2,
            'base_rate' => '100.00',
            'is_active' => true,
        ]);
        $this->room = Room::create([
            'property_id' => $this->property->id,
            'room_type_id' => $roomType->id,
            'number' => '101',
            'status' => Room::STATUS_OCCUPIED,
        ]);
        $this->booking = Booking::create([
            'user_id' => $this->guest->id,
            'property_id' => $this->property->id,
            'room_type_id' => $roomType->id,
            'room_id' => $this->room->id,
            'check_in' => now()->subDays(2)->toDateString(),
            'check_out' => now()->toDateString(),
            'guests' => 2,
            'status' => Booking::STATUS_CHECKED_IN,
            'checked_in_at' => now()->subDays(2),
            'checked_in_by' => $this->staff->id,
            'nightly_rate' => '100.00',
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

    public function test_zero_balance_closes_folio_completes_stay_releases_room_and_emits_event(): void
    {
        Event::fake([ReservationCheckedOut::class]);
        Log::spy();
        $folio = $this->createFolioWithCharge('200.00');
        $this->createPayment('200.00', Payment::STATUS_COMPLETED);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson($this->checkOutUrl())
            ->assertOk()
            ->assertJsonPath('data.status', Booking::STATUS_COMPLETED)
            ->assertJsonPath('data.checked_out_by', $this->staff->id)
            ->assertJsonPath('meta.checkout.folio_id', $folio->id)
            ->assertJsonPath('meta.checkout.outstanding_balance', '0.00')
            ->assertJsonPath('meta.checkout.late_checkout_fee', '0.00');

        $this->assertSame(Booking::STATUS_COMPLETED, $this->booking->fresh()->status);
        $this->assertNotNull($this->booking->fresh()->checked_out_at);
        $this->assertSame(Folio::STATUS_CLOSED, $folio->fresh()->status);
        $this->assertSame(Room::STATUS_DIRTY, $this->room->fresh()->status);
        $this->assertDatabaseHas('room_status_histories', [
            'room_id' => $this->room->id,
            'from_status' => Room::STATUS_OCCUPIED,
            'to_status' => Room::STATUS_DIRTY,
            'changed_by' => $this->staff->id,
            'reason' => "Checked out reservation {$this->booking->id}.",
        ]);
        Event::assertDispatched(
            ReservationCheckedOut::class,
            fn (ReservationCheckedOut $event): bool => $event->booking->is($this->booking)
                && $event->booking->status === Booking::STATUS_COMPLETED
                && $event->folioId === $folio->id
                && $event->lateCheckoutFee === '0.00',
        );
        Log::shouldHaveReceived('info')->with('reservation.checked_out', [
            'actor_id' => $this->staff->id,
            'booking_id' => $this->booking->id,
            'property_id' => $this->property->id,
            'folio_id' => $folio->id,
            'room_id' => $this->room->id,
            'late_checkout_fee' => '0.00',
        ])->once();
    }

    public function test_unsettled_balance_blocks_checkout_and_pending_payments_do_not_count(): void
    {
        Event::fake([ReservationCheckedOut::class]);
        $folio = $this->createFolioWithCharge('200.00');
        $this->createPayment('150.00', Payment::STATUS_COMPLETED);
        $this->createPayment('50.00', Payment::STATUS_PENDING);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson($this->checkOutUrl())
            ->assertConflict()
            ->assertJsonPath('error.code', 'FOLIO_BALANCE_OUTSTANDING')
            ->assertJsonPath('error.details.folio_id', $folio->id)
            ->assertJsonPath('error.details.outstanding_balance', '50.00');

        $this->assertSame(Booking::STATUS_CHECKED_IN, $this->booking->fresh()->status);
        $this->assertNull($this->booking->fresh()->checked_out_at);
        $this->assertSame(Folio::STATUS_OPEN, $folio->fresh()->status);
        $this->assertSame(Room::STATUS_OCCUPIED, $this->room->fresh()->status);
        $this->assertDatabaseCount('room_status_histories', 0);
        Event::assertNotDispatched(ReservationCheckedOut::class);
    }

    public function test_late_fee_is_posted_once_before_balance_validation_and_can_then_be_settled(): void
    {
        Event::fake([ReservationCheckedOut::class]);
        Carbon::setTestNow('2026-07-10 12:00:00');
        CarbonImmutable::setTestNow('2026-07-10 12:00:00');
        $folio = $this->createFolioWithCharge('200.00');
        $this->createPayment('200.00', Payment::STATUS_COMPLETED);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson($this->checkOutUrl())
            ->assertConflict()
            ->assertJsonPath('error.details.outstanding_balance', '50.00')
            ->assertJsonPath('error.details.late_checkout_fee', '50.00');

        $this->assertSame(1, $folio->lineItems()->where('type', FolioLineItem::TYPE_LATE_CHECKOUT_FEE)->count());
        $this->createPayment('50.00', Payment::STATUS_COMPLETED);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson($this->checkOutUrl())
            ->assertOk()
            ->assertJsonPath('meta.checkout.late_checkout_fee', '50.00');

        $this->assertSame(1, $folio->lineItems()->where('type', FolioLineItem::TYPE_LATE_CHECKOUT_FEE)->count());
        $this->assertSame('250.00', $folio->balance());
        $this->assertSame(Folio::STATUS_CLOSED, $folio->fresh()->status);
        Event::assertDispatchedTimes(ReservationCheckedOut::class, 1);
    }

    public function test_only_checked_in_reservations_can_be_checked_out(): void
    {
        Event::fake([ReservationCheckedOut::class]);
        $this->booking->update(['status' => Booking::STATUS_CONFIRMED, 'room_id' => null]);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson($this->checkOutUrl())
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['status']]]]);

        $this->assertSame(Room::STATUS_OCCUPIED, $this->room->fresh()->status);
        Event::assertNotDispatched(ReservationCheckedOut::class);
    }

    private function createFolioWithCharge(string $amount): Folio
    {
        $folio = Folio::create([
            'booking_id' => $this->booking->id,
            'status' => Folio::STATUS_OPEN,
            'currency' => 'USD',
        ]);
        $folio->lineItems()->create([
            'type' => FolioLineItem::TYPE_ROOM_RATE,
            'description' => 'Stay room rate',
            'amount' => $amount,
        ]);

        return $folio;
    }

    private function createPayment(string $amount, string $status): Payment
    {
        return Payment::create([
            'user_id' => $this->staff->id,
            'booking_id' => $this->booking->id,
            'amount' => $amount,
            'currency' => 'USD',
            'method' => 'cash',
            'status' => $status,
        ]);
    }

    private function checkOutUrl(): string
    {
        return "/api/v1/bookings/{$this->booking->id}/check-out";
    }
}
