<?php

namespace Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Property\Models\Property;

class IntegrationEvent extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DEAD_LETTERED = 'dead_lettered';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_DEAD_LETTERED,
    ];

    protected $fillable = [
        'property_id',
        'provider',
        'external_event_id',
        'type',
        'occurred_at',
        'payload_hash',
        'payload',
        'status',
        'attempts',
        'last_error',
        'processed_at',
        'failed_at',
    ];

    protected $hidden = ['payload_hash', 'payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
