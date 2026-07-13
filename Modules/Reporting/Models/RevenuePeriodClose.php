<?php

namespace Modules\Reporting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Property\Models\Property;

class RevenuePeriodClose extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'property_id',
        'closed_by',
        'period_start',
        'period_end',
        'currency',
        'snapshot',
        'checksum',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'snapshot' => 'array',
            'closed_at' => 'datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    protected function performUpdate(Builder $query): bool
    {
        throw new LogicException('Revenue period-close snapshots are immutable.');
    }

    public function delete(): ?bool
    {
        throw new LogicException('Revenue period-close snapshots are immutable.');
    }
}
