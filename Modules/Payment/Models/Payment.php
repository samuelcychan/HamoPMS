<?php

namespace Modules\Payment\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Booking\Models\Booking;

class Payment extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REQUIRES_CAPTURE = 'requires_capture';

    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_VOIDED = 'voided';

    public const SETTLED_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_PARTIALLY_REFUNDED,
    ];

    protected $fillable = [
        'user_id',
        'booking_id',
        'amount',
        'captured_amount',
        'refunded_amount',
        'currency',
        'method',
        'gateway',
        'gateway_payment_id',
        'idempotency_key',
        'status',
        'settlement_status',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'captured_amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(PaymentOperation::class);
    }
}
