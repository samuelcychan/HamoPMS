<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Support\PropertyContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Booking\Models\Booking;
use Modules\Payment\Models\Payment;
use Modules\Property\Models\Property;
use Symfony\Component\HttpFoundation\Response;

class ResolvePropertyContext
{
    public function __construct(private readonly PropertyContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $propertyIds = $this->resolvePropertyIds($request);

        if ($propertyIds === []) {
            return ApiResponse::error(
                'PROPERTY_CONTEXT_REQUIRED',
                'A property context is required for this request.',
                400,
            );
        }

        if (count(array_unique($propertyIds)) !== 1) {
            return ApiResponse::error(
                'PROPERTY_CONTEXT_MISMATCH',
                'The property context does not match the requested resource.',
                409,
            );
        }

        $property = Property::findOrFail($propertyIds[0]);

        if (! $request->user()?->hasPropertyAccess($property->id)) {
            return ApiResponse::error(
                'FORBIDDEN',
                'You are not allowed to perform this action.',
                403,
            );
        }

        $this->context->set($request, $property);

        return $next($request);
    }

    /**
     * @return list<int>
     */
    private function resolvePropertyIds(Request $request): array
    {
        $propertyIds = [];

        if ($request->route('propertyId') !== null) {
            $propertyIds[] = $this->integer($request->route('propertyId'));
        }

        if ($request->header('X-Property-ID') !== null) {
            $propertyIds[] = $this->integer($request->header('X-Property-ID'));
        }

        if ($request->input('property_id') !== null) {
            $propertyIds[] = $this->integer($request->input('property_id'));
        }

        $bookingId = $request->route('booking_id') ?? $request->input('booking_id');
        $routeName = $request->route()?->getName() ?? '';

        if ($bookingId === null && str_starts_with($routeName, 'api.v1.bookings.')) {
            $bookingId = $request->route('id');
        }

        if ($bookingId !== null) {
            $propertyIds[] = (int) Booking::query()->findOrFail($bookingId)->property_id;
        }

        if (str_starts_with($routeName, 'api.v1.payments.') && $request->route('id') !== null) {
            $payment = Payment::query()->findOrFail($request->route('id'));
            $propertyIds[] = (int) Booking::query()->findOrFail($payment->booking_id)->property_id;
        }

        return $propertyIds;
    }

    private function integer(mixed $value): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw ValidationException::withMessages([
                'property_id' => ['The property id must be a positive integer.'],
            ]);
        }

        return (int) $value;
    }
}
