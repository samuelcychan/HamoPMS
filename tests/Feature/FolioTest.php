<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Booking\Models\Booking;
use Modules\Folio\Models\Folio;
use Modules\Folio\Models\FolioLineItem;
use Modules\Property\Models\Property;
use Tests\TestCase;

class FolioTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->user = User::factory()->create();

        $property = Property::create([
            'name' => 'Test Hotel',
            'address' => '123 Main St',
            'type' => 'hotel',
        ]);

        $this->booking = Booking::create([
            'user_id' => $this->user->id,
            'property_id' => $property->id,
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'guests' => 2,
            'status' => 'confirmed',
        ]);
        RoleAssignment::create([
            'user_id' => $this->user->id,
            'role_id' => Role::where('slug', 'receptionist')->value('id'),
            'property_id' => $property->id,
        ]);
    }

    private function actingAsUser(): static
    {
        $token = $this->user->createToken('test-token')->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    public function test_guest_can_view_folio_for_their_booking(): void
    {
        $response = $this->actingAsUser()
            ->getJson("/api/v1/bookings/{$this->booking->id}/folio");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'folio' => ['id', 'booking_id', 'status', 'currency'],
                    'balance',
                ],
            ]);
    }

    public function test_folio_is_auto_created_on_first_access(): void
    {
        $this->assertDatabaseMissing('folios', ['booking_id' => $this->booking->id]);

        $this->actingAsUser()
            ->getJson("/api/v1/bookings/{$this->booking->id}/folio")
            ->assertOk();

        $this->assertDatabaseHas('folios', ['booking_id' => $this->booking->id, 'status' => 'open']);
    }

    public function test_can_post_room_charge_to_folio(): void
    {
        $response = $this->actingAsUser()
            ->postJson("/api/v1/bookings/{$this->booking->id}/folio/line-items", [
                'type' => 'room_charge',
                'description' => 'Room rate - Night 1',
                'amount' => '150.00',
            ]);

        $response->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'folio_id', 'type', 'description', 'amount', 'posted_at']])
            ->assertJsonFragment(['type' => 'room_charge', 'description' => 'Room rate - Night 1']);

        $this->assertDatabaseHas('folio_line_items', [
            'type' => 'room_charge',
            'description' => 'Room rate - Night 1',
            'amount' => '150.00',
        ]);
    }

    public function test_can_post_tax_to_folio(): void
    {
        $response = $this->actingAsUser()
            ->postJson("/api/v1/bookings/{$this->booking->id}/folio/line-items", [
                'type' => 'tax',
                'description' => 'State tax 10%',
                'amount' => '15.00',
            ]);

        $response->assertCreated()
            ->assertJsonFragment(['type' => 'tax']);
    }

    public function test_rejects_invalid_line_item_type(): void
    {
        $this->actingAsUser()
            ->postJson("/api/v1/bookings/{$this->booking->id}/folio/line-items", [
                'type' => 'invalid_type',
                'description' => 'Test',
                'amount' => '10.00',
            ])
            ->assertUnprocessable();
    }

    public function test_folio_balance_reflects_all_posted_charges(): void
    {
        $folio = Folio::create([
            'booking_id' => $this->booking->id,
            'status' => 'open',
            'currency' => 'USD',
        ]);

        $folio->lineItems()->create([
            'type' => 'room_charge',
            'description' => 'Room rate - Night 1',
            'amount' => '150.00',
        ]);

        $folio->lineItems()->create([
            'type' => 'room_charge',
            'description' => 'Room rate - Night 2',
            'amount' => '150.00',
        ]);

        $folio->lineItems()->create([
            'type' => 'tax',
            'description' => 'State tax 10%',
            'amount' => '30.00',
        ]);

        $response = $this->actingAsUser()
            ->getJson("/api/v1/bookings/{$this->booking->id}/folio");

        $response->assertOk()
            ->assertJsonFragment(['balance' => '330.00']);
    }

    public function test_folio_balance_is_zero_when_no_line_items(): void
    {
        $this->actingAsUser()
            ->getJson("/api/v1/bookings/{$this->booking->id}/folio")
            ->assertOk()
            ->assertJsonFragment(['balance' => '0.00']);
    }

    public function test_line_items_have_posted_at_timestamp(): void
    {
        $response = $this->actingAsUser()
            ->postJson("/api/v1/bookings/{$this->booking->id}/folio/line-items", [
                'type' => 'room_charge',
                'description' => 'Room rate',
                'amount' => '100.00',
            ]);

        $response->assertCreated();
        $this->assertNotNull($response->json('data.posted_at'));
    }

    public function test_cannot_access_another_users_folio(): void
    {
        $otherUser = User::factory()->create();
        RoleAssignment::create([
            'user_id' => $otherUser->id,
            'role_id' => Role::where('slug', 'receptionist')->value('id'),
            'property_id' => $this->booking->property_id,
        ]);
        $token = $otherUser->createToken('other-token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/bookings/{$this->booking->id}/folio")
            ->assertNotFound();
    }

    public function test_line_item_is_immutable_after_posting(): void
    {
        $folio = Folio::create([
            'booking_id' => $this->booking->id,
            'status' => 'open',
            'currency' => 'USD',
        ]);

        $lineItem = FolioLineItem::create([
            'folio_id' => $folio->id,
            'type' => 'room_charge',
            'description' => 'Room rate',
            'amount' => '100.00',
        ]);

        $this->expectException(\LogicException::class);
        $lineItem->update(['amount' => '200.00']);
    }

    public function test_closed_folio_cannot_receive_new_line_items(): void
    {
        Folio::create([
            'booking_id' => $this->booking->id,
            'status' => Folio::STATUS_CLOSED,
            'currency' => 'USD',
        ]);

        $this->actingAsUser()
            ->postJson("/api/v1/bookings/{$this->booking->id}/folio/line-items", [
                'type' => 'room_charge',
                'description' => 'Post-close charge',
                'amount' => '10.00',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['folio']]]]);

        $this->assertDatabaseCount('folio_line_items', 0);
    }
}
