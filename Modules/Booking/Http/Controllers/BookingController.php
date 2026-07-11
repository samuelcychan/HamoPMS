<?php

namespace Modules\Booking\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Booking\Models\Booking;

class BookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $bookings = Booking::where('user_id', $request->user()->id)
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::paginated($bookings);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'property_id' => ['required', 'exists:properties,id'],
            'check_in' => ['required', 'date', 'after_or_equal:today'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'guests' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
        ]);

        $validated['user_id'] = $request->user()->id;
        $booking = Booking::create($validated);

        return ApiResponse::success($booking, 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $booking = Booking::where('user_id', $request->user()->id)->findOrFail($id);

        return ApiResponse::success($booking);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $booking = Booking::where('user_id', $request->user()->id)->findOrFail($id);

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

    public function destroy(Request $request, string $id): JsonResponse
    {
        $booking = Booking::where('user_id', $request->user()->id)->findOrFail($id);
        $booking->delete();

        return response()->json(null, 204);
    }
}
