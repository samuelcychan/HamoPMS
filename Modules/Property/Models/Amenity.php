<?php

namespace Modules\Property\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Amenity extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'property_id',
        'name',
        'code',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class)->withTimestamps();
    }
}
