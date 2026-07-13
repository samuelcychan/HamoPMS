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
                'folio' => ['A closed folio cannot receive a reservation modification.'],
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
}
