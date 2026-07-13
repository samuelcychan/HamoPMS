<?php

namespace Modules\Booking\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Booking\Models\Booking;
use Modules\Property\Models\Room;
use Modules\Property\Models\RoomType;

class ReservationService
{
    public function __construct(
        private readonly InventoryCounter $inventory,
        private readonly ReservationRateCalculator $rates,
        private readonly ReservationFolioService $folios,
    ) {}

    /**
     * Create and confirm a reservation, or return null when inventory is unavailable.
     *
     * The room-type lock serializes availability checks for the same inventory
     * pool, including when there is no existing booking row available to lock.
     */
    public function createConfirmed(int $userId, array $attributes): ?Booking
    {
        return DB::transaction(function () use ($userId, $attributes): ?Booking {
            $checkIn = CarbonImmutable::parse($attributes['check_in'])->startOfDay();
            $checkOut = CarbonImmutable::parse($attributes['check_out'])->startOfDay();

            $roomType = RoomType::query()
                ->whereKey($attributes['room_type_id'])
                ->where('property_id', $attributes['property_id'])
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();

            $sellableInventory = $roomType->rooms()
                ->where('status', '!=', Room::STATUS_OUT_OF_SERVICE)
                ->count();

            if ((int) $attributes['guests'] > $roomType->max_occupancy) {
                throw ValidationException::withMessages([
                    'guests' => ['The guest count exceeds the selected room type capacity.'],
                ]);
            }

            $overlappingBookings = Booking::query()
                ->where('room_type_id', $roomType->id)
                ->whereIn('status', Booking::INVENTORY_BLOCKING_STATUSES)
                ->where('check_in', '<', $checkOut)
                ->where('check_out', '>', $checkIn)
                ->get(['check_in', 'check_out']);
            $reservedInventory = $this->inventory->peakReservedInventory(
                $overlappingBookings,
                $checkIn,
                $checkOut,
            );

            if ($reservedInventory >= $sellableInventory) {
                return null;
            }

            $booking = Booking::create([
                ...$attributes,
                'user_id' => $userId,
                'status' => Booking::STATUS_CONFIRMED,
                'nightly_rate' => $roomType->base_rate,
            ]);
            $totalCents = $this->rates->totalCents($roomType->base_rate, $checkIn, $checkOut);
            $this->folios->ensureRoomRateBaseline($booking, $totalCents);

            return $booking;
        });
    }
}
