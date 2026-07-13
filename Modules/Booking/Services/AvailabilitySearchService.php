<?php

namespace Modules\Booking\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Booking\Models\Booking;
use Modules\Property\Models\Room;
use Modules\Property\Models\RoomType;

class AvailabilitySearchService
{
    public const RESPONSE_TIME_TARGET_MS = 250;

    public function __construct(private readonly InventoryCounter $inventory) {}

    public function search(
        int $propertyId,
        string $checkIn,
        string $checkOut,
        int $occupancy,
    ): Collection {
        $checkInDate = CarbonImmutable::parse($checkIn)->startOfDay();
        $checkOutDate = CarbonImmutable::parse($checkOut)->startOfDay();

        $roomTypes = RoomType::query()
            ->where('property_id', $propertyId)
            ->where('is_active', true)
            ->where('max_occupancy', '>=', $occupancy)
            ->withCount([
                'rooms as total_inventory',
                'rooms as blocked_inventory' => fn ($query) => $query
                    ->where('status', Room::STATUS_OUT_OF_SERVICE),
            ])
            ->orderBy('id')
            ->get();

        $bookings = Booking::query()
            ->whereIn('room_type_id', $roomTypes->modelKeys())
            ->whereIn('status', Booking::INVENTORY_BLOCKING_STATUSES)
            ->where('check_in', '<', $checkOutDate)
            ->where('check_out', '>', $checkInDate)
            ->get(['room_type_id', 'check_in', 'check_out'])
            ->groupBy('room_type_id');

        return $roomTypes
            ->map(function (RoomType $roomType) use ($bookings, $checkInDate, $checkOutDate): array {
                $reservedInventory = $this->inventory->peakReservedInventory(
                    $bookings->get($roomType->id, collect()),
                    $checkInDate,
                    $checkOutDate,
                );
                $availableInventory = max(
                    0,
                    $roomType->total_inventory
                        - $roomType->blocked_inventory
                        - $reservedInventory,
                );

                return [
                    'room_type_id' => $roomType->id,
                    'property_id' => $roomType->property_id,
                    'name' => $roomType->name,
                    'code' => $roomType->code,
                    'description' => $roomType->description,
                    'max_occupancy' => $roomType->max_occupancy,
                    'base_rate' => $roomType->base_rate,
                    'total_inventory' => $roomType->total_inventory,
                    'blocked_inventory' => $roomType->blocked_inventory,
                    'reserved_inventory' => $reservedInventory,
                    'available_inventory' => $availableInventory,
                ];
            })
            ->filter(fn (array $roomType) => $roomType['available_inventory'] > 0)
            ->values();
    }
}
