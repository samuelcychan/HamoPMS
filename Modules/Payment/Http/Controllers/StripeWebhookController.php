<?php

namespace Modules\Payment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Payment\Services\PaymentWebhookService;
use Modules\Payment\Services\StripeWebhookVerifier;

class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly StripeWebhookVerifier $verifier,
        private readonly PaymentWebhookService $webhooks,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $payload = $this->verifier->verify(
            $request->getContent(),
            (string) $request->header('Stripe-Signature'),
        );

        if ($payload === null || empty($payload['id']) || empty($payload['type'])) {
            return ApiResponse::error('INVALID_WEBHOOK_SIGNATURE', 'The webhook signature is invalid.', 400);
        }

        $event = $this->webhooks->handle('stripe', $payload);

        return ApiResponse::success([
            'received' => true,
            'event_id' => $event->event_id,
        ]);
    }
}
