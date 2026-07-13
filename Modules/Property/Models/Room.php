<?php

namespace Modules\Property\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Room extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_CLEAN = 'clean';

    public const STATUS_DIRTY = 'dirty';

    public const STATUS_CLEANING = 'cleaning';

    public const STATUS_OCCUPIED = 'occupied';

    public const STATUS_OUT_OF_SERVICE = 'out_of_service';

    public const STATUSES = [
        self::STATUS_CLEAN,
        self::STATUS_DIRTY,
        self::STATUS_CLEANING,
        self::STATUS_OCCUPIED,
        self::STATUS_OUT_OF_SERVICE,
    ];

    public const ALLOWED_STATUS_TRANSITIONS = [
        self::STATUS_CLEAN => [self::STATUS_DIRTY, self::STATUS_OCCUPIED, self::STATUS_OUT_OF_SERVICE],
        self::STATUS_DIRTY => [self::STATUS_CLEANING, self::STATUS_OUT_OF_SERVICE],
        self::STATUS_CLEANING => [self::STATUS_CLEAN, self::STATUS_DIRTY, self::STATUS_OUT_OF_SERVICE],
        self::STATUS_OCCUPIED => [self::STATUS_DIRTY, self::STATUS_OUT_OF_SERVICE],
        self::STATUS_OUT_OF_SERVICE => [self::STATUS_CLEAN, self::STATUS_DIRTY],
    ];

    protected $fillable = [
        'property_id',
        'room_type_id',
        'number',
        'floor',
        'status',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(RoomStatusHistory::class);
    }

    public function housekeepingTasks(): HasMany
    {
        return $this->hasMany(HousekeepingTask::class);
    }

    public function maintenanceTickets(): HasMany
    {
        return $this->hasMany(MaintenanceTicket::class);
    }
}
