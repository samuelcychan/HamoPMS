<?php

namespace Modules\Folio\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Booking\Models\Booking;
use Modules\Folio\Models\Folio;
use Modules\Folio\Models\FolioLineItem;

class FolioLineItemController extends Controller
{
    public function store(Request $request, string $bookingId): JsonResponse
    {
        $booking = Booking::where('user_id', $request->user()->id)->findOrFail($bookingId);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', FolioLineItem::POSTABLE_TYPES)],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric'],
        ]);

        $folio = Folio::firstOrCreate(
            ['booking_id' => $booking->id],
            ['status' => Folio::STATUS_OPEN, 'currency' => 'USD'],
        );

        if ($folio->status !== Folio::STATUS_OPEN) {
            throw ValidationException::withMessages([
                'folio' => ['A closed folio cannot receive new line items.'],
            ]);
        }

        $lineItem = $folio->lineItems()->create($validated);

        return ApiResponse::success($lineItem, 201);
    }
}
