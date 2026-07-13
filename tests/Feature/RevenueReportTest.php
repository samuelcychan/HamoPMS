<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Booking\Models\Booking;
use Modules\Folio\Models\Folio;
use Modules\Folio\Models\FolioLineItem;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentOperation;
use Modules\Property\Models\Property;
use Modules\Property\Models\RoomType;
use Tests\TestCase;

class RevenueReportTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Property $property;

    private Folio $standardFolio;

    private Folio $suiteFolio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-15 10:00:00'));
        $this->seed(RolePermissionSeeder::class);
        $this->manager = User::factory()->create();
        $this->property = $this->createProperty('Finance Hotel');
        $standard = $this->createRoomType($this->property, 'STD', 'Standard');
        $suite = $this->createRoomType($this->property, 'STE', 'Suite');
        $standardBooking = $this->createBooking($this->property, $standard);
        $suiteBooking = $this->createBooking($this->property, $suite);
        $this->standardFolio = Folio::create([
            'booking_id' => $standardBooking->id,
            'status' => Folio::STATUS_OPEN,
            'currency' => 'USD',
        ]);
        $this->suiteFolio = Folio::create([
            'booking_id' => $suiteBooking->id,
            'status' => Folio::STATUS_OPEN,
            'currency' => 'USD',
        ]);
        RoleAssignment::create([
            'user_id' => $this->manager->id,
            'role_id' => Role::where('slug', 'manager')->value('id'),
            'property_id' => $this->property->id,
        ]);

        $this->postLine($this->standardFolio, FolioLineItem::TYPE_ROOM_RATE, '100.00', '2026-07-13 08:00:00');
        $this->postLine($this->standardFolio, 'tax', '10.00', '2026-07-13 08:01:00');
        $this->postLine($this->standardFolio, FolioLineItem::TYPE_ANCILLARY_CHARGE, '50.00', '2026-07-14 09:00:00');
        $this->postLine($this->standardFolio, FolioLineItem::TYPE_ANCILLARY_TAX, '5.00', '2026-07-14 09:01:00');
        $this->postLine($this->suiteFolio, FolioLineItem::TYPE_ROOM_RATE, '200.00', '2026-07-14 10:00:00');
        $this->postLine($this->suiteFolio, FolioLineItem::TYPE_ANCILLARY_TAX, '20.00', '2026-07-14 10:01:00');

        $card = $this->createPayment($standardBooking, 'card', '110.00', Payment::STATUS_PARTIALLY_REFUNDED, '2026-07-13 10:00:00');
        $this->createOperation($card, 'capture', '110.00', 'capture-report', '2026-07-13 10:05:00');
        $this->createOperation($card, 'refund', '10.00', 'refund-report', '2026-07-14 11:00:00');
        $this->createPayment($suiteBooking, 'cash', '285.00', Payment::STATUS_COMPLETED, '2026-07-14 12:00:00');

        $otherProperty = $this->createProperty('Other Finance Hotel');
        $otherType = $this->createRoomType($otherProperty, 'OTH', 'Other');
        $otherBooking = $this->createBooking($otherProperty, $otherType);
        $otherFolio = Folio::create([
            'booking_id' => $otherBooking->id,
            'status' => Folio::STATUS_OPEN,
            'currency' => 'USD',
        ]);
        $this->postLine($otherFolio, FolioLineItem::TYPE_ROOM_RATE, '999.00', '2026-07-13 08:00:00');
        $this->travelTo(CarbonImmutable::parse('2026-07-15 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_revenue_report_breaks_down_daily_room_type_tax_and_payment_method(): void
    {
        $this->getReport('2026-07-13', '2026-07-14')
            ->assertOk()
            ->assertJsonPath('data.revenue.net_revenue', '350.00')
            ->assertJsonPath('data.revenue.tax_revenue', '35.00')
            ->assertJsonPath('data.revenue.gross_revenue', '385.00')
            ->assertJsonPath('data.daily.0.gross_revenue', '110.00')
            ->assertJsonPath('data.daily.1.gross_revenue', '275.00')
            ->assertJsonPath('data.by_room_type.0.room_type_code', 'STD')
            ->assertJsonPath('data.by_room_type.0.net_revenue', '150.00')
            ->assertJsonPath('data.by_room_type.1.room_type_code', 'STE')
            ->assertJsonPath('data.by_room_type.1.gross_revenue', '220.00')
            ->assertJsonPath('data.payments.captured_amount', '395.00')
            ->assertJsonPath('data.payments.refunded_amount', '10.00')
            ->assertJsonPath('data.payments.net_settled_amount', '385.00')
            ->assertJsonPath('data.by_payment_method.0.method', 'card')
            ->assertJsonPath('data.by_payment_method.0.net_settled_amount', '100.00')
            ->assertJsonPath('data.by_payment_method.1.method', 'cash')
            ->assertJsonPath('data.by_payment_method.1.net_settled_amount', '285.00')
            ->assertJsonPath('data.reconciliation.revenue_less_net_settlement', '0.00')
            ->assertJsonPath('meta.performance.target_ms', 500);
    }

    public function test_revenue_report_honors_date_range_and_fills_daily_rows(): void
    {
        $this->getReport('2026-07-12', '2026-07-13')
            ->assertOk()
            ->assertJsonPath('data.daily.0.date', '2026-07-12')
            ->assertJsonPath('data.daily.0.gross_revenue', '0.00')
            ->assertJsonPath('data.daily.1.gross_revenue', '110.00')
            ->assertJsonPath('data.revenue.gross_revenue', '110.00')
            ->assertJsonPath('data.payments.net_settled_amount', '110.00');
    }

    public function test_combined_ancillary_void_is_allocated_back_to_net_and_tax(): void
    {
        $charge = $this->postLine(
            $this->standardFolio,
            FolioLineItem::TYPE_ANCILLARY_CHARGE,
            '100.00',
            '2026-07-11 08:00:00',
        );
        $this->travelTo(CarbonImmutable::parse('2026-07-11 08:01:00'));
        $this->standardFolio->lineItems()->create([
            'related_line_item_id' => $charge->id,
            'type' => FolioLineItem::TYPE_ANCILLARY_TAX,
            'description' => 'Void allocation tax',
            'amount' => '10.00',
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-07-12 09:00:00'));
        $this->standardFolio->lineItems()->create([
            'related_line_item_id' => $charge->id,
            'type' => FolioLineItem::TYPE_ANCILLARY_VOID,
            'description' => 'Void allocation reversal',
            'amount' => '-110.00',
        ]);

        $this->getReport('2026-07-12', '2026-07-12')
            ->assertOk()
            ->assertJsonPath('data.revenue.net_revenue', '-100.00')
            ->assertJsonPath('data.revenue.tax_revenue', '-10.00')
            ->assertJsonPath('data.revenue.gross_revenue', '-110.00');
    }

    public function test_period_close_is_immutable_and_replayed_for_the_same_scope(): void
    {
        $payload = [
            'property_id' => $this->property->id,
            'start_date' => '2026-07-13',
            'end_date' => '2026-07-14',
            'currency' => 'USD',
        ];
        $first = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/reports/revenue/period-close', $payload)
            ->assertCreated()
            ->assertJsonPath('data.snapshot.revenue.gross_revenue', '385.00')
            ->assertJsonPath('meta.period_close.replayed', false);
        $snapshotId = $first->json('data.id');
        $checksum = $first->json('data.checksum');
        $this->assertSame(64, strlen($checksum));

        $this->postLine($this->standardFolio, FolioLineItem::TYPE_ROOM_RATE, '999.00', '2026-07-14 20:00:00');
        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/reports/revenue/period-close', $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $snapshotId)
            ->assertJsonPath('data.checksum', $checksum)
            ->assertJsonPath('data.snapshot.revenue.gross_revenue', '385.00')
            ->assertJsonPath('meta.period_close.replayed', true);

        $this->assertDatabaseCount('revenue_period_closes', 1);
        $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/reports/revenue/period-close/{$snapshotId}?property_id={$this->property->id}")
            ->assertOk()
            ->assertJsonPath('data.checksum', $checksum);
    }

    public function test_period_close_requires_adjust_permission_and_range_is_bounded(): void
    {
        $receptionist = User::factory()->create();
        RoleAssignment::create([
            'user_id' => $receptionist->id,
            'role_id' => Role::where('slug', 'receptionist')->value('id'),
            'property_id' => $this->property->id,
        ]);
        $payload = [
            'property_id' => $this->property->id,
            'start_date' => '2026-07-13',
            'end_date' => '2026-07-14',
        ];

        $this->actingAs($receptionist, 'sanctum')
            ->postJson('/api/v1/reports/revenue/period-close', $payload)
            ->assertForbidden();

        $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/reports/revenue?property_id={$this->property->id}&start_date=2025-01-01&end_date=2026-07-14")
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['end_date']]]]);

        $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/reports/revenue?property_id={$this->property->id}&end_date=2026-07-14")
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['end_date']]]]);
    }

    private function getReport(string $start, string $end)
    {
        return $this->actingAs($this->manager, 'sanctum')->getJson(
            "/api/v1/reports/revenue?property_id={$this->property->id}&start_date={$start}&end_date={$end}&currency=USD",
        );
    }

    private function postLine(Folio $folio, string $type, string $amount, string $at): FolioLineItem
    {
        $this->travelTo(CarbonImmutable::parse($at));

        return $folio->lineItems()->create([
            'type' => $type,
            'description' => "{$type} report entry",
            'amount' => $amount,
        ]);
    }

    private function createPayment(
        Booking $booking,
        string $method,
        string $amount,
        string $status,
        string $at,
    ): Payment {
        $this->travelTo(CarbonImmutable::parse($at));

        return Payment::create([
            'user_id' => $this->manager->id,
            'booking_id' => $booking->id,
            'amount' => $amount,
            'currency' => 'USD',
            'method' => $method,
            'status' => $status,
            'settlement_status' => $status,
        ]);
    }

    private function createOperation(Payment $payment, string $type, string $amount, string $key, string $at): void
    {
        $this->travelTo(CarbonImmutable::parse($at));
        $payment->operations()->create([
            'performed_by' => $this->manager->id,
            'type' => $type,
            'amount' => $amount,
            'idempotency_key' => $key,
            'status' => PaymentOperation::STATUS_COMPLETED,
        ]);
    }

    private function createBooking(Property $property, RoomType $roomType): Booking
    {
        $guest = User::factory()->create();

        return Booking::create([
            'user_id' => $guest->id,
            'property_id' => $property->id,
            'room_type_id' => $roomType->id,
            'check_in' => '2026-07-13',
            'check_out' => '2026-07-15',
            'guests' => 1,
            'status' => Booking::STATUS_CONFIRMED,
        ]);
    }

    private function createProperty(string $name): Property
    {
        return Property::create([
            'name' => $name,
            'address' => '1 Main Street',
            'type' => 'hotel',
        ]);
    }

    private function createRoomType(Property $property, string $code, string $name): RoomType
    {
        return RoomType::create([
            'property_id' => $property->id,
            'name' => $name,
            'code' => $code,
            'max_occupancy' => 2,
            'base_rate' => '100.00',
            'is_active' => true,
        ]);
    }
}
