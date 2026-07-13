<?php

namespace Modules\Booking\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Booking\Models\Booking;
use Modules\Property\Models\Room;
use Modules\Property\Models\RoomType;

class ReservationModificationService
{
    public const MODIFIABLE_STATUSES = [
        Booking::STATUS_PENDING,
        Booking::STATUS_CONFIRMED,
    ];

    public function __construct(
        private readonly InventoryCounter $inventory,
        private readonly ReservationRateCalculator $rates,
        private readonly ReservationFolioService $folios,
    ) {}

    public function modify(int $bookingId, int $actorId, array $attributes): ?array
    {
        return DB::transaction(function () use ($bookingId, $actorId, $attributes): ?array {
            $booking = Booking::query()->lockForUpdate()->findOrFail($bookingId);

            if (! in_array($booking->status, self::MODIFIABLE_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => ['Only pending or confirmed reservations can be modified.'],
                ]);
            }

            $before = $this->snapshot($booking);
            $checkIn = CarbonImmutable::parse($attributes['check_in'] ?? $booking->check_in)->startOfDay();
            $checkOut = CarbonImmutable::parse($attributes['check_out'] ?? $booking->check_out)->startOfDay();

            if ($checkIn->isBefore(today())) {
                throw ValidationException::withMessages([
                    'check_in' => ['The check in date must be today or later.'],
                ]);
            }

            if (! $checkOut->isAfter($checkIn)) {
                throw ValidationException::withMessages([
                    'check_out' => ['The check out date must be after check in.'],
                ]);
            }

            $newRoomTypeId = (int) ($attributes['room_type_id'] ?? $booking->room_type_id);
            $roomTypes = RoomType::withTrashed()
                ->whereIn('id', array_unique([(int) $booking->room_type_id, $newRoomTypeId]))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $oldRoomType = $roomTypes->get((int) $booking->room_type_id);
            $newRoomType = $roomTypes->get($newRoomTypeId);

            if (! $oldRoomType || ! $newRoomType
                || (int) $newRoomType->property_id !== (int) $booking->property_id
                || ! $newRoomType->is_active
                || $newRoomType->trashed()) {
                throw ValidationException::withMessages([
                    'room_type_id' => ['The selected room type is not available for this property.'],
                ]);
            }

            $guests = (int) ($attributes['occupancy'] ?? $attributes['guests'] ?? $booking->guests);

            if ($guests > $newRoomType->max_occupancy) {
                throw ValidationException::withMessages([
                    'occupancy' => ['The occupancy exceeds the selected room type capacity.'],
                ]);
            }

            $oldCheckIn = CarbonImmutable::parse($booking->check_in)->startOfDay();
            $oldCheckOut = CarbonImmutable::parse($booking->check_out)->startOfDay();
            $inventoryChanged = $newRoomType->id !== $booking->room_type_id
                || ! $checkIn->equalTo($oldCheckIn)
                || ! $checkOut->equalTo($oldCheckOut);

            if ($inventoryChanged) {
                $sellableInventory = $newRoomType->rooms()
                    ->where('status', '!=', Room::STATUS_OUT_OF_SERVICE)
                    ->count();
                $overlappingBookings = Booking::query()
                    ->where('id', '!=', $booking->id)
                    ->where('room_type_id', $newRoomType->id)
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
            }

            $oldTotalCents = $this->rates->totalCents($oldRoomType->base_rate, $oldCheckIn, $oldCheckOut);
            $newTotalCents = $this->rates->totalCents($newRoomType->base_rate, $checkIn, $checkOut);
            $differenceCents = $newTotalCents - $oldTotalCents;

            $booking->update([
                'room_type_id' => $newRoomType->id,
                'check_in' => $checkIn->toDateString(),
                'check_out' => $checkOut->toDateString(),
                'guests' => $guests,
                'notes' => array_key_exists('notes', $attributes) ? $attributes['notes'] : $booking->notes,
                'special_requests' => array_key_exists('special_requests', $attributes)
                    ? $attributes['special_requests']
                    : $booking->special_requests,
            ]);

            $modification = $booking->modifications()->create([
                'actor_id' => $actorId,
                'before' => $before,
                'after' => $this->snapshot($booking->refresh()),
                'rate_difference' => $this->rates->formatCents($differenceCents),
            ]);
            $folio = $this->folios->ensureRoomRateBaseline($booking, $oldTotalCents);
            $this->folios->postRateAdjustment($folio, $differenceCents, $modification->id);

            Log::info('reservation.modified', [
                'actor_id' => $actorId,
                'booking_id' => $booking->id,
                'property_id' => $booking->property_id,
                'modification_id' => $modification->id,
                'rate_difference' => $this->rates->formatCents($differenceCents),
            ]);

            return [
                'booking' => $booking,
                'modification' => $modification,
                'rate_difference' => $this->rates->formatCents($differenceCents),
            ];
        });
    }

    private function snapshot(Booking $booking): array
    {
        return [
            'room_type_id' => (int) $booking->room_type_id,
            'check_in' => $booking->check_in->toDateString(),
            'check_out' => $booking->check_out->toDateString(),
            'guests' => (int) $booking->guests,
            'notes' => $booking->notes,
            'special_requests' => $booking->special_requests,
            'status' => $booking->status,
        ];
    }
}
