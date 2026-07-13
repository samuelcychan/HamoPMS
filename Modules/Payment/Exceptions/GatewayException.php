<?php

namespace Modules\Payment\Exceptions;

use Modules\Payment\Enums\GatewayErrorCode;
use RuntimeException;
use Throwable;

class GatewayException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $gateway,
        public readonly GatewayErrorCode $errorCode,
        string $message,
        public readonly bool $retryable = false,
        public readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return array{gateway: string, code: string, message: string, retryable: bool, details: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'gateway' => $this->gateway,
            'code' => $this->errorCode->value,
            'message' => $this->getMessage(),
            'retryable' => $this->retryable,
            'details' => $this->details,
        ];
    }
}
