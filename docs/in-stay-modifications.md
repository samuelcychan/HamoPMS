# In-stay modifications

Checked-in reservations can be changed through two front-desk endpoints. Both endpoints require authentication, an active user, property context, and the `reservations.write` permission.

## Room move

`POST /api/v1/bookings/{bookingId}/room-move` accepts a destination `room_id`. The destination must belong to the same property, be clean, use an active room type, and accommodate the reservation's guest count. A move into a different room type also requires pooled inventory to be available for every remaining night.

The current room changes from `occupied` to `dirty`, and the destination changes from `clean` to `occupied`. Both transitions, the reservation reassignment, its before/after audit snapshot, and its folio adjustment are committed atomically. A failed move leaves all room and reservation state unchanged.

When the room type changes, the adjustment is the difference between the destination base rate and the reservation's current nightly rate multiplied by the remaining nights. The response reports the signed adjustment in `meta.stay_change.rate_difference`.

## Departure adjustment

`POST /api/v1/bookings/{bookingId}/departure-adjustment` accepts a new `check_out` date after the current date. A later date is an extension; an earlier date is an early departure.

Extensions require pooled room-type inventory for every added night. Both extensions and early departures append an immutable folio rate adjustment based on the reservation's nightly rate and the number of nights added or removed. This recalculates the open folio balance without rewriting earlier line items. The reservation date, audit snapshot, and folio entry are committed in one transaction.

## Events and response metadata

Accepted changes dispatch one event after the database transaction commits its work:

- `ReservationRoomMoved`
- `StayExtended`
- `EarlyDepartureScheduled`

The reservation is returned in `data`. `meta.stay_change` contains the change `type`, immutable `modification_id`, and signed `rate_difference`. Inventory conflicts return `INVENTORY_UNAVAILABLE` with status `409`; validation failures use the standard `422` error envelope.
