<?php

namespace Modules\Folio\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class FolioLineItem extends Model
{
    public const TYPE_ROOM_RATE = 'room_rate';

    public const TYPE_ROOM_RATE_ADJUSTMENT = 'room_rate_adjustment';

    public const TYPE_ROOM_RATE_REVERSAL = 'room_rate_reversal';

    public const TYPE_CANCELLATION_PENALTY = 'cancellation_penalty';

    public const CREATED_AT = 'posted_at';

    public const UPDATED_AT = null;

    public const POSTABLE_TYPES = ['room_charge', 'tax'];

    protected $fillable = [
        'folio_id',
        'type',
        'description',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'posted_at' => 'datetime',
        ];
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    protected function performUpdate(Builder $query): bool
    {
        throw new LogicException('Folio line items are immutable and cannot be updated.');
    }

    public function delete(): ?bool
    {
        throw new LogicException('Folio line items are immutable and cannot be deleted.');
    }
}
