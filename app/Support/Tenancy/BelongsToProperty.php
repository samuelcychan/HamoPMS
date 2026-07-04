<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apply this trait to any Eloquent model that is scoped to a single
 * property (i.e. carries a `property_id` column). It automatically:
 *
 *  - Constrains all queries to the current PropertyContext, when set.
 *  - Fills `property_id` on create from the current PropertyContext.
 *  - Exposes a `property()` relation.
 *
 * This mirrors HAIP's "property_id on every table" multi-tenancy model.
 */
trait BelongsToProperty
{
    protected static function bootBelongsToProperty(): void
    {
        static::addGlobalScope('property', function (Builder $builder) {
            if ($propertyId = PropertyContext::id()) {
                $builder->where($builder->getModel()->getTable().'.property_id', $propertyId);
            }
        });

        static::creating(function (Model $model) {
            if (empty($model->property_id) && $propertyId = PropertyContext::id()) {
                $model->property_id = $propertyId;
            }
        });
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Property::class);
    }

    /**
     * Query without the property scope applied (e.g. for cross-property
     * admin/reporting use cases).
     */
    public function scopeWithoutPropertyScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope('property');
    }
}
