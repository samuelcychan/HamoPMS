<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToProperty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookSubscription extends Model
{
    use BelongsToProperty;

    protected $fillable = [
        'property_id',
        'url',
        'secret',
        'event_types',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'event_types' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /**
     * Whether this subscription listens for the given `entity.action`
     * event type (e.g. `reservation.created`).
     */
    public function listensFor(string $eventType): bool
    {
        return in_array($eventType, $this->event_types ?? [], true);
    }
}
