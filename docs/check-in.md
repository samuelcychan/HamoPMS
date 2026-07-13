# Check-in API

`POST /api/v1/bookings/{bookingId}/check-in` assigns a physical room and transitions a confirmed reservation into an in-stay state.

The request body contains `room_id`. The reservation must be `confirmed`, its arrival date must be today or earlier, and its departure date must still be in the future. The room must belong to the same property and room type and must have `clean` status.

Check-in is transactional. The selected room is moved to `occupied`, the room status audit trail records the acting user and reservation, and the booking records `room_id`, `checked_in_at`, `checked_in_by`, and status `checked_in`. Invalid reservation or room states use the standard validation contract.

After the transaction succeeds, `Modules\Booking\Events\ReservationCheckedIn` is dispatched with the updated booking for reporting and other downstream listeners.
