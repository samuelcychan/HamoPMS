<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use LogicException;
use Modules\Property\Models\Property;

class PropertyContext
{
    private const REQUEST_ATTRIBUTE = 'property_context';

    public function set(Request $request, Property $property): void
    {
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $property);
    }

    public function get(Request $request): ?Property
    {
        $property = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        return $property instanceof Property ? $property : null;
    }

    public function property(Request $request): Property
    {
        return $this->get($request)
            ?? throw new LogicException('Property context has not been resolved for this request.');
    }

    public function id(Request $request): int
    {
        return (int) $this->property($request)->getKey();
    }

    public function scope(Builder $query, Request $request, string $column = 'property_id'): Builder
    {
        return $query->where(
            $query->getModel()->qualifyColumn($column),
            $this->id($request),
        );
    }
}
