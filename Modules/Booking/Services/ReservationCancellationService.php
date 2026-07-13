<?php

namespace Modules\Booking\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Booking\Events\ReservationCancelled;
use Modules\Booking\Models\Booking;
use Modules\Property\Models\RoomType;

class ReservationCancellationService
{
    public function __construct(
        private readonly CancellationPolicyEngine $policies,
        private readonly ReservationRateCalculator $rates,
        private readonly ReservationFolioService $folios,
    ) {}

    public function cancel(int $bookingId, int $actorId): array
    {
        $result = DB::transaction(function () use ($bookingId, $actorId): array {
            $booking = Booking::query()->lockForUpdate()->findOrFail($bookingId);

            if (! in_array($booking->status, [Booking::STATUS_PENDING, Booking::STATUS_CONFIRMED], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Only pending or confirmed reservations can be cancelled.'],
                ]);
            }

            $roomType = RoomType::withTrashed()->lockForUpdate()->findOrFail($booking->room_type_id);
            $evaluation = $this->policies->evaluate($booking, $roomType, CarbonImmutable::now());
            $penalty = $this->rates->formatCents($evaluation['penalty_cents']);

            $this->folios->settleCancellation(
                $booking,
                $evaluation['room_total_cents'],
                $evaluation['penalty_cents'],
            );

            $booking->update([
                'status' => Booking::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $actorId,
                'cancellation_penalty' => $penalty,
            ]);

            Log::info('reservation.cancelled', [
                'actor_id' => $actorId,
                'booking_id' => $booking->id,
                'property_id' => $booking->property_id,
                'policy' => $evaluation['policy'],
                'penalty' => $penalty,
                'hours_before_check_in' => $evaluation['hours_before_check_in'],
            ]);

            return [
                'booking' => $booking->refresh(),
                'evaluation' => $evaluation,
                'penalty' => $penalty,
            ];
        });

        ReservationCancelled::dispatch(
            $result['booking'],
            $result['evaluation']['policy'],
            $result['penalty'],
        );

        return $result;
    }
}
