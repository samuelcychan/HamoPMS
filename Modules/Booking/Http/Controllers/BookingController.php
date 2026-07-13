<?php

namespace Modules\Booking\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Support\PropertyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Booking\Models\Booking;
use Modules\Booking\Services\ReservationService;

class BookingController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly PropertyContext $propertyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $bookings = $this->propertyContext
            ->scope(Booking::query(), $request)
            ->where('user_id', $request->user()->id)
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::paginated($bookings);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'property_id' => ['required', 'exists:properties,id'],
            'room_type_id' => [
                'required',
                'integer',
                Rule::exists('room_types', 'id')->where(
                    fn ($query) => $query
                        ->where('property_id', $request->input('property_id'))
                        ->where('is_active', true)
                        ->whereNull('deleted_at'),
                ),
            ],
            'check_in' => ['required', 'date', 'after_or_equal:today'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'guests' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
        ]);

        $booking = $this->reservations->createConfirmed($request->user()->id, $validated);

        if ($booking === null) {
            return ApiResponse::error(
                'INVENTORY_UNAVAILABLE',
                'The property is unavailable for the requested dates.',
                409,
            );
        }

        return ApiResponse::success($booking, 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $booking = $this->bookingQuery($request)->findOrFail($id);

        return ApiResponse::success($booking);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $booking = $this->bookingQuery($request)->findOrFail($id);

        $validated = $request->validate([
            'check_in' => ['sometimes', 'date', 'after_or_equal:today'],
            'check_out' => ['sometimes', 'date', 'after:check_in'],
            'guests' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'in:pending,confirmed,cancelled,completed'],
            'notes' => ['nullable', 'string'],
        ]);

        $booking->update($validated);

        return ApiResponse::success($booking);
    }

    public function destroy(Request $request, string $id): Response
    {
        $booking = $this->bookingQuery($request)->findOrFail($id);
        $booking->delete();

        return response()->noContent();
    }

    private function bookingQuery(Request $request): Builder
    {
        return $this->propertyContext
            ->scope(Booking::query(), $request)
            ->where('user_id', $request->user()->id);
    }
}
