<?php

namespace Modules\Folio\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Folio extends Model
{
    protected $fillable = [
        'booking_id',
        'status',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'booking_id' => 'integer',
        ];
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(FolioLineItem::class);
    }

    public function balance(): string
    {
        return number_format((float) $this->lineItems()->sum('amount'), 2, '.', '');
    }
}
