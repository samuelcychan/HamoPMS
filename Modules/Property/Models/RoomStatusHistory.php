<?php

namespace Modules\Property\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'room_id',
        'from_status',
        'to_status',
        'changed_by',
        'reason',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
