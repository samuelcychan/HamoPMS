<?php

namespace Modules\Payment\Services;

class StripeWebhookVerifier
{
    public function verify(string $payload, string $signatureHeader): ?array
    {
        $secret = (string) config('payment.gateways.stripe.webhook_secret');

        if ($secret === '' || $signatureHeader === '') {
            return null;
        }

        $parts = [];

        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);

            if ($key !== null && $value !== null) {
                $parts[$key][] = $value;
            }
        }

        $timestamp = isset($parts['t'][0]) ? (int) $parts['t'][0] : 0;
        $tolerance = (int) config('payment.gateways.stripe.webhook_tolerance', 300);

        if ($timestamp < 1 || abs(time() - $timestamp) > $tolerance) {
            return null;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        $valid = collect($parts['v1'] ?? [])->contains(
            fn (string $signature): bool => hash_equals($expected, $signature),
        );

        if (! $valid) {
            return null;
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }
}
