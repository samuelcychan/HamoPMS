<?php

namespace Modules\Payment\Data;

class PaymentOperationResult
{
    public function __construct(
        public readonly string $gateway,
        public readonly string $transactionId,
        public readonly string $status,
        public readonly string $idempotencyKey,
        public readonly ?int $amountMinor = null,
    ) {}

    public function toArray(): array
    {
        return [
            'gateway' => $this->gateway,
            'transaction_id' => $this->transactionId,
            'status' => $this->status,
            'idempotency_key' => $this->idempotencyKey,
            'amount_minor' => $this->amountMinor,
        ];
    }
}
