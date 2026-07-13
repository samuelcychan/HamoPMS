<?php

namespace Modules\Reporting\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Booking\Services\ReservationRateCalculator;
use Modules\Folio\Models\FolioLineItem;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentOperation;
use Modules\Reporting\Models\RevenuePeriodClose;

class FinancialReportService
{
    public function __construct(private readonly ReservationRateCalculator $rates) {}

    public function report(
        int $propertyId,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $currency,
    ): array {
        $from = $start->startOfDay();
        $until = $end->addDay()->startOfDay();
        $daily = [];

        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $daily[$day->toDateString()] = $this->emptyRevenue();
        }

        $totals = $this->emptyRevenue();
        $roomTypes = [];
        $lineItems = DB::table('folio_line_items')
            ->join('folios', 'folios.id', '=', 'folio_line_items.folio_id')
            ->join('bookings', 'bookings.id', '=', 'folios.booking_id')
            ->leftJoin('room_types', 'room_types.id', '=', 'bookings.room_type_id')
            ->where('bookings.property_id', $propertyId)
            ->where('folios.currency', $currency)
            ->where('folio_line_items.posted_at', '>=', $from)
            ->where('folio_line_items.posted_at', '<', $until)
            ->select([
                'folio_line_items.id',
                'folio_line_items.related_line_item_id',
                'folio_line_items.type',
                'folio_line_items.amount',
                'folio_line_items.posted_at',
                'bookings.room_type_id',
                'room_types.code as room_type_code',
                'room_types.name as room_type_name',
            ])
            ->orderBy('folio_line_items.posted_at')
            ->orderBy('folio_line_items.id')
            ->get();
        $voidSplits = $this->voidRevenueSplits($lineItems);

        foreach ($lineItems as $lineItem) {
            $amountCents = $this->signedAmountCents((string) $lineItem->amount);
            $date = CarbonImmutable::parse($lineItem->posted_at)->toDateString();
            $roomTypeId = $lineItem->room_type_id === null ? null : (int) $lineItem->room_type_id;
            $roomKey = $roomTypeId === null ? 'unassigned' : (string) $roomTypeId;
            $roomTypes[$roomKey] ??= array_merge([
                'room_type_id' => $roomTypeId,
                'room_type_code' => $lineItem->room_type_code,
                'room_type_name' => $lineItem->room_type_name,
            ], $this->emptyRevenue());
            $components = $lineItem->type === FolioLineItem::TYPE_ANCILLARY_VOID
                && isset($voidSplits[(int) $lineItem->related_line_item_id])
                    ? $voidSplits[(int) $lineItem->related_line_item_id]
                    : [
                        in_array($lineItem->type, FolioLineItem::TAX_TYPES, true)
                            ? 'tax_cents'
                            : 'net_cents' => $amountCents,
                    ];

            foreach ($components as $bucket => $componentCents) {
                $totals[$bucket] += $componentCents;
                $daily[$date][$bucket] += $componentCents;
                $roomTypes[$roomKey][$bucket] += $componentCents;
            }
        }

        $payments = $this->paymentBreakdown($propertyId, $from, $until, $currency);
        $grossCents = $totals['net_cents'] + $totals['tax_cents'];

