<?php

namespace Modules\Booking\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Property\Models\Room;
use Modules\Property\Models\RoomType;

class Booking extends Model
{
    use HasFactory, SoftDeletes;

    protected $hidden = [
        'cancellation_policy_snapshot',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CHECKED_IN = 'checked_in';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_COMPLETED = 'completed';

    public const INVENTORY_BLOCKING_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
        self::STATUS_CHECKED_IN,
    ];

    protected $fillable = [
        'user_id',
        'property_id',
        'room_type_id',
        'room_id',
        'check_in',
        'check_out',
        'guests',
        'status',
        'checked_in_at',
        'checked_in_by',
        'notes',
        'special_requests',
        'nightly_rate',
        'cancellation_policy',
        'cancellation_policy_snapshot',
        'cancelled_at',
        'cancelled_by',
        'cancellation_penalty',
    ];

    protected function casts(): array
    {
        return [
            'check_in' => 'date',
            'check_out' => 'date',
            'guests' => 'integer',
            'checked_in_at' => 'datetime',
            'special_requests' => 'array',
            'nightly_rate' => 'decimal:2',
            'cancellation_policy_snapshot' => 'array',
            'cancelled_at' => 'datetime',
            'cancellation_penalty' => 'decimal:2',
        ];
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function checkedInByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function modifications(): HasMany
    {
        return $this->hasMany(BookingModification::class);
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
