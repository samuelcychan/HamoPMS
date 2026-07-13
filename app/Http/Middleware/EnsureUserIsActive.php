<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->is_active) {
            return ApiResponse::error(
                'ACCOUNT_INACTIVE',
                'This account is inactive.',
                403,
            );
        }

        return $next($request);
    }
}
