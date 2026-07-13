<?php

namespace Modules\Booking\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Booking\Events\EarlyDepartureScheduled;
use Modules\Booking\Events\ReservationRoomMoved;
use Modules\Booking\Events\StayExtended;
use Modules\Booking\Models\Booking;
use Modules\Property\Models\Room;
use Modules\Property\Models\RoomType;
use Modules\Property\Services\RoomInventoryService;

class InStayModificationService
{
    public function __construct(
        private readonly RoomInventoryService $rooms,
        private readonly InventoryCounter $inventory,
        private readonly ReservationRateCalculator $rates,
        private readonly ReservationFolioService $folios,
        private readonly BookingAuditSnapshot $audit,
    ) {}

    public function moveRoom(int $bookingId, int $newRoomId, int $actorId): ?array
    {
        $result = DB::transaction(function () use ($bookingId, $newRoomId, $actorId): ?array {
            $booking = Booking::query()->lockForUpdate()->findOrFail($bookingId);
            $this->assertCheckedIn($booking);
            $before = $this->audit->capture($booking);
            $fromRoomId = (int) $booking->room_id;
            $destinationRoom = Room::query()
                ->where('property_id', $booking->property_id)
                ->findOrFail($newRoomId);
            $roomTypes = RoomType::withTrashed()
                ->whereIn('id', [$booking->room_type_id, $destinationRoom->room_type_id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $oldRoomType = $roomTypes->get((int) $booking->room_type_id);
            $destinationType = $roomTypes->get((int) $destinationRoom->room_type_id);

            abort_unless($oldRoomType && $destinationType, 404);

            if (! $destinationType->is_active) {
                throw ValidationException::withMessages([
                    'room_id' => ['The destination room must be clean and active.'],
                ]);
            }

            $oldNightlyRate = $booking->nightly_rate ?? $oldRoomType->base_rate;

            if ((int) $booking->guests > $destinationType->max_occupancy) {
                throw ValidationException::withMessages([
                    'room_id' => ['The destination room type cannot accommodate the reservation.'],
                ]);
            }

            $today = CarbonImmutable::today();
            $checkOut = CarbonImmutable::parse($booking->check_out)->startOfDay();

            if (! $today->lt($checkOut)) {
                throw ValidationException::withMessages([
                    'check_out' => ['The stay has no remaining nights to move.'],
                ]);
            }

            if ((int) $destinationType->id !== (int) $booking->room_type_id
                && ! $this->roomTypeInventoryAvailable($booking, $destinationType, $today, $checkOut)) {
                return null;
            }

            $move = $this->rooms->moveOccupiedRoom(
                (int) $booking->property_id,
                $fromRoomId,
                $newRoomId,
                $actorId,
                "Moved reservation {$booking->id}.",
            );
            $newRoom = $move['to'];

            $remainingNights = (int) $today->diffInDays($checkOut);
            $differenceCents = (
                $this->rates->amountCents($newRoom->roomType->base_rate)
                - $this->rates->amountCents($oldNightlyRate)
            ) * $remainingNights;
            $oldCheckIn = CarbonImmutable::parse($booking->check_in)->startOfDay();
            $oldTotalCents = $this->rates->totalCents($oldNightlyRate, $oldCheckIn, $checkOut);

            $booking->update([
                'room_id' => $newRoom->id,
                'room_type_id' => $newRoom->room_type_id,
                'nightly_rate' => $newRoom->roomType->base_rate,
            ]);
            $modification = $this->recordModification(
                $booking,
                $actorId,
                $before,
                $differenceCents,
            );
            $folio = $this->folios->ensureRoomRateBaseline($booking, $oldTotalCents);
            $this->folios->postRateAdjustment($folio, $differenceCents, $modification->id);
            $rateDifference = $this->rates->formatCents($differenceCents);

            Log::info('stay.room_moved', [
                'actor_id' => $actorId,
                'booking_id' => $booking->id,
                'property_id' => $booking->property_id,
                'from_room_id' => $fromRoomId,
                'to_room_id' => $newRoom->id,
                'rate_difference' => $rateDifference,
            ]);

            return [
                'booking' => $booking->refresh()->load('room.roomType'),
                'from_room_id' => $fromRoomId,
                'to_room_id' => $newRoom->id,
                'modification_id' => $modification->id,
                'rate_difference' => $rateDifference,
            ];
        });

        if ($result === null) {
            return null;
        }

        ReservationRoomMoved::dispatch(
            $result['booking'],
            $result['from_room_id'],
            $result['to_room_id'],
            $result['rate_difference'],
        );

        return $result;
    }

    public function adjustDeparture(int $bookingId, string $newCheckOut, int $actorId): ?array
    {
        $result = DB::transaction(function () use ($bookingId, $newCheckOut, $actorId): ?array {
            $booking = Booking::query()->lockForUpdate()->findOrFail($bookingId);
            $this->assertCheckedIn($booking);
            $before = $this->audit->capture($booking);
            $previousCheckOut = CarbonImmutable::parse($booking->check_out)->startOfDay();
            $checkOut = CarbonImmutable::parse($newCheckOut)->startOfDay();
            $today = CarbonImmutable::today();

            if (! $checkOut->gt($today)) {
                throw ValidationException::withMessages([
                    'check_out' => ['The departure date must be after today.'],
                ]);
            }

            if ($checkOut->equalTo($previousCheckOut)) {
                throw ValidationException::withMessages([
                    'check_out' => ['The departure date must change.'],
                ]);
            }

            $isExtension = $checkOut->gt($previousCheckOut);
            $roomType = RoomType::withTrashed()
                ->whereKey($booking->room_type_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($isExtension && ! $this->roomTypeInventoryAvailable($booking, $roomType, $previousCheckOut, $checkOut)) {
                return null;
            }

            $nightlyRate = $booking->nightly_rate ?? $roomType->base_rate;
            $changedNights = (int) abs($previousCheckOut->diffInDays($checkOut));
            $differenceCents = $this->rates->amountCents($nightlyRate)
                * $changedNights
                * ($isExtension ? 1 : -1);
            $oldCheckIn = CarbonImmutable::parse($booking->check_in)->startOfDay();
            $oldTotalCents = $this->rates->totalCents($nightlyRate, $oldCheckIn, $previousCheckOut);

            $booking->update(['check_out' => $checkOut->toDateString()]);
            $modification = $this->recordModification(
                $booking,
                $actorId,
                $before,
                $differenceCents,
            );
            $folio = $this->folios->ensureRoomRateBaseline($booking, $oldTotalCents);
            $this->folios->postRateAdjustment($folio, $differenceCents, $modification->id);
            $rateDifference = $this->rates->formatCents($differenceCents);
            $changeType = $isExtension ? 'extension' : 'early_departure';

            Log::info("stay.{$changeType}", [
                'actor_id' => $actorId,
                'booking_id' => $booking->id,
                'property_id' => $booking->property_id,
                'previous_check_out' => $previousCheckOut->toDateString(),
                'check_out' => $checkOut->toDateString(),
                'rate_difference' => $rateDifference,
            ]);

            return [
                'booking' => $booking->refresh()->load('room.roomType'),
                'change_type' => $changeType,
                'previous_check_out' => $previousCheckOut->toDateString(),
                'modification_id' => $modification->id,
                'rate_difference' => $rateDifference,
            ];
        });

        if ($result === null) {
            return null;
        }

        if ($result['change_type'] === 'extension') {
            StayExtended::dispatch(
                $result['booking'],
                $result['previous_check_out'],
                $result['rate_difference'],
            );
        } else {
            EarlyDepartureScheduled::dispatch(
                $result['booking'],
                $result['previous_check_out'],
                $result['rate_difference'],
            );
        }

        return $result;
    }

    private function assertCheckedIn(Booking $booking): void
    {
        if ($booking->status !== Booking::STATUS_CHECKED_IN || $booking->room_id === null) {
            throw ValidationException::withMessages([
                'status' => ['Only checked-in reservations can use in-stay modifications.'],
            ]);
        }
    }

    private function roomTypeInventoryAvailable(
        Booking $booking,
        RoomType $roomType,
        CarbonImmutable $currentCheckOut,
        CarbonImmutable $newCheckOut,
    ): bool {
        $sellableInventory = $roomType->rooms()
            ->where('status', '!=', Room::STATUS_OUT_OF_SERVICE)
            ->count();
        $overlappingBookings = Booking::query()
            ->where('id', '!=', $booking->id)
            ->where('room_type_id', $roomType->id)
            ->whereIn('status', Booking::INVENTORY_BLOCKING_STATUSES)
            ->where('check_in', '<', $newCheckOut)
            ->where('check_out', '>', $currentCheckOut)
            ->get(['check_in', 'check_out']);

        return $this->inventory->peakReservedInventory(
            $overlappingBookings,
            $currentCheckOut,
            $newCheckOut,
        ) < $sellableInventory;
    }

    private function recordModification(
        Booking $booking,
        int $actorId,
        array $before,
        int $differenceCents,
    ) {
        return $booking->modifications()->create([
            'actor_id' => $actorId,
            'before' => $before,
            'after' => $this->audit->capture($booking->refresh()),
            'rate_difference' => $this->rates->formatCents($differenceCents),
        ]);
    }
}
