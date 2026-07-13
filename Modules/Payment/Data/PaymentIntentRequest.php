<?php

namespace Modules\Payment\Data;

use InvalidArgumentException;

class PaymentIntentRequest
{
    /**
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $idempotencyKey,
        public readonly ?string $description = null,
        public readonly array $metadata = [],
    ) {
        if ($amountMinor < 1) {
            throw new InvalidArgumentException('Payment intent amount must be at least one minor unit.');
        }

        if (preg_match('/^[A-Za-z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException('Payment intent currency must be a three-letter ISO code.');
        }

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 255) {
            throw new InvalidArgumentException('Payment intent idempotency key must contain 1 to 255 characters.');
        }
    }
}
