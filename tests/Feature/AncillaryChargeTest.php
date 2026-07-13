<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Booking\Models\Booking;
use Modules\Folio\Models\AncillaryChargeType;
use Modules\Folio\Models\Folio;
use Modules\Folio\Models\FolioLineItem;
use Modules\Property\Models\Property;
use Modules\Property\Models\Room;
use Modules\Property\Models\RoomType;
use Tests\TestCase;

class AncillaryChargeTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $receptionist;

    private Property $property;

    private Booking $booking;

    private Folio $folio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->manager = User::factory()->create();
        $this->receptionist = User::factory()->create();
        $guest = User::factory()->create();
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
        $room = Room::create([
            'property_id' => $this->property->id,
            'room_type_id' => $roomType->id,
            'number' => '101',
            'status' => Room::STATUS_OCCUPIED,
        ]);
        $this->booking = Booking::create([
            'user_id' => $guest->id,
            'property_id' => $this->property->id,
            'room_type_id' => $roomType->id,
            'room_id' => $room->id,
            'check_in' => now()->subDay()->toDateString(),
            'check_out' => now()->addDays(2)->toDateString(),
            'guests' => 2,
            'status' => Booking::STATUS_CHECKED_IN,
            'checked_in_at' => now()->subDay(),
            'checked_in_by' => $this->receptionist->id,
            'nightly_rate' => '100.00',
        ]);
        $this->folio = Folio::create([
            'booking_id' => $this->booking->id,
            'status' => Folio::STATUS_OPEN,
            'currency' => 'USD',
        ]);
        $this->assignRole($this->manager, 'manager');
        $this->assignRole($this->receptionist, 'receptionist');
    }

    public function test_manager_can_configure_property_charge_types(): void
    {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/properties/{$this->property->id}/ancillary-charge-types", [
                'code' => 'ROOM_SERVICE',
                'name' => 'Room service',
                'tax_rate' => '8.25',
            ])
            ->assertCreated()
            ->assertJsonPath('data.property_id', $this->property->id)
            ->assertJsonPath('data.code', 'ROOM_SERVICE')
            ->assertJsonPath('data.tax_rate', '8.25')
            ->assertJsonPath('data.is_active', true);

        $chargeTypeId = $response->json('data.id');

        $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/v1/properties/{$this->property->id}/ancillary-charge-types/{$chargeTypeId}", [
                'tax_rate' => '10.00',
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.tax_rate', '10.00')
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/v1/properties/{$this->property->id}/ancillary-charge-types", [
                'code' => 'MINIBAR',
                'name' => 'Minibar',
                'tax_rate' => 0,
            ])
            ->assertForbidden();
    }

    public function test_receptionist_posts_charge_and_automatic_tax_immediately(): void
    {
        $chargeType = $this->createChargeType();

        $response = $this->actingAs($this->receptionist, 'sanctum')
            ->postJson($this->chargesUrl(), [
                'charge_type_id' => $chargeType->id,
                'amount' => '100.00',
                'description' => 'Dinner service',
            ])
            ->assertCreated()
            ->assertJsonPath('data.charge.type', FolioLineItem::TYPE_ANCILLARY_CHARGE)
            ->assertJsonPath('data.charge.description', 'Dinner service')
            ->assertJsonPath('data.charge.amount', '100.00')
            ->assertJsonPath('data.charge.posted_by', $this->receptionist->id)
            ->assertJsonPath('data.charge.ancillary_charge_type.id', $chargeType->id)
            ->assertJsonPath('data.tax.type', FolioLineItem::TYPE_ANCILLARY_TAX)
            ->assertJsonPath('data.tax.amount', '8.25')
            ->assertJsonPath('data.balance', '108.25');

        $chargeId = $response->json('data.charge.id');
        $this->assertNotNull($response->json('data.charge.posted_at'));
        $this->assertDatabaseHas('folio_line_items', [
            'related_line_item_id' => $chargeId,
            'type' => FolioLineItem::TYPE_ANCILLARY_TAX,
            'amount' => '8.25',
            'tax_rate' => '8.25',
        ]);
    }

    public function test_inactive_or_cross_property_charge_type_cannot_be_posted(): void
    {
        $inactive = $this->createChargeType(['is_active' => false]);
        $otherProperty = Property::create([
            'name' => 'Other Hotel',
            'address' => '2 Main Street',
            'type' => 'hotel',
        ]);
        $other = AncillaryChargeType::create([
            'property_id' => $otherProperty->id,
            'code' => 'SPA',
            'name' => 'Spa',
            'tax_rate' => '5.00',
            'is_active' => true,
        ]);

        foreach ([$inactive, $other] as $chargeType) {
            $this->actingAs($this->receptionist, 'sanctum')
                ->postJson($this->chargesUrl(), [
                    'charge_type_id' => $chargeType->id,
                    'amount' => '20.00',
                ])
                ->assertNotFound();
        }

        $this->assertDatabaseCount('folio_line_items', 0);
    }

    public function test_adjustment_and_void_require_elevated_permission_and_preserve_ledger_history(): void
    {
        $charge = $this->postCharge($this->createChargeType());
        $adjustmentsUrl = "{$this->chargesUrl()}/{$charge->id}/adjustments";

        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson($adjustmentsUrl, [
                'action' => 'adjustment',
                'amount' => '-10.00',
                'reason' => 'Service recovery',
            ])
            ->assertForbidden();

        $this->booking->update(['user_id' => $this->receptionist->id]);
        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/v1/bookings/{$this->booking->id}/folio/line-items", [
                'type' => 'room_charge',
                'description' => 'Adjustment bypass',
                'amount' => '-10.00',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['amount']]]]);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson($adjustmentsUrl, [
                'action' => 'adjustment',
                'amount' => '-10.00',
                'reason' => 'Service recovery',
            ])
            ->assertCreated()
            ->assertJsonPath('data.entry.type', FolioLineItem::TYPE_ANCILLARY_ADJUSTMENT)
            ->assertJsonPath('data.entry.amount', '-10.00')
            ->assertJsonPath('data.tax.amount', '-0.83')
            ->assertJsonPath('data.balance', '97.42');

        $this->actingAs($this->manager, 'sanctum')
            ->postJson($adjustmentsUrl, [
                'action' => 'void',
                'reason' => 'Charge entered for wrong room',
            ])
            ->assertCreated()
            ->assertJsonPath('data.entry.type', FolioLineItem::TYPE_ANCILLARY_VOID)
            ->assertJsonPath('data.entry.amount', '-97.42')
            ->assertJsonPath('data.balance', '0.00');

        $this->assertSame('100.00', $charge->fresh()->amount);
        $this->assertSame(5, $this->folio->lineItems()->count());

        $this->actingAs($this->manager, 'sanctum')
            ->postJson($adjustmentsUrl, [
                'action' => 'void',
                'reason' => 'Duplicate attempt',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['line_item']]]]);

        $this->assertSame(5, $this->folio->lineItems()->count());
    }

    public function test_charges_require_an_active_stay_and_open_folio(): void
    {
        $chargeType = $this->createChargeType();
        $this->booking->update(['status' => Booking::STATUS_COMPLETED]);

        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson($this->chargesUrl(), [
                'charge_type_id' => $chargeType->id,
                'amount' => '20.00',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['status']]]]);

        $this->booking->update(['status' => Booking::STATUS_CHECKED_IN]);
        $this->folio->update(['status' => Folio::STATUS_CLOSED]);

        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson($this->chargesUrl(), [
                'charge_type_id' => $chargeType->id,
                'amount' => '20.00',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['folio']]]]);
    }

    private function createChargeType(array $overrides = []): AncillaryChargeType
    {
        return AncillaryChargeType::create([
            'property_id' => $this->property->id,
            'code' => 'ROOM_SERVICE',
            'name' => 'Room service',
            'tax_rate' => '8.25',
            'is_active' => true,
            ...$overrides,
        ]);
    }

    private function postCharge(AncillaryChargeType $chargeType): FolioLineItem
    {
        $response = $this->actingAs($this->receptionist, 'sanctum')
            ->postJson($this->chargesUrl(), [
                'charge_type_id' => $chargeType->id,
                'amount' => '100.00',
            ])
            ->assertCreated();

        return FolioLineItem::findOrFail($response->json('data.charge.id'));
    }

    private function assignRole(User $user, string $role): void
    {
        RoleAssignment::create([
            'user_id' => $user->id,
            'role_id' => Role::where('slug', $role)->value('id'),
            'property_id' => $this->property->id,
        ]);
    }

    private function chargesUrl(): string
    {
        return "/api/v1/bookings/{$this->booking->id}/folio/ancillary-charges";
    }
}
