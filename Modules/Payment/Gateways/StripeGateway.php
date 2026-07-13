<?php

namespace Modules\Payment\Gateways;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Modules\Payment\Contracts\PaymentGateway;
use Modules\Payment\Data\PaymentIntentRequest;
use Modules\Payment\Data\PaymentIntentResult;
use Modules\Payment\Enums\GatewayErrorCode;
use Modules\Payment\Exceptions\GatewayException;

class StripeGateway implements PaymentGateway
{
    public function __construct(
        private readonly Factory $http,
        private readonly string $secret,
        private readonly string $baseUrl,
    ) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function createPaymentIntent(PaymentIntentRequest $request): PaymentIntentResult
    {
        if ($this->secret === '') {
            throw new GatewayException(
                $this->name(),
                GatewayErrorCode::NOT_CONFIGURED,
                'The Stripe gateway is not configured.',
            );
        }

        try {
            $response = $this->http
                ->asForm()
                ->acceptJson()
                ->withToken($this->secret)
                ->withHeader('Idempotency-Key', $request->idempotencyKey)
                ->post(rtrim($this->baseUrl, '/').'/payment_intents', [
                    'amount' => $request->amountMinor,
                    'currency' => strtolower($request->currency),
                    'description' => $request->description,
                    'metadata' => $request->metadata,
                ]);
        } catch (ConnectionException $exception) {
            throw new GatewayException(
                $this->name(),
                GatewayErrorCode::UNAVAILABLE,
                'The Stripe gateway could not be reached.',
                true,
                previous: $exception,
            );
        }

        if (! $response->successful()) {
            throw $this->failedResponse($response);
        }

        $transactionId = $response->json('id');
        $status = $response->json('status');

        if (! is_string($transactionId) || $transactionId === '' || ! is_string($status) || $status === '') {
            throw new GatewayException(
                $this->name(),
                GatewayErrorCode::INVALID_RESPONSE,
                'Stripe returned an invalid payment intent response.',
            );
        }

        return new PaymentIntentResult(
            $this->name(),
            $transactionId,
            $status,
            $request->idempotencyKey,
        );
    }

    private function failedResponse(Response $response): GatewayException
    {
        $status = $response->status();
        $errorCode = match (true) {
            in_array($status, [401, 403], true) => GatewayErrorCode::AUTHENTICATION_FAILED,
            $status === 402 => GatewayErrorCode::DECLINED,
            $status === 429 => GatewayErrorCode::RATE_LIMITED,
            $status >= 500 => GatewayErrorCode::UNAVAILABLE,
            default => GatewayErrorCode::INVALID_REQUEST,
        };

        return new GatewayException(
            $this->name(),
            $errorCode,
            (string) ($response->json('error.message') ?: 'Stripe rejected the payment operation.'),
            $status === 429,
            [
                'http_status' => $status,
                'provider_code' => $response->json('error.code'),
                'provider_type' => $response->json('error.type'),
            ],
        );
    }
}
