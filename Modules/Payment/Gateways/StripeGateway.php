<?php

namespace Modules\Payment\Gateways;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Modules\Payment\Contracts\PaymentGateway;
use Modules\Payment\Data\PaymentIntentRequest;
use Modules\Payment\Data\PaymentIntentResult;
use Modules\Payment\Data\PaymentOperationRequest;
use Modules\Payment\Data\PaymentOperationResult;
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
                    'capture_method' => 'manual',
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

    public function capture(PaymentOperationRequest $request): PaymentOperationResult
    {
        $payload = $request->amountMinor === null ? [] : ['amount_to_capture' => $request->amountMinor];
        $response = $this->operationRequest(
            "/payment_intents/{$request->transactionId}/capture",
            $request->idempotencyKey,
            $payload,
        );

        return $this->operationResult($response, $request, $request->amountMinor);
    }

    public function refund(PaymentOperationRequest $request): PaymentOperationResult
    {
        $payload = ['payment_intent' => $request->transactionId];

        if ($request->amountMinor !== null) {
            $payload['amount'] = $request->amountMinor;
        }

        $response = $this->operationRequest('/refunds', $request->idempotencyKey, $payload);

        return $this->operationResult($response, $request, $request->amountMinor);
    }

    public function void(PaymentOperationRequest $request): PaymentOperationResult
    {
        $response = $this->operationRequest(
            "/payment_intents/{$request->transactionId}/cancel",
            $request->idempotencyKey,
        );

        return $this->operationResult($response, $request);
    }

    private function operationRequest(string $path, string $idempotencyKey, array $payload = []): Response
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
                ->withHeader('Idempotency-Key', $idempotencyKey)
                ->post(rtrim($this->baseUrl, '/').$path, $payload);
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

        return $response;
    }

    private function operationResult(
        Response $response,
        PaymentOperationRequest $request,
        ?int $amountMinor = null,
    ): PaymentOperationResult {
        $transactionId = $response->json('id');
        $status = $response->json('status');

        if (! is_string($transactionId) || $transactionId === '' || ! is_string($status) || $status === '') {
            throw new GatewayException(
                $this->name(),
                GatewayErrorCode::INVALID_RESPONSE,
                'Stripe returned an invalid payment operation response.',
            );
        }

        return new PaymentOperationResult(
            $this->name(),
            $transactionId,
            $status,
            $request->idempotencyKey,
            $amountMinor,
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
