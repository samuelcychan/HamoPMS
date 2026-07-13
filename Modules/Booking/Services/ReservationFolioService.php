<?php

namespace Modules\Booking\Services;

use Illuminate\Validation\ValidationException;
use Modules\Booking\Models\Booking;
use Modules\Folio\Models\Folio;
use Modules\Folio\Models\FolioLineItem;

class ReservationFolioService
{
    public function __construct(private readonly ReservationRateCalculator $rates) {}

    public function ensureRoomRateBaseline(Booking $booking, int $totalCents): Folio
    {
        $folio = Folio::firstOrCreate(
            ['booking_id' => $booking->id],
            ['status' => 'open', 'currency' => 'USD'],
        );

        if ($folio->status !== 'open') {
            throw ValidationException::withMessages([
                'folio' => ['A closed folio cannot receive reservation adjustments.'],
            ]);
        }

        if (! $folio->lineItems()->where('type', FolioLineItem::TYPE_ROOM_RATE)->exists()) {
            $folio->lineItems()->create([
                'type' => FolioLineItem::TYPE_ROOM_RATE,
                'description' => 'Original reservation room rate',
                'amount' => $this->rates->formatCents($totalCents),
            ]);
        }

        return $folio;
    }

    public function postRateAdjustment(Folio $folio, int $differenceCents, int $modificationId): void
    {
        if ($differenceCents === 0) {
            return;
        }

        $folio->lineItems()->create([
            'type' => FolioLineItem::TYPE_ROOM_RATE_ADJUSTMENT,
            'description' => "Reservation modification #{$modificationId} room-rate adjustment",
            'amount' => $this->rates->formatCents($differenceCents),
        ]);
    }

    public function settleCancellation(Booking $booking, int $roomTotalCents, int $penaltyCents): Folio
    {
        $folio = $this->ensureRoomRateBaseline($booking, $roomTotalCents);

        $folio->lineItems()->create([
            'type' => FolioLineItem::TYPE_ROOM_RATE_REVERSAL,
            'description' => 'Cancelled reservation room-rate reversal',
            'amount' => $this->rates->formatCents(-$roomTotalCents),
        ]);

        if ($penaltyCents > 0) {
            $folio->lineItems()->create([
                'type' => FolioLineItem::TYPE_CANCELLATION_PENALTY,
                'description' => 'Reservation cancellation penalty',
                'amount' => $this->rates->formatCents($penaltyCents),
            ]);
        }

        return $folio;
    }
}
