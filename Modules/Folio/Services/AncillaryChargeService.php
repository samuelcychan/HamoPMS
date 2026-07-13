<?php

namespace Modules\Folio\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Booking\Models\Booking;
use Modules\Booking\Services\ReservationRateCalculator;
use Modules\Folio\Models\AncillaryChargeType;
use Modules\Folio\Models\Folio;
use Modules\Folio\Models\FolioLineItem;

class AncillaryChargeService
{
    public function __construct(private readonly ReservationRateCalculator $rates) {}

    public function post(int $bookingId, int $chargeTypeId, string $amount, ?string $description, int $actorId): array
    {
        return DB::transaction(function () use ($bookingId, $chargeTypeId, $amount, $description, $actorId): array {
            $booking = Booking::query()->lockForUpdate()->findOrFail($bookingId);
            $this->assertCheckedIn($booking);
            $folio = $this->openFolio($booking);
            $chargeType = AncillaryChargeType::query()
                ->where('property_id', $booking->property_id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->findOrFail($chargeTypeId);
            $amountCents = $this->rates->amountCents($amount);

            if ($amountCents === 0) {
                throw ValidationException::withMessages([
                    'amount' => ['An ancillary charge must be greater than zero.'],
                ]);
            }

            $charge = $folio->lineItems()->create([
                'ancillary_charge_type_id' => $chargeType->id,
                'posted_by' => $actorId,
                'type' => FolioLineItem::TYPE_ANCILLARY_CHARGE,
                'description' => $description ?: $chargeType->name,
                'amount' => $this->rates->formatCents($amountCents),
                'tax_rate' => $chargeType->tax_rate,
            ]);
            $tax = $this->postTax(
                $folio,
                $charge,
                $this->taxCents($amountCents, $chargeType->tax_rate),
                FolioLineItem::TYPE_ANCILLARY_TAX,
                "{$chargeType->name} tax",
                $actorId,
            );

            return [
                'charge' => $charge->refresh()->load(['ancillaryChargeType', 'postedByUser']),
                'tax' => $tax?->refresh(),
                'balance' => $folio->balance(),
            ];
        });
    }

    public function adjust(int $bookingId, int $lineItemId, string $action, ?string $amount, string $reason, int $actorId): array
    {
        return DB::transaction(function () use ($bookingId, $lineItemId, $action, $amount, $reason, $actorId): array {
            $booking = Booking::query()->lockForUpdate()->findOrFail($bookingId);
            $this->assertCheckedIn($booking);
            $folio = $this->openFolio($booking);
            $charge = $folio->lineItems()
                ->where('type', FolioLineItem::TYPE_ANCILLARY_CHARGE)
                ->lockForUpdate()
                ->findOrFail($lineItemId);

            if ($folio->lineItems()
                ->where('related_line_item_id', $charge->id)
                ->where('type', FolioLineItem::TYPE_ANCILLARY_VOID)
                ->exists()) {
                throw ValidationException::withMessages([
                    'line_item' => ['A voided ancillary charge cannot be changed again.'],
                ]);
            }

            if ($action === 'void') {
                $family = $folio->lineItems()
                    ->where(fn ($query) => $query
                        ->whereKey($charge->id)
                        ->orWhere('related_line_item_id', $charge->id))
                    ->lockForUpdate()
                    ->get();
                $familyCents = $family->sum(
                    fn (FolioLineItem $item): int => $this->signedAmountCents($item->amount),
                );
                $entry = $folio->lineItems()->create([
                    'ancillary_charge_type_id' => $charge->ancillary_charge_type_id,
                    'related_line_item_id' => $charge->id,
                    'posted_by' => $actorId,
                    'type' => FolioLineItem::TYPE_ANCILLARY_VOID,
                    'description' => "Void: {$reason}",
                    'amount' => $this->rates->formatCents(-$familyCents),
                    'tax_rate' => $charge->tax_rate,
                ]);

                return ['entry' => $entry->refresh(), 'tax' => null, 'balance' => $folio->balance()];
            }

            $adjustmentCents = $this->signedAmountCents((string) $amount);

            if ($adjustmentCents === 0) {
                throw ValidationException::withMessages([
                    'amount' => ['An adjustment must be non-zero.'],
                ]);
            }

            $entry = $folio->lineItems()->create([
                'ancillary_charge_type_id' => $charge->ancillary_charge_type_id,
                'related_line_item_id' => $charge->id,
                'posted_by' => $actorId,
                'type' => FolioLineItem::TYPE_ANCILLARY_ADJUSTMENT,
                'description' => "Adjustment: {$reason}",
                'amount' => $this->rates->formatCents($adjustmentCents),
                'tax_rate' => $charge->tax_rate,
            ]);
            $tax = $this->postTax(
                $folio,
                $charge,
                $this->taxCents($adjustmentCents, $charge->tax_rate),
                FolioLineItem::TYPE_ANCILLARY_TAX_ADJUSTMENT,
                "Adjustment tax: {$reason}",
                $actorId,
            );

            return ['entry' => $entry->refresh(), 'tax' => $tax?->refresh(), 'balance' => $folio->balance()];
        });
    }

    private function openFolio(Booking $booking): Folio
    {
        $folio = Folio::query()->where('booking_id', $booking->id)->lockForUpdate()->first();
        $folio ??= Folio::create([
            'booking_id' => $booking->id,
            'status' => Folio::STATUS_OPEN,
            'currency' => 'USD',
        ]);

        if ($folio->status !== Folio::STATUS_OPEN) {
            throw ValidationException::withMessages([
                'folio' => ['A closed folio cannot receive ancillary charges.'],
            ]);
        }

        return $folio;
    }

    private function assertCheckedIn(Booking $booking): void
    {
        if ($booking->status !== Booking::STATUS_CHECKED_IN) {
            throw ValidationException::withMessages([
                'status' => ['Ancillary charges can only be posted during a checked-in stay.'],
            ]);
        }
    }

    private function postTax(
        Folio $folio,
        FolioLineItem $charge,
        int $taxCents,
        string $type,
        string $description,
        int $actorId,
    ): ?FolioLineItem {
        if ($taxCents === 0) {
            return null;
        }

        return $folio->lineItems()->create([
            'ancillary_charge_type_id' => $charge->ancillary_charge_type_id,
            'related_line_item_id' => $charge->id,
            'posted_by' => $actorId,
            'type' => $type,
            'description' => $description,
            'amount' => $this->rates->formatCents($taxCents),
            'tax_rate' => $charge->tax_rate,
        ]);
    }

    private function taxCents(int $amountCents, string $taxRate): int
    {
        $taxBasisPoints = $this->rates->amountCents($taxRate);
        $absoluteTax = intdiv((abs($amountCents) * $taxBasisPoints) + 5000, 10000);

        return $amountCents < 0 ? -$absoluteTax : $absoluteTax;
    }

    private function signedAmountCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $cents = $this->rates->amountCents(ltrim($amount, '-'));

        return $negative ? -$cents : $cents;
    }
}
