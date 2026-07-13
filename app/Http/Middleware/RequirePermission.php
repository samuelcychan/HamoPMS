<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $propertyId = $request->route('propertyId')
            ?? $request->input('property_id')
            ?? $request->query('property_id');
        $routeName = $request->route()?->getName() ?? '';
        $bookingId = $request->route('booking_id') ?? $request->input('booking_id');

        if ($propertyId === null && $bookingId !== null) {
            $propertyId = DB::table('bookings')->where('id', $bookingId)->value('property_id');
        }

        if ($propertyId === null && str_starts_with($routeName, 'api.v1.bookings.') && $request->route('id')) {
            $propertyId = DB::table('bookings')->where('id', $request->route('id'))->value('property_id');
        }

        if ($propertyId === null && str_starts_with($routeName, 'api.v1.payments.') && $request->route('id')) {
            $propertyId = DB::table('payments')
                ->join('bookings', 'bookings.id', '=', 'payments.booking_id')
                ->where('payments.id', $request->route('id'))
                ->value('bookings.property_id');
        }

        if (! $request->user()?->hasPermission(
            $permission,
            $propertyId !== null ? (int) $propertyId : null,
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
