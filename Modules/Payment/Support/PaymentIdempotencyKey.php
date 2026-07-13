<?php

namespace Modules\Payment\Support;

use InvalidArgumentException;

class PaymentIdempotencyKey
{
    public static function for(string $operation, int|string $resourceId, int $version = 1): string
    {
        $operation = strtolower(trim($operation));
        $resourceId = trim((string) $resourceId);

        if (preg_match('/^[a-z][a-z0-9_-]*$/', $operation) !== 1) {
            throw new InvalidArgumentException('Payment operation must use a stable lowercase identifier.');
        }

        if ($resourceId === '' || str_contains($resourceId, ':')) {
            throw new InvalidArgumentException('Payment resource id must be non-empty and cannot contain a colon.');
        }

        if ($version < 1) {
            throw new InvalidArgumentException('Payment operation version must be at least one.');
        }

        return "hamopms:{$operation}:{$resourceId}:v{$version}";
    }
}
