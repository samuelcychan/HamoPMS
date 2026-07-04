<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\PropertyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active property for the current request and stores it in
 * the PropertyContext for the duration of the request.
 *
 * Resolution order:
 *  1. `X-Property-Id` header (useful for portfolio users switching context)
 *  2. The authenticated user's home `property_id`
 */
class ResolvePropertyContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $propertyId = $request->header('X-Property-Id')
            ?? $request->user()?->property_id;

        PropertyContext::set($propertyId ? (int) $propertyId : null);

        try {
            return $next($request);
        } finally {
            PropertyContext::clear();
        }
    }
}
