<?php

namespace Modules\Booking\Services;

use Carbon\CarbonImmutable;
use Modules\Booking\Models\Booking;

class LateCheckoutFeeRule
{
    public function __construct(private readonly ReservationRateCalculator $rates) {}

    public function evaluate(Booking $booking, CarbonImmutable $checkedOutAt): array
    {
        $amountCents = $this->rates->amountCents((string) config('checkout.late_fee', '50.00'));
        $departureTime = (string) config('checkout.departure_time', '11:00');
        $scheduledDeparture = CarbonImmutable::parse(
            $booking->check_out->toDateString().' '.$departureTime,
            (string) config('app.timezone'),
        );
        $applicable = (bool) config('checkout.late_fee_enabled', true)
            && $amountCents > 0
            && $checkedOutAt->gt($scheduledDeparture);

        return [
            'applicable' => $applicable,
            'amount_cents' => $applicable ? $amountCents : 0,
            'amount' => $this->rates->formatCents($applicable ? $amountCents : 0),
        ];
    }
}
