<?php

namespace Modules\Booking\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;

class InventoryCounter
{
    public function peakReservedInventory(
        iterable $bookings,
        CarbonImmutable $checkIn,
        CarbonImmutable $checkOut,
    ): int {
        $events = [];

        foreach ($bookings as $booking) {
            $bookingCheckIn = $this->date($booking->check_in);
            $bookingCheckOut = $this->date($booking->check_out);
            $start = $bookingCheckIn->greaterThan($checkIn) ? $bookingCheckIn : $checkIn;
            $end = $bookingCheckOut->lessThan($checkOut) ? $bookingCheckOut : $checkOut;
            $startKey = $start->toDateString();
            $endKey = $end->toDateString();

            $events[$startKey] = ($events[$startKey] ?? 0) + 1;
            $events[$endKey] = ($events[$endKey] ?? 0) - 1;
        }

        ksort($events);

        $current = 0;
        $peak = 0;

        foreach ($events as $change) {
            $current += $change;
            $peak = max($peak, $current);
        }

        return $peak;
    }

    private function date(DateTimeInterface|string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date)->startOfDay();
    }
}
