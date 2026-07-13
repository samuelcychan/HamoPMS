<?php

namespace Modules\Reporting\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class FinancialAuditReportService
{
    public const SOURCES = [
        'folio_line_item',
        'payment_operation',
        'revenue_period_close',
    ];

    public function paginate(
        int $propertyId,
        ?string $source,
        ?string $startDate,
        ?string $endDate,
        int $perPage,
    ): LengthAwarePaginator {
        $events = $this->folioEvents($propertyId)
            ->unionAll($this->paymentEvents($propertyId))
            ->unionAll($this->periodCloseEvents($propertyId));

        $paginator = DB::query()
            ->fromSub($events, 'financial_audit_events')
            ->when($source !== null, fn (Builder $query) => $query->where('source', $source))
            ->when($startDate !== null, fn (Builder $query) => $query->where(
                'occurred_at',
                '>=',
                CarbonImmutable::parse($startDate)->startOfDay(),
            ))
            ->when($endDate !== null, fn (Builder $query) => $query->where(
                'occurred_at',
                '<',
                CarbonImmutable::parse($endDate)->addDay()->startOfDay(),
            ))
            ->orderByDesc('occurred_at')
            ->orderBy('source')
            ->orderByDesc('event_id')
            ->paginate($perPage);

        return $paginator->through(fn (object $event): array => [
            'source' => $event->source,
            'event_id' => (int) $event->event_id,
            'occurred_at' => CarbonImmutable::parse($event->occurred_at)->toIso8601String(),
            'actor_id' => $event->actor_id === null ? null : (int) $event->actor_id,
            'resource_type' => $event->resource_type,
            'resource_id' => (int) $event->resource_id,
            'action' => $event->action,
            'status' => $event->status,
            'amount' => $event->amount === null ? null : number_format((float) $event->amount, 2, '.', ''),
            'currency' => $event->currency,
            'reference' => $event->reference,
        ]);
    }

    private function folioEvents(int $propertyId): Builder
    {
        return DB::table('folio_line_items')
            ->join('folios', 'folios.id', '=', 'folio_line_items.folio_id')
            ->join('bookings', 'bookings.id', '=', 'folios.booking_id')
            ->where('bookings.property_id', $propertyId)
            ->select([
                DB::raw("'folio_line_item' as source"),
                'folio_line_items.id as event_id',
                'folio_line_items.posted_at as occurred_at',
                'folio_line_items.posted_by as actor_id',
                DB::raw("'folio' as resource_type"),
                'folios.id as resource_id',
                'folio_line_items.type as action',
                DB::raw("'posted' as status"),
                'folio_line_items.amount',
                'folios.currency',
                'folio_line_items.description as reference',
            ]);
    }

    private function paymentEvents(int $propertyId): Builder
    {
        return DB::table('payment_operations')
            ->join('payments', 'payments.id', '=', 'payment_operations.payment_id')
            ->join('bookings', 'bookings.id', '=', 'payments.booking_id')
            ->where('bookings.property_id', $propertyId)
            ->select([
                DB::raw("'payment_operation' as source"),
                'payment_operations.id as event_id',
                'payment_operations.created_at as occurred_at',
                'payment_operations.performed_by as actor_id',
                DB::raw("'payment' as resource_type"),
                'payments.id as resource_id',
                'payment_operations.type as action',
                'payment_operations.status',
                'payment_operations.amount',
                'payments.currency',
                'payment_operations.idempotency_key as reference',
            ]);
    }

    private function periodCloseEvents(int $propertyId): Builder
    {
        return DB::table('revenue_period_closes')
            ->where('property_id', $propertyId)
            ->select([
                DB::raw("'revenue_period_close' as source"),
                'id as event_id',
                'closed_at as occurred_at',
                'closed_by as actor_id',
                DB::raw("'revenue_period_close' as resource_type"),
                'id as resource_id',
                DB::raw("'period_close' as action"),
                DB::raw("'closed' as status"),
                DB::raw('NULL as amount'),
                'currency',
                'checksum as reference',
            ]);
    }
}
