<?php

namespace Modules\Booking\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Booking\Events\ReservationCheckedOut;
use Modules\Booking\Models\Booking;
use Modules\Folio\Models\Folio;
use Modules\Folio\Models\FolioLineItem;
use Modules\Payment\Models\Payment;
use Modules\Property\Models\Room;
use Modules\Property\Services\RoomInventoryService;

class CheckOutService
{
    public function __construct(
        private readonly RoomInventoryService $rooms,
        private readonly LateCheckoutFeeRule $lateFeeRule,
        private readonly ReservationRateCalculator $rates,
    ) {}

    public function checkOut(int $bookingId, int $actorId): array
    {
        $result = DB::transaction(function () use ($bookingId, $actorId): array {
            $booking = Booking::query()->lockForUpdate()->findOrFail($bookingId);

            if ($booking->status !== Booking::STATUS_CHECKED_IN || $booking->room_id === null) {
                throw ValidationException::withMessages([
                    'status' => ['Only a checked-in reservation can be checked out.'],
                ]);
            }

            $folio = Folio::query()->where('booking_id', $booking->id)->lockForUpdate()->first();

            if ($folio === null) {
                $folio = Folio::create([
                    'booking_id' => $booking->id,
                    'status' => Folio::STATUS_OPEN,
                    'currency' => 'USD',
                ]);
            }

            if ($folio->status !== Folio::STATUS_OPEN) {
                throw ValidationException::withMessages([
                    'folio' => ['Only an open folio can be finalized at check-out.'],
                ]);
            }

            $lateFee = $this->lateFeeRule->evaluate($booking, CarbonImmutable::now());

            if ($lateFee['applicable'] && ! $folio->lineItems()->where('type', FolioLineItem::TYPE_LATE_CHECKOUT_FEE)->exists()) {
                $folio->lineItems()->create([
                    'type' => FolioLineItem::TYPE_LATE_CHECKOUT_FEE,
                    'description' => 'Late check-out fee',
                    'amount' => $lateFee['amount'],
                ]);
            }

            $chargeCents = $folio->lineItems()
                ->lockForUpdate()
                ->get(['amount'])
                ->sum(fn (FolioLineItem $item): int => $this->signedAmountCents($item->amount));
            $paymentCents = Payment::query()
                ->where('booking_id', $booking->id)
                ->where('currency', $folio->currency)
                ->where('status', Payment::STATUS_COMPLETED)
                ->lockForUpdate()
                ->get(['amount'])
                ->sum(fn (Payment $payment): int => $this->rates->amountCents($payment->amount));
            $outstandingCents = $chargeCents - $paymentCents;
            $lateFeeAmount = $folio->lineItems()
                ->where('type', FolioLineItem::TYPE_LATE_CHECKOUT_FEE)
                ->value('amount') ?? '0.00';

            if ($outstandingCents !== 0) {
                return [
                    'checked_out' => false,
                    'booking' => $booking,
                    'folio' => $folio->refresh(),
                    'outstanding_balance' => $this->rates->formatCents($outstandingCents),
                    'late_checkout_fee' => $lateFeeAmount,
                ];
            }

            $this->rooms->transitionStatus(
                (int) $booking->property_id,
                (int) $booking->room_id,
                Room::STATUS_DIRTY,
                $actorId,
                "Checked out reservation {$booking->id}.",
            );

            $folio->update(['status' => Folio::STATUS_CLOSED]);
            $booking->update([
                'status' => Booking::STATUS_COMPLETED,
                'checked_out_at' => now(),
                'checked_out_by' => $actorId,
            ]);

            Log::info('reservation.checked_out', [
                'actor_id' => $actorId,
                'booking_id' => $booking->id,
                'property_id' => $booking->property_id,
                'folio_id' => $folio->id,
                'room_id' => $booking->room_id,
                'late_checkout_fee' => $lateFeeAmount,
            ]);

            return [
                'checked_out' => true,
                'booking' => $booking->refresh()->load(['room.roomType', 'checkedOutByUser']),
                'folio' => $folio->refresh(),
                'outstanding_balance' => '0.00',
                'late_checkout_fee' => $lateFeeAmount,
            ];
        });

        if ($result['checked_out']) {
            ReservationCheckedOut::dispatch(
                $result['booking'],
                $result['folio']->id,
                $result['late_checkout_fee'],
            );
        }

        return $result;
    }

    private function signedAmountCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $cents = $this->rates->amountCents(ltrim($amount, '-'));

        return $negative ? -$cents : $cents;
    }
}
