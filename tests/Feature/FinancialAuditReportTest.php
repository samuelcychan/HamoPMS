<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Booking\Models\Booking;
use Modules\Folio\Models\Folio;
use Modules\Folio\Models\FolioLineItem;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentOperation;
use Modules\Property\Models\Property;
use Modules\Property\Models\RoomType;
use Modules\Reporting\Models\RevenuePeriodClose;
use Tests\TestCase;

class FinancialAuditReportTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_financial_audit_feed_is_property_scoped_filterable_and_paginated(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = User::factory()->create();
        $property = $this->property('Audit Hotel');
        RoleAssignment::create([
            'user_id' => $staff->id,
            'role_id' => Role::where('slug', 'accountant')->value('id'),
            'property_id' => $property->id,
        ]);
        $booking = $this->booking($property);
        $this->events($property, $booking, $staff);
        $otherProperty = $this->property('Other Hotel');
        $this->events($otherProperty, $this->booking($otherProperty), User::factory()->create());

        $response = $this->actingAs($staff, 'sanctum')->getJson(
            "/api/v1/reports/audit-events?property_id={$property->id}&start_date=2026-07-10&end_date=2026-07-12&per_page=2",
        )
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.source', 'revenue_period_close')
            ->assertJsonPath('data.1.source', 'payment_operation')
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.pagination.per_page', 2);

        $this->assertSame($property->id, RevenuePeriodClose::findOrFail($response->json('data.0.event_id'))->property_id);

        $this->actingAs($staff, 'sanctum')->getJson(
            "/api/v1/reports/audit-events?property_id={$property->id}&source=folio_line_item",
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', FolioLineItem::TYPE_ROOM_RATE)
            ->assertJsonPath('data.0.amount', '100.00');

        $this->actingAs($staff, 'sanctum')->getJson(
            "/api/v1/reports/audit-events?property_id={$property->id}&end_date=2026-07-11",
        )
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_financial_audit_feed_validates_filters_and_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $property = $this->property('Audit Hotel');
        $housekeeper = User::factory()->create();
        RoleAssignment::create([
            'user_id' => $housekeeper->id,
            'role_id' => Role::where('slug', 'housekeeping')->value('id'),
            'property_id' => $property->id,
        ]);

        $this->actingAs($housekeeper, 'sanctum')
            ->getJson("/api/v1/reports/audit-events?property_id={$property->id}")
            ->assertForbidden();

        $accountant = User::factory()->create();
        RoleAssignment::create([
            'user_id' => $accountant->id,
            'role_id' => Role::where('slug', 'accountant')->value('id'),
            'property_id' => $property->id,
        ]);
        $this->actingAs($accountant, 'sanctum')
            ->getJson("/api/v1/reports/audit-events?property_id={$property->id}&source=unknown")
            ->assertUnprocessable();
    }

    private function events(Property $property, Booking $booking, User $actor): void
    {
        $folio = Folio::create(['booking_id' => $booking->id, 'status' => 'open', 'currency' => 'USD']);
        Carbon::setTestNow('2026-07-10 12:00:00');
        FolioLineItem::create([
            'folio_id' => $folio->id,
            'posted_by' => $actor->id,
            'type' => FolioLineItem::TYPE_ROOM_RATE,
            'description' => 'Room rate',
            'amount' => '100.00',
        ]);
        $payment = Payment::create([
            'user_id' => $booking->user_id,
            'booking_id' => $booking->id,
            'amount' => '100.00',
            'currency' => 'USD',
            'method' => 'card',
            'status' => Payment::STATUS_COMPLETED,
        ]);
        Carbon::setTestNow('2026-07-11 12:00:00');
        PaymentOperation::create([
            'payment_id' => $payment->id,
            'performed_by' => $actor->id,
            'type' => 'capture',
            'amount' => '100.00',
            'idempotency_key' => "capture-{$property->id}",
            'status' => PaymentOperation::STATUS_COMPLETED,
        ]);
        RevenuePeriodClose::create([
            'property_id' => $property->id,
            'closed_by' => $actor->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-12',
            'currency' => 'USD',
            'snapshot' => ['gross_revenue' => '100.00'],
            'checksum' => hash('sha256', "snapshot-{$property->id}"),
            'closed_at' => '2026-07-12 12:00:00',
        ]);
    }

    private function property(string $name): Property
    {
        return Property::create(['name' => $name, 'address' => '1 Main Street', 'type' => 'hotel']);
    }

    private function booking(Property $property): Booking
    {
        $type = RoomType::create([
            'property_id' => $property->id,
            'name' => 'Standard',
            'code' => "STD-{$property->id}",
            'max_occupancy' => 2,
            'base_rate' => '100.00',
        ]);

        return Booking::create([
            'user_id' => User::factory()->create()->id,
            'property_id' => $property->id,
            'room_type_id' => $type->id,
            'check_in' => '2026-07-10',
            'check_out' => '2026-07-12',
            'guests' => 1,
            'status' => Booking::STATUS_COMPLETED,
        ]);
    }
}
