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
use Illuminate\Validation\ValidationException;
use Modules\Booking\Models\Booking;
use Modules\Booking\Services\ReservationModificationService;
use Modules\Booking\Services\ReservationService;

class BookingController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly ReservationModificationService $modifications,
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
            'cancellation_policy' => ['sometimes', 'string', Rule::in(array_keys(config('cancellation.policies')))],
        ]);
        $validated['cancellation_policy'] ??= config('cancellation.default');
        $validated['cancellation_policy_snapshot'] = config(
            "cancellation.policies.{$validated['cancellation_policy']}",
        );

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
        $booking = $this->propertyContext->scope(Booking::query(), $request)->findOrFail($id);

        $validated = $request->validate([
            'room_type_id' => ['sometimes', 'integer'],
            'check_in' => ['sometimes', 'date'],
            'check_out' => ['sometimes', 'date'],
            'guests' => ['sometimes', 'integer', 'min:1'],
            'occupancy' => ['sometimes', 'integer', 'min:1'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'special_requests' => ['sometimes', 'nullable', 'array'],
            'special_requests.*' => ['string', 'max:255'],
            'status' => ['prohibited'],
        ]);

        $editable = ['room_type_id', 'check_in', 'check_out', 'guests', 'occupancy', 'notes', 'special_requests'];

        if (array_intersect($editable, array_keys($validated)) === []) {
            throw ValidationException::withMessages([
                'reservation' => ['At least one editable reservation field is required.'],
            ]);
        }

        if (array_key_exists('guests', $validated) && array_key_exists('occupancy', $validated)) {
            throw ValidationException::withMessages([
                'occupancy' => ['Use either occupancy or guests, not both.'],
            ]);
        }

        $result = $this->modifications->modify($booking->id, $request->user()->id, $validated);

        if ($result === null) {
            return ApiResponse::error(
                'INVENTORY_UNAVAILABLE',
                'The property is unavailable for the requested dates.',
                409,
            );
        }

        return ApiResponse::success($result['booking'], 200, [
            'modification' => [
                'id' => $result['modification']->id,
                'rate_difference' => $result['rate_difference'],
            ],
        ]);
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
