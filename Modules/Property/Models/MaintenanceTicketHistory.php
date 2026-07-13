<?php

namespace Modules\Property\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceTicketHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'event',
        'from_status',
        'to_status',
        'changed_by',
        'details',
    ];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTicket::class, 'maintenance_ticket_id');
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
