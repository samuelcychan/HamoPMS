<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Modules\Booking\Models\Booking;
use Modules\Notification\Jobs\DeliverGuestNotification;
use Modules\Notification\Mail\GuestLifecycleMail;
use Modules\Notification\Models\NotificationDelivery;
use Modules\Payment\Contracts\PaymentGateway;
use Modules\Payment\Data\PaymentOperationResult;
use Modules\Payment\Events\PaymentReceiptIssued;
use Modules\Payment\Models\Payment;
use Modules\Payment\Services\PaymentSettlementService;
use Modules\Property\Models\Property;
use Modules\Property\Models\Room;
use Modules\Property\Models\RoomType;
use RuntimeException;
use Tests\TestCase;

class GuestNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $guest;

    private Property $property;

    private RoomType $roomType;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('queue.default', 'sync');
        config()->set('app.locale', 'en');
        $this->seed(RolePermissionSeeder::class);
        $this->guest = User::factory()->create(['name' => 'Alex Guest', 'email' => 'alex@example.com']);
        $this->property = Property::create([
            'name' => 'Harbour Hotel',
            'address' => '1 Main Street',
            'type' => 'hotel',
        ]);
        $this->roomType = RoomType::create([
            'property_id' => $this->property->id,
            'name' => 'Deluxe Room',
            'code' => 'DLX',
            'max_occupancy' => 3,
            'base_rate' => '250.00',
            'is_active' => true,
        ]);
        Room::create([
            'property_id' => $this->property->id,
            'room_type_id' => $this->roomType->id,
            'number' => '101',
            'status' => Room::STATUS_CLEAN,
        ]);
        RoleAssignment::create([
            'user_id' => $this->guest->id,
            'role_id' => Role::where('slug', 'receptionist')->value('id'),
            'property_id' => $this->property->id,
        ]);
    }

    public function test_reservation_and_payment_events_send_localized_templates_and_log_delivery(): void
    {
        Mail::fake();
        $bookingId = $this->actingAs($this->guest, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'property_id' => $this->property->id,
                'room_type_id' => $this->roomType->id,
                'check_in' => now()->addDays(4)->toDateString(),
                'check_out' => now()->addDays(6)->toDateString(),
                'guests' => 2,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->guest, 'sanctum')
            ->putJson("/api/v1/bookings/{$bookingId}", [
                'check_out' => now()->addDays(7)->toDateString(),
            ])
            ->assertOk();
        $this->actingAs($this->guest, 'sanctum')
            ->postJson("/api/v1/bookings/{$bookingId}/cancel")
            ->assertOk();

        $paymentBooking = Booking::create([
            'user_id' => $this->guest->id,
            'property_id' => $this->property->id,
            'room_type_id' => $this->roomType->id,
            'check_in' => now()->addDays(10)->toDateString(),
            'check_out' => now()->addDays(11)->toDateString(),
            'guests' => 1,
            'status' => Booking::STATUS_CONFIRMED,
            'nightly_rate' => '250.00',
        ]);
        $payment = Payment::create([
            'user_id' => $this->guest->id,
            'booking_id' => $paymentBooking->id,
            'amount' => '50.00',
            'currency' => 'USD',
            'method' => 'card',
            'gateway' => 'stripe',
            'gateway_payment_id' => 'pi_notification',
            'idempotency_key' => 'create-notification-payment',
            'status' => Payment::STATUS_REQUIRES_CAPTURE,
            'settlement_status' => Payment::STATUS_REQUIRES_CAPTURE,
        ]);
        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('name')->once()->andReturn('stripe');
        $gateway->shouldReceive('capture')->once()->andReturn(new PaymentOperationResult(
            'stripe',
            'ch_notification',
            'succeeded',
            'capture-notification-payment',
            5000,
        ));
        $this->app->instance(PaymentGateway::class, $gateway);
        $this->app->make(PaymentSettlementService::class)->capture(
            $payment->id,
            null,
            'capture-notification-payment',
            $this->guest->id,
        );

        $this->assertDatabaseCount('notification_deliveries', 4);
        foreach ([
            'reservation_confirmation',
            'reservation_modification',
            'reservation_cancellation',
            'payment_receipt',
        ] as $template) {
            $this->assertDatabaseHas('notification_deliveries', [
                'template_key' => $template,
                'recipient' => 'alex@example.com',
                'locale' => 'en',
                'status' => NotificationDelivery::STATUS_SENT,
                'attempts' => 1,
            ]);
        }
        Mail::assertSent(GuestLifecycleMail::class, 4);
        Mail::assertSent(
            GuestLifecycleMail::class,
            fn (GuestLifecycleMail $mail): bool => $mail->hasTo('alex@example.com')
                && str_contains($mail->messageSubject, "Reservation #{$bookingId} confirmed")
                && str_contains($mail->messageBody, 'Harbour Hotel')
                && ! str_contains($mail->messageBody, ':guest_name'),
        );
        Mail::assertSent(
            GuestLifecycleMail::class,
            fn (GuestLifecycleMail $mail): bool => str_contains($mail->messageSubject, 'Payment receipt')
                && str_contains($mail->messageBody, '50.00 USD'),
        );

        PaymentReceiptIssued::dispatch($payment->fresh());
        $this->assertDatabaseCount('notification_deliveries', 4);
        Mail::assertSent(GuestLifecycleMail::class, 4);
    }

    public function test_terminal_queue_failure_is_persisted_for_operations(): void
    {
        $booking = Booking::create([
            'user_id' => $this->guest->id,
            'property_id' => $this->property->id,
            'room_type_id' => $this->roomType->id,
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(2)->toDateString(),
            'guests' => 1,
            'status' => Booking::STATUS_CONFIRMED,
        ]);
        $delivery = NotificationDelivery::create([
            'property_id' => $this->property->id,
            'booking_id' => $booking->id,
            'deduplication_key' => "failure-test:{$booking->id}",
            'channel' => 'email',
            'template_key' => 'reservation_confirmation',
            'locale' => 'en',
            'recipient' => $this->guest->email,
            'subject' => 'Test subject',
            'payload' => ['body' => 'Test body'],
            'status' => NotificationDelivery::STATUS_PENDING,
            'queued_at' => now(),
        ]);
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP unavailable'));
        $job = new DeliverGuestNotification($delivery->id);

        try {
            $job->handle();
            $this->fail('Expected notification delivery to fail.');
        } catch (RuntimeException $exception) {
            $job->failed($exception);
        }

        $delivery->refresh();
        $this->assertSame(NotificationDelivery::STATUS_FAILED, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame('SMTP unavailable', $delivery->error_message);
        $this->assertNotNull($delivery->failed_at);
    }
}
