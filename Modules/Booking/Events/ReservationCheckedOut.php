<?php

namespace Modules\Booking\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Booking\Models\Booking;

class ReservationCheckedOut
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public int $folioId,
        public string $lateCheckoutFee,
    ) {}
}
