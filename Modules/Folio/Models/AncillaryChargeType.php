<?php

namespace Modules\Folio\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Property\Models\Property;

class AncillaryChargeType extends Model
{
    protected $fillable = [
        'property_id',
        'code',
        'name',
        'tax_rate',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'property_id' => 'integer',
            'tax_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(FolioLineItem::class);
    }
}
