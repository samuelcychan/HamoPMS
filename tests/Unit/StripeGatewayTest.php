<?php

namespace Tests\Unit;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Payment\Contracts\PaymentGateway;
use Modules\Payment\Data\PaymentIntentRequest;
use Modules\Payment\Enums\GatewayErrorCode;
use Modules\Payment\Exceptions\GatewayException;
use Modules\Payment\Gateways\StripeGateway;
use Modules\Payment\Support\PaymentIdempotencyKey;
use Tests\TestCase;

class StripeGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('payment.default', 'stripe');
        config()->set('payment.gateways.stripe.secret', 'sk_test_example');
        config()->set('payment.gateways.stripe.base_url', 'https://api.stripe.test/v1');
    }

    public function test_stripe_adapter_is_resolved_behind_gateway_contract(): void
    {
        $gateway = $this->app->make(PaymentGateway::class);

        $this->assertInstanceOf(StripeGateway::class, $gateway);
        $this->assertSame('stripe', $gateway->name());
    }

    public function test_payment_intent_uses_minor_units_and_idempotency_header(): void
    {
        Http::fake([
            'api.stripe.test/*' => Http::response([
                'id' => 'pi_123',
                'status' => 'requires_payment_method',
            ]),
        ]);
        $key = PaymentIdempotencyKey::for('payment_intent', 42);

        $result = $this->app->make(PaymentGateway::class)->createPaymentIntent(
            new PaymentIntentRequest(
                12550,
                'USD',
                $key,
                'Booking 42 deposit',
                ['booking_id' => '42'],
            ),
        );

        $this->assertSame([
            'gateway' => 'stripe',
            'transaction_id' => 'pi_123',
            'status' => 'requires_payment_method',
            'idempotency_key' => $key,
        ], $result->toArray());
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.stripe.test/v1/payment_intents'
            && $request->hasHeader('Authorization', 'Bearer sk_test_example')
            && $request->hasHeader('Idempotency-Key', $key)
            && $request['amount'] === 12550
            && $request['currency'] === 'usd'
            && $request['metadata']['booking_id'] === '42');
    }

    public function test_provider_failures_use_normalized_retryable_error_contract(): void
    {
        Http::fake([
            'api.stripe.test/*' => Http::response([
                'error' => [
                    'type' => 'api_error',
                    'code' => 'rate_limit',
                    'message' => 'Try again later.',
                ],
            ], 429),
        ]);

        try {
            $this->app->make(PaymentGateway::class)->createPaymentIntent(
                new PaymentIntentRequest(1000, 'USD', PaymentIdempotencyKey::for('payment_intent', 1)),
            );
            $this->fail('A gateway exception was not thrown.');
        } catch (GatewayException $exception) {
            $this->assertSame('stripe', $exception->gateway);
            $this->assertSame(GatewayErrorCode::RATE_LIMITED, $exception->errorCode);
            $this->assertTrue($exception->retryable);
            $this->assertSame(429, $exception->details['http_status']);
            $this->assertSame('rate_limit', $exception->details['provider_code']);
            $this->assertSame('GATEWAY_RATE_LIMITED', $exception->toArray()['code']);
        }
    }

    public function test_missing_gateway_credentials_fail_before_an_http_request(): void
    {
        config()->set('payment.gateways.stripe.secret', '');
        Http::fake();

        try {
            $this->app->make(PaymentGateway::class)->createPaymentIntent(
                new PaymentIntentRequest(1000, 'USD', PaymentIdempotencyKey::for('payment_intent', 1)),
            );
            $this->fail('A gateway exception was not thrown.');
        } catch (GatewayException $exception) {
            $this->assertSame(GatewayErrorCode::NOT_CONFIGURED, $exception->errorCode);
            $this->assertFalse($exception->retryable);
        }

        Http::assertNothingSent();
    }

    public function test_received_server_error_is_indeterminate_not_blindly_retryable(): void
    {
        Http::fake([
            'api.stripe.test/*' => Http::response([
                'error' => ['type' => 'api_error', 'message' => 'Internal failure.'],
            ], 500),
        ]);

        try {
            $this->app->make(PaymentGateway::class)->createPaymentIntent(
                new PaymentIntentRequest(1000, 'USD', PaymentIdempotencyKey::for('payment_intent', 1)),
            );
            $this->fail('A gateway exception was not thrown.');
        } catch (GatewayException $exception) {
            $this->assertSame(GatewayErrorCode::UNAVAILABLE, $exception->errorCode);
            $this->assertFalse($exception->retryable);
        }
    }

    public function test_idempotency_keys_are_stable_for_retries_and_versioned_for_new_operations(): void
    {
        $original = PaymentIdempotencyKey::for('payment_intent', 42);

        $this->assertSame($original, PaymentIdempotencyKey::for('payment_intent', 42));
        $this->assertNotSame($original, PaymentIdempotencyKey::for('payment_intent', 42, 2));
        $this->assertSame('hamopms:payment_intent:42:v1', $original);
    }
}
