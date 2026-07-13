<?php

namespace Modules\Folio\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Booking\Models\Booking;
use Modules\Booking\Services\ReservationRateCalculator;
use Modules\Folio\Models\Folio;
use Modules\Payment\Models\Payment;

class FolioController extends Controller
{
    public function __construct(private readonly ReservationRateCalculator $rates) {}

    public function show(Request $request, string $bookingId): JsonResponse
    {
        $booking = Booking::where('user_id', $request->user()->id)->findOrFail($bookingId);

        $folio = Folio::firstOrCreate(
            ['booking_id' => $booking->id],
            ['status' => 'open', 'currency' => 'USD'],
        );

        $folio->load('lineItems');
        $payments = Payment::query()
            ->where('booking_id', $booking->id)
            ->orderBy('id')
            ->get();
        $settledCents = $payments
            ->whereIn('status', Payment::SETTLED_STATUSES)
            ->sum(function (Payment $payment): int {
                $captured = $this->rates->amountCents($payment->captured_amount);
                $captured = $captured === 0 ? $this->rates->amountCents($payment->amount) : $captured;

                return $captured - $this->rates->amountCents($payment->refunded_amount);
            });
        $balance = $folio->balance();
        $balanceCents = $this->signedAmountCents($balance);

        return ApiResponse::success([
            'folio' => $folio,
            'balance' => $balance,
            'payments' => $payments,
            'settled_amount' => $this->rates->formatCents($settledCents),
            'outstanding_balance' => $this->rates->formatCents($balanceCents - $settledCents),
        ]);
    }

    private function signedAmountCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $cents = $this->rates->amountCents(ltrim($amount, '-'));

        return $negative ? -$cents : $cents;
    }
}
