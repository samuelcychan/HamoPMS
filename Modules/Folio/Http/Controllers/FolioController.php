<?php

namespace Modules\Folio\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Booking\Models\Booking;
use Modules\Folio\Models\Folio;

class FolioController extends Controller
{
    public function show(Request $request, string $bookingId): JsonResponse
    {
        $booking = Booking::where('user_id', $request->user()->id)->findOrFail($bookingId);

        $folio = Folio::firstOrCreate(
            ['booking_id' => $booking->id],
            ['status' => 'open', 'currency' => 'USD'],
        );

        $folio->load('lineItems');

        return response()->json([
            'folio' => $folio,
            'balance' => $folio->balance(),
        ]);
    }
}
