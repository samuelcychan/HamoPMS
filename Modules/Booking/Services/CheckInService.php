<?php

namespace Modules\Booking\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Booking\Events\ReservationCheckedIn;
use Modules\Booking\Models\Booking;
use Modules\Property\Models\Room;
use Modules\Property\Services\RoomInventoryService;

class CheckInService
{
    public function __construct(private readonly RoomInventoryService $inventory) {}

    public function checkIn(int $bookingId, int $roomId, int $actorId): Booking
    {
        $booking = DB::transaction(function () use ($bookingId, $roomId, $actorId): Booking {
            $booking = Booking::query()->lockForUpdate()->findOrFail($bookingId);

            if ($booking->status !== Booking::STATUS_CONFIRMED) {
                throw ValidationException::withMessages([
                    'status' => ['Only a confirmed reservation can be checked in.'],
                ]);
            }

            $today = CarbonImmutable::today();

            if ($today->lt($booking->check_in)) {
                throw ValidationException::withMessages([
                    'check_in' => ['The reservation cannot be checked in before its arrival date.'],
                ]);
            }

            if (! $today->lt($booking->check_out)) {
                throw ValidationException::withMessages([
                    'check_out' => ['The reservation departure date has already passed.'],
                ]);
            }

            $room = Room::query()
                ->where('property_id', $booking->property_id)
                ->where('room_type_id', $booking->room_type_id)
                ->findOrFail($roomId);

            if ($room->status !== Room::STATUS_CLEAN) {
                throw ValidationException::withMessages([
                    'room_id' => ['The assigned room must be clean and ready.'],
                ]);
            }

            $this->inventory->transitionStatus(
                $booking->property_id,
                $room->id,
                Room::STATUS_OCCUPIED,
                $actorId,
                "Checked in reservation {$booking->id}.",
            );

            $booking->update([
                'room_id' => $room->id,
                'status' => Booking::STATUS_CHECKED_IN,
                'checked_in_at' => now(),
                'checked_in_by' => $actorId,
            ]);

            return $booking->load(['room.roomType', 'checkedInByUser']);
        });

        ReservationCheckedIn::dispatch($booking);

        return $booking;
    }
}
