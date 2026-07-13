<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Booking\Models\Booking;
use Modules\Folio\Models\Folio;
use Modules\Folio\Models\FolioLineItem;
use Modules\Payment\Models\Payment;
use Modules\Property\Models\Property;
use Modules\Property\Models\Room;
use Modules\Property\Models\RoomType;
use Tests\TestCase;

class PaymentSettlementTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Property $property;

    private Booking $booking;

    private Folio $folio;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-10 10:00:00');
        CarbonImmutable::setTestNow('2026-07-10 10:00:00');
        config()->set('payment.default', 'stripe');
        config()->set('payment.gateways.stripe.secret', 'sk_test_example');
        config()->set('payment.gateways.stripe.webhook_secret', 'whsec_example');
        config()->set('payment.gateways.stripe.base_url', 'https://api.stripe.test/v1');
        $this->fakeStripe();
        $this->seed(RolePermissionSeeder::class);
        $this->staff = User::factory()->create();
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
            'check_out' => now()->toDateString(),
            'guests' => 2,
            'status' => Booking::STATUS_CHECKED_IN,
            'checked_in_at' => now()->subDay(),
            'checked_in_by' => $this->staff->id,
            'nightly_rate' => '100.00',
        ]);
        $this->folio = Folio::create([
            'booking_id' => $this->booking->id,
            'status' => Folio::STATUS_OPEN,
            'currency' => 'USD',
        ]);
        $this->folio->lineItems()->create([
            'type' => FolioLineItem::TYPE_ROOM_RATE,
            'description' => 'Stay room rate',
            'amount' => '100.00',
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

    public function test_payment_intent_creation_is_idempotent(): void
    {
        $first = $this->createCardPayment('100.00', 'create-payment-1')
            ->assertCreated()
            ->assertJsonPath('data.gateway_payment_id', 'pi_10000')
            ->assertJsonPath('data.status', Payment::STATUS_REQUIRES_CAPTURE)
            ->assertJsonPath('meta.idempotent_replay', false);

        $this->createCardPayment('100.00', 'create-payment-1')
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('meta.idempotent_replay', true);

        $this->assertDatabaseCount('payments', 1);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Idempotency-Key', 'create-payment-1')
            && $request['capture_method'] === 'manual');

        $this->createCardPayment('90.00', 'create-payment-1')
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['idempotency_key']]]]);
    }

    public function test_split_capture_settles_the_folio_and_retry_does_not_double_charge(): void
    {
        $deposit = Payment::findOrFail($this->createCardPayment('30.00', 'create-deposit')->json('data.id'));
        $balance = Payment::findOrFail($this->createCardPayment('70.00', 'create-balance')->json('data.id'));

        $this->capture($deposit, 'capture-deposit')
            ->assertOk()
            ->assertJsonPath('data.captured_amount', '30.00')
            ->assertJsonPath('meta.idempotent_replay', false);
        $this->capture($deposit, 'capture-deposit')
            ->assertOk()
            ->assertJsonPath('data.captured_amount', '30.00')
            ->assertJsonPath('meta.idempotent_replay', true);
        $this->capture($balance, 'capture-balance')
            ->assertOk()
            ->assertJsonPath('data.captured_amount', '70.00');

        $this->assertDatabaseCount('payment_operations', 2);
        $this->assertSame('30.00', $deposit->fresh()->captured_amount);
        $this->assertSame('70.00', $balance->fresh()->captured_amount);
        $this->booking->update(['user_id' => $this->staff->id]);
        $this->actingAs($this->staff, 'sanctum')
            ->getJson("/api/v1/bookings/{$this->booking->id}/folio")
            ->assertOk()
            ->assertJsonCount(2, 'data.payments')
            ->assertJsonPath('data.settled_amount', '100.00')
            ->assertJsonPath('data.outstanding_balance', '0.00');
        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/bookings/{$this->booking->id}/check-out")
            ->assertOk()
            ->assertJsonPath('meta.checkout.outstanding_balance', '0.00');
        Http::assertSentCount(4);
    }

    public function test_partial_and_full_refunds_track_net_settlement(): void
    {
        $payment = Payment::findOrFail($this->createCardPayment('100.00', 'create-refund')->json('data.id'));
        $this->capture($payment, 'capture-refund')->assertOk();

        $this->operation($payment, 'refund', 'refund-30', ['amount' => '30.00'])
            ->assertOk()
            ->assertJsonPath('data.refunded_amount', '30.00')
            ->assertJsonPath('data.status', Payment::STATUS_PARTIALLY_REFUNDED);
        $this->operation($payment, 'refund', 'refund-30', ['amount' => '30.00'])
            ->assertOk()
            ->assertJsonPath('meta.idempotent_replay', true)
            ->assertJsonPath('data.refunded_amount', '30.00');
        $this->operation($payment, 'refund', 'refund-70', ['amount' => '70.00'])
            ->assertOk()
            ->assertJsonPath('data.refunded_amount', '100.00')
            ->assertJsonPath('data.status', Payment::STATUS_REFUNDED);

        $this->assertDatabaseCount('payment_operations', 3);
    }

    public function test_uncaptured_payment_can_be_voided(): void
    {
        $payment = Payment::findOrFail($this->createCardPayment('40.00', 'create-void')->json('data.id'));

        $this->operation($payment, 'void', 'void-payment')
            ->assertOk()
            ->assertJsonPath('data.status', Payment::STATUS_VOIDED)
            ->assertJsonPath('data.settlement_status', 'voided')
            ->assertJsonPath('meta.operation.performed_by', $this->staff->id);
    }

    public function test_payment_operations_cannot_cross_the_active_property_boundary(): void
    {
        $otherProperty = Property::create([
            'name' => 'Other Hotel',
            'address' => '2 Other Street',
            'type' => 'hotel',
        ]);
        $otherBooking = $this->booking->replicate();
        $otherBooking->property_id = $otherProperty->id;
        $otherBooking->save();
        $otherPayment = Payment::create([
            'user_id' => $this->staff->id,
            'booking_id' => $otherBooking->id,
            'amount' => '25.00',
            'currency' => 'USD',
            'method' => 'card',
            'gateway' => 'stripe',
            'gateway_payment_id' => 'pi_other_property',
            'idempotency_key' => 'create-other-property',
            'status' => Payment::STATUS_REQUIRES_CAPTURE,
            'settlement_status' => Payment::STATUS_REQUIRES_CAPTURE,
        ]);

        $this->capture($otherPayment, 'capture-other-property')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->assertDatabaseCount('payment_operations', 0);
        Http::assertNothingSent();
    }

    public function test_signed_webhook_is_recorded_once_and_reconciles_settlement(): void
    {
        $payment = Payment::findOrFail($this->createCardPayment('50.00', 'create-webhook')->json('data.id'));
        $payload = [
            'id' => 'evt_payment_succeeded',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => $payment->gateway_payment_id,
                    'amount_received' => 5000,
                ],
            ],
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_example');
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ];

        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], $server, $body)
            ->assertOk()
            ->assertJsonPath('data.event_id', 'evt_payment_succeeded');
        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], $server, $body)
            ->assertOk();

        $this->assertDatabaseCount('payment_webhook_events', 1);
        $this->assertSame(Payment::STATUS_COMPLETED, $payment->fresh()->status);
        $this->assertSame('50.00', $payment->fresh()->captured_amount);
    }

    public function test_refund_webhook_reconciles_out_of_order_capture_amount(): void
    {
        $payment = Payment::findOrFail($this->createCardPayment('50.00', 'create-refund-webhook')->json('data.id'));
        $payload = [
            'id' => 'evt_partial_refund',
            'type' => 'charge.refunded',
            'data' => [
                'object' => [
                    'id' => 'ch_partial_refund',
                    'payment_intent' => $payment->gateway_payment_id,
                    'amount' => 5000,
                    'amount_refunded' => 2000,
                ],
            ],
        ];

        $this->postStripeWebhook($payload)->assertOk();

        $payment->refresh();
        $this->assertSame(Payment::STATUS_PARTIALLY_REFUNDED, $payment->status);
        $this->assertSame('50.00', $payment->captured_amount);
        $this->assertSame('20.00', $payment->refunded_amount);
    }

    public function test_invalid_webhook_signature_is_rejected_without_recording_event(): void
    {
        $this->withHeader('Stripe-Signature', 't=1,v1=invalid')
            ->postJson('/api/v1/webhooks/stripe', ['id' => 'evt_invalid', 'type' => 'test'])
            ->assertBadRequest()
            ->assertJsonPath('error.code', 'INVALID_WEBHOOK_SIGNATURE');

        $this->assertDatabaseCount('payment_webhook_events', 0);
    }

    private function fakeStripe(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_ends_with($url, '/capture')) {
                return Http::response([
                    'id' => basename(dirname($url)),
                    'status' => 'succeeded',
                ]);
            }

            if (str_ends_with($url, '/cancel')) {
                return Http::response([
                    'id' => basename(dirname($url)),
                    'status' => 'canceled',
                ]);
            }

            if (str_ends_with($url, '/refunds')) {
                return Http::response(['id' => 're_123', 'status' => 'succeeded']);
            }

            return Http::response([
                'id' => 'pi_'.$request['amount'],
                'status' => Payment::STATUS_REQUIRES_CAPTURE,
            ]);
        });
    }

    private function createCardPayment(string $amount, string $key)
    {
        return $this->actingAs($this->staff, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/payments', [
                'booking_id' => $this->booking->id,
                'amount' => $amount,
                'currency' => 'USD',
                'method' => 'card',
            ]);
    }

    private function capture(Payment $payment, string $key)
    {
        return $this->operation($payment, 'capture', $key);
    }

    private function operation(Payment $payment, string $operation, string $key, array $payload = [])
    {
        return $this->actingAs($this->staff, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/payments/{$payment->id}/{$operation}", $payload);
    }

    private function postStripeWebhook(array $payload)
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_example');

        return $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $body);
    }
}
