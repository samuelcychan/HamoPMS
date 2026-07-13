<?php

namespace Modules\Payment\Data;

use InvalidArgumentException;

class PaymentOperationRequest
{
    public function __construct(
        public readonly string $transactionId,
        public readonly string $idempotencyKey,
        public readonly ?int $amountMinor = null,
    ) {
        if ($transactionId === '') {
            throw new InvalidArgumentException('A gateway transaction id is required.');
        }

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 255) {
            throw new InvalidArgumentException('Payment operation idempotency key must contain 1 to 255 characters.');
        }

        if ($amountMinor !== null && $amountMinor < 1) {
            throw new InvalidArgumentException('Payment operation amount must be at least one minor unit.');
        }
    }
}
