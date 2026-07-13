<?php

namespace Modules\Booking\Services;

use Carbon\CarbonImmutable;

class ReservationRateCalculator
{
    public function totalCents(string $nightlyRate, CarbonImmutable $checkIn, CarbonImmutable $checkOut): int
    {
        return $this->amountCents($nightlyRate) * (int) $checkIn->diffInDays($checkOut);
    }

    public function formatCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($absolute, 100), $absolute % 100);
    }

    public function amountCents(string $amount): int
    {
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $amount)) {
            throw new \InvalidArgumentException('Room rates must be non-negative decimal amounts.');
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $fraction = str_pad($fraction, 2, '0');

        return ((int) $whole * 100) + (int) $fraction;
    }
}
