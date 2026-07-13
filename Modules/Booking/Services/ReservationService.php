<?php

namespace Modules\Booking\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Booking\Models\Booking;
use Modules\Property\Models\Property;

class ReservationService
{
    /**
     * Create and confirm a reservation, or return null when inventory is unavailable.
     *
     * The property lock serializes availability checks for the same property. This
     * remains safe even when there is no existing booking row available to lock.
     */
    public function createConfirmed(int $userId, array $attributes): ?Booking
    {
        return DB::transaction(function () use ($userId, $attributes): ?Booking {
            $checkIn = CarbonImmutable::parse($attributes['check_in'])->startOfDay();
            $checkOut = CarbonImmutable::parse($attributes['check_out'])->startOfDay();

            Property::query()
                ->whereKey($attributes['property_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $inventoryUnavailable = Booking::query()
                ->where('property_id', $attributes['property_id'])
                ->whereIn('status', Booking::INVENTORY_BLOCKING_STATUSES)
                ->where('check_in', '<', $checkOut)
                ->where('check_out', '>', $checkIn)
                ->exists();

            if ($inventoryUnavailable) {
                return null;
            }

            return Booking::create([
                ...$attributes,
                'user_id' => $userId,
                'status' => Booking::STATUS_CONFIRMED,
            ]);
        });
    }
}
