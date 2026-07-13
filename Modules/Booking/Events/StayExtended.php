<?php

namespace Modules\Booking\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Booking\Models\Booking;

class StayExtended
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public string $previousCheckOut,
        public string $rateDifference,
    ) {}
}
