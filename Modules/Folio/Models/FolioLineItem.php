<?php

namespace Modules\Folio\Models;

use App\Models\User;
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

    public const TYPE_LATE_CHECKOUT_FEE = 'late_checkout_fee';

    public const TYPE_ANCILLARY_CHARGE = 'ancillary_charge';

    public const TYPE_ANCILLARY_TAX = 'ancillary_tax';

    public const TYPE_ANCILLARY_ADJUSTMENT = 'ancillary_adjustment';

    public const TYPE_ANCILLARY_TAX_ADJUSTMENT = 'ancillary_tax_adjustment';

    public const TYPE_ANCILLARY_VOID = 'ancillary_void';

    public const CREATED_AT = 'posted_at';

    public const UPDATED_AT = null;

    public const POSTABLE_TYPES = ['room_charge', 'tax'];

    protected $fillable = [
        'folio_id',
        'ancillary_charge_type_id',
        'related_line_item_id',
        'posted_by',
        'type',
        'description',
        'amount',
        'tax_rate',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'posted_at' => 'datetime',
        ];
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    public function ancillaryChargeType(): BelongsTo
    {
        return $this->belongsTo(AncillaryChargeType::class);
    }

    public function relatedLineItem(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_line_item_id');
    }

    public function postedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
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