        return [
            'property_id' => $propertyId,
            'period' => [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ],
            'currency' => $currency,
            'revenue' => $this->formatRevenue($totals),
            'daily' => collect($daily)->map(
                fn (array $amounts, string $date): array => array_merge(
                    ['date' => $date],
                    $this->formatRevenue($amounts),
                ),
            )->sortBy('date')->values()->all(),
            'by_room_type' => collect($roomTypes)->map(
                fn (array $amounts): array => array_merge(
                    array_intersect_key($amounts, array_flip(['room_type_id', 'room_type_code', 'room_type_name'])),
                    $this->formatRevenue($amounts),
                ),
            )->sortBy('room_type_code')->values()->all(),
            'payments' => $payments['totals'],
            'by_payment_method' => $payments['methods'],
            'reconciliation' => [
                'revenue_less_net_settlement' => $this->rates->formatCents(
                    $grossCents - $payments['net_settled_cents'],
                ),
            ],
        ];
    }

    public function closePeriod(
        int $propertyId,
        int $actorId,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $currency,
    ): array {
        try {
            return DB::transaction(function () use ($propertyId, $actorId, $start, $end, $currency): array {
                $existing = $this->periodCloseQuery($propertyId, $start, $end, $currency)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return ['period_close' => $existing, 'replayed' => true];
                }

                $snapshot = $this->report($propertyId, $start, $end, $currency);
                $serialized = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
                $periodClose = RevenuePeriodClose::create([
                    'property_id' => $propertyId,
                    'closed_by' => $actorId,
                    'period_start' => $start->toDateString(),
                    'period_end' => $end->toDateString(),
                    'currency' => $currency,
                    'snapshot' => $snapshot,
                    'checksum' => hash('sha256', $serialized),
                    'closed_at' => now(),
                ]);

                return ['period_close' => $periodClose, 'replayed' => false];
            });
        } catch (UniqueConstraintViolationException) {
            return [
                'period_close' => $this->periodCloseQuery($propertyId, $start, $end, $currency)->firstOrFail(),
                'replayed' => true,
            ];
        }
    }

    private function paymentBreakdown(
        int $propertyId,
        CarbonImmutable $from,
        CarbonImmutable $until,
        string $currency,
    ): array {
        $methods = [];
        $capturedCents = 0;
        $refundedCents = 0;
        $operations = DB::table('payment_operations')
            ->join('payments', 'payments.id', '=', 'payment_operations.payment_id')
            ->join('bookings', 'bookings.id', '=', 'payments.booking_id')
            ->where('bookings.property_id', $propertyId)
            ->where('payments.currency', $currency)
            ->where('payment_operations.status', PaymentOperation::STATUS_COMPLETED)
            ->whereIn('payment_operations.type', ['capture', 'refund'])
            ->where('payment_operations.created_at', '>=', $from)
            ->where('payment_operations.created_at', '<', $until)
            ->select([
                'payment_operations.payment_id',
                'payment_operations.type',
                'payment_operations.amount',
                'payments.method',
            ])
            ->get();

        foreach ($operations as $operation) {
            if ($operation->amount === null) {
                continue;
            }

            $amountCents = $this->rates->amountCents((string) $operation->amount);
            $method = (string) $operation->method;
            $methods[$method] ??= $this->emptyPaymentMethod($method);
            $methods[$method]['payment_ids'][(int) $operation->payment_id] = true;

            if ($operation->type === 'capture') {
                $capturedCents += $amountCents;
                $methods[$method]['captured_cents'] += $amountCents;
            } else {
                $refundedCents += $amountCents;
                $methods[$method]['refunded_cents'] += $amountCents;
            }
        }

        $legacyPayments = Payment::query()
            ->whereHas('booking', fn ($query) => $query->where('property_id', $propertyId))
            ->whereDoesntHave('operations')
            ->where('currency', $currency)
            ->whereIn('status', [
                Payment::STATUS_COMPLETED,
                Payment::STATUS_PARTIALLY_REFUNDED,
                Payment::STATUS_REFUNDED,
            ])
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $until)
            ->get();

        foreach ($legacyPayments as $payment) {
            $captured = $this->rates->amountCents($payment->captured_amount);
            $captured = $captured === 0 ? $this->rates->amountCents($payment->amount) : $captured;
            $refunded = $this->rates->amountCents($payment->refunded_amount);
            $methods[$payment->method] ??= $this->emptyPaymentMethod($payment->method);
            $methods[$payment->method]['payment_ids'][$payment->id] = true;
            $methods[$payment->method]['captured_cents'] += $captured;
            $methods[$payment->method]['refunded_cents'] += $refunded;
            $capturedCents += $captured;
            $refundedCents += $refunded;
        }

        $methodRows = collect($methods)->map(function (array $method): array {
            return [
                'method' => $method['method'],
                'payment_count' => count($method['payment_ids']),
                'captured_amount' => $this->rates->formatCents($method['captured_cents']),
                'refunded_amount' => $this->rates->formatCents($method['refunded_cents']),
                'net_settled_amount' => $this->rates->formatCents(
                    $method['captured_cents'] - $method['refunded_cents'],
                ),
            ];
        })->sortBy('method')->values()->all();

        return [
            'totals' => [
                'captured_amount' => $this->rates->formatCents($capturedCents),
                'refunded_amount' => $this->rates->formatCents($refundedCents),
                'net_settled_amount' => $this->rates->formatCents($capturedCents - $refundedCents),
            ],
            'methods' => $methodRows,
            'net_settled_cents' => $capturedCents - $refundedCents,
        ];
    }

    private function periodCloseQuery(
        int $propertyId,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $currency,
    ): Builder {
        return RevenuePeriodClose::query()
            ->where('property_id', $propertyId)
            ->where('period_start', '>=', $start->startOfDay())
            ->where('period_start', '<', $start->addDay()->startOfDay())
            ->where('period_end', '>=', $end->startOfDay())
            ->where('period_end', '<', $end->addDay()->startOfDay())
            ->where('currency', $currency);
    }

    private function voidRevenueSplits(Collection $lineItems): array
    {
        $chargeIds = $lineItems
            ->where('type', FolioLineItem::TYPE_ANCILLARY_VOID)
            ->pluck('related_line_item_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($chargeIds->isEmpty()) {
            return [];
        }

        $splits = $chargeIds->mapWithKeys(
            fn (int $id): array => [$id => $this->emptyRevenue()],
        )->all();
        $family = DB::table('folio_line_items')
            ->where(function ($query) use ($chargeIds): void {
                $query->whereIn('id', $chargeIds)->orWhereIn('related_line_item_id', $chargeIds);
            })
            ->where('type', '!=', FolioLineItem::TYPE_ANCILLARY_VOID)
            ->get(['id', 'related_line_item_id', 'type', 'amount']);

        foreach ($family as $lineItem) {
            $chargeId = (int) ($lineItem->related_line_item_id ?? $lineItem->id);
            $bucket = in_array($lineItem->type, FolioLineItem::TAX_TYPES, true) ? 'tax_cents' : 'net_cents';
            $splits[$chargeId][$bucket] -= $this->signedAmountCents((string) $lineItem->amount);
        }

        return $splits;
    }

    private function emptyRevenue(): array
    {
        return ['net_cents' => 0, 'tax_cents' => 0];
    }

    private function signedAmountCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $cents = $this->rates->amountCents(ltrim($amount, '-'));

        return $negative ? -$cents : $cents;
    }

    private function formatRevenue(array $amounts): array
    {
        return [
            'net_revenue' => $this->rates->formatCents($amounts['net_cents']),
            'tax_revenue' => $this->rates->formatCents($amounts['tax_cents']),
            'gross_revenue' => $this->rates->formatCents($amounts['net_cents'] + $amounts['tax_cents']),
        ];
    }

    private function emptyPaymentMethod(string $method): array
    {
        return [
            'method' => $method,
            'payment_ids' => [],
            'captured_cents' => 0,
            'refunded_cents' => 0,
        ];
    }
}
