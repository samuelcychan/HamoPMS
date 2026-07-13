<?php

namespace Modules\Booking\Services;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Booking\Models\Booking;
use Modules\Property\Models\RoomType;

class CancellationPolicyEngine
{
    public function __construct(private readonly ReservationRateCalculator $rates) {}

    public function evaluate(Booking $booking, RoomType $roomType, CarbonImmutable $now): array
    {
        $policyKey = $booking->cancellation_policy ?: config('cancellation.default');
        $policy = $booking->cancellation_policy_snapshot
            ?: config("cancellation.policies.{$policyKey}");

        if (! is_array($policy)) {
            throw new InvalidArgumentException("Unknown cancellation policy [{$policyKey}].");
        }

        $checkIn = CarbonImmutable::parse($booking->check_in)->startOfDay();
        $checkOut = CarbonImmutable::parse($booking->check_out)->startOfDay();
        $hoursBeforeCheckIn = (int) floor($now->diffInHours($checkIn, false));
        $nightlyRate = $booking->nightly_rate ?? $roomType->base_rate;
        $totalCents = $this->rates->totalCents($nightlyRate, $checkIn, $checkOut);
        $isNonRefundable = (bool) ($policy['non_refundable'] ?? false);
        $isFree = ! $isNonRefundable
            && $hoursBeforeCheckIn >= (int) ($policy['free_cancellation_hours'] ?? 0);
        $penaltyCents = $isFree ? 0 : $this->penaltyCents($policy, $nightlyRate, $totalCents);

        return [
            'policy' => $policyKey,
            'is_free' => $isFree,
            'is_non_refundable' => $isNonRefundable,
            'hours_before_check_in' => $hoursBeforeCheckIn,
            'room_total_cents' => $totalCents,
            'penalty_cents' => min($totalCents, max(0, $penaltyCents)),
        ];
    }

    private function penaltyCents(array $policy, string $nightlyRate, int $totalCents): int
    {
        return match ($policy['penalty_type'] ?? 'none') {
            'none' => 0,
            'first_night' => $this->rates->amountCents($nightlyRate),
            'fixed' => $this->rates->amountCents((string) ($policy['penalty_value'] ?? '0')),
            'percentage' => (int) round(
                $totalCents * (float) ($policy['penalty_value'] ?? 0) / 100,
            ),
            default => throw new InvalidArgumentException(
                "Unsupported cancellation penalty type [{$policy['penalty_type']}].",
            ),
        };
    }
}
