# Reservation modifications

`PUT /api/v1/bookings/{bookingId}` modifies a pre-stay reservation. The endpoint accepts `room_type_id`, `check_in`, `check_out`, `occupancy` (or the legacy `guests` field), `notes`, and an array of `special_requests`.

Only `pending` and `confirmed` reservations are eligible. Checked-in changes belong to the in-stay workflow, while cancellation belongs to the policy engine. The endpoint rejects direct status changes.

The modification runs in one database transaction. It locks the reservation and affected room types, validates effective dates and room capacity, and checks peak nightly inventory without counting the reservation itself. An unavailable change returns `INVENTORY_UNAVAILABLE` without updating the reservation, folio, or history.

Room price is the room type's base rate multiplied by the number of nights. A system-owned `room_rate` line establishes the original folio baseline, and each non-zero change appends an immutable `room_rate_adjustment`. Negative adjustments represent a shorter or less expensive stay.

Every accepted modification creates a `booking_modifications` row containing before/after snapshots, the actor, and the signed rate difference. The response keeps the reservation in `data` and returns the history identifier and rate difference in `meta.modification`.
