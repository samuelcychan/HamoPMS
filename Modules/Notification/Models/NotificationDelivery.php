<?php

namespace Modules\Notification\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Booking\Models\Booking;
use Modules\Payment\Models\Payment;
use Modules\Property\Models\Property;

class NotificationDelivery extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'property_id',
        'booking_id',
        'payment_id',
        'deduplication_key',
        'channel',
        'template_key',
        'locale',
        'recipient',
        'subject',
        'payload',
        'status',
        'attempts',
        'queued_at',
        'sent_at',
        'failed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
