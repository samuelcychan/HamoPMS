<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\App;

/**
 * Holds the "current property" for the duration of a request/job, so that
 * the BelongsToProperty trait and other tenancy-aware code can resolve the
 * active property without threading it through every method signature.
 */
class PropertyContext
{
    protected static ?int $currentPropertyId = null;

    public static function set(?int $propertyId): void
    {
        static::$currentPropertyId = $propertyId;
    }

    public static function id(): ?int
    {
        return static::$currentPropertyId;
    }

    public static function clear(): void
    {
        static::$currentPropertyId = null;
    }

    /**
     * Resolve the shared singleton instance (useful for testability/DI).
     */
    public static function instance(): static
    {
        return App::make(static::class);
    }
}
