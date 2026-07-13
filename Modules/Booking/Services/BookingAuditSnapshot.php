<?php

namespace Modules\Booking\Services;

use Modules\Booking\Models\Booking;

class BookingAuditSnapshot
{
    public function capture(Booking $booking): array
    {
        return [
            'room_type_id' => (int) $booking->room_type_id,
            'room_id' => $booking->room_id === null ? null : (int) $booking->room_id,
            'check_in' => $booking->check_in->toDateString(),
            'check_out' => $booking->check_out->toDateString(),
            'guests' => (int) $booking->guests,
            'notes' => $booking->notes,
            'special_requests' => $booking->special_requests,
            'nightly_rate' => $booking->nightly_rate,
            'status' => $booking->status,
        ];
    }
}
