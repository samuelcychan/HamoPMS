# Check-out and folio closure

`POST /api/v1/bookings/{bookingId}/check-out` completes a checked-in stay. The endpoint requires authentication, an active user, property context, the `reservations.write` permission, and an assigned occupied room.

## Pre-close balance validation

The service locks the reservation and its open folio, then compares all immutable folio line items with completed payments in the same currency. Pending payments do not settle the balance. Both a positive amount due and a negative overpayment are considered unsettled.

An unsettled folio returns `FOLIO_BALANCE_OUTSTANDING` with status `409`. `error.details` includes the folio identifier, signed `outstanding_balance`, and the posted `late_checkout_fee`. The reservation remains checked in, the folio remains open, and the room remains occupied.

## Late check-out fee

The configured late fee is posted once when check-out occurs after the scheduled departure cutoff on the reservation's departure date. If that new charge creates a balance, the first attempt returns `409` so staff can collect payment; retrying does not duplicate the fee.

Defaults are controlled by `CHECKOUT_DEPARTURE_TIME` (`11:00`), `CHECKOUT_LATE_FEE` (`50.00`), and `CHECKOUT_LATE_FEE_ENABLED` (`true`). Times use the application timezone.

## Successful closure

With a zero balance, one transaction closes the folio, marks the reservation `completed`, records `checked_out_at` and `checked_out_by`, and changes the assigned room from `occupied` to `dirty` with an immutable status-history entry. The dirty room is released from the stay and awaits housekeeping before it can be assigned again.

After the transaction succeeds, `ReservationCheckedOut` is dispatched for reporting consumers. The response returns the completed reservation in `data` and closure details in `meta.checkout`.
