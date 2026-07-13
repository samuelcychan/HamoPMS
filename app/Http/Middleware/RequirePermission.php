<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Support\PropertyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function __construct(private readonly PropertyContext $propertyContext) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $propertyId = $this->propertyContext->get($request)?->id;

        if (! $request->user()?->hasPermission(
            $permission,
            $propertyId,
        )) {
            return ApiResponse::error(
                'FORBIDDEN',
                'You are not allowed to perform this action.',
                403,
            );
        }

        return $next($request);
    }
}
