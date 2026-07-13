# Operational reports

Authenticated users with `reservations.read` can query property-scoped operational reports. Supply `property_id` as a query parameter or use `X-Property-ID`. A conflicting query and header context returns the standard property-context conflict response.

## Endpoints

- `GET /api/v1/reports/arrivals?property_id={id}&date=YYYY-MM-DD`
- `GET /api/v1/reports/departures?property_id={id}&date=YYYY-MM-DD`
- `GET /api/v1/reports/in-house?property_id={id}`
- `GET /api/v1/reports/no-shows?property_id={id}&date=YYYY-MM-DD`
- `GET /api/v1/reports/occupancy?property_id={id}&date=YYYY-MM-DD`
- `GET /api/v1/reports/room-status?property_id={id}`

The date defaults to today in the application timezone. Arrival lists include confirmed, checked-in, and completed stays for the requested date. Departure forecasts include active confirmed stays whose arrival has not already been missed, plus checked-in and completed stays. Pending and cancelled reservations are excluded. The in-house list is evaluated at request time and includes only stays that have been checked in, have not been checked out, and contain the current date under half-open stay semantics (`check_in <= today < check_out`).

No-shows are confirmed bookings with no check-in timestamp whose selected arrival day has fully elapsed. Current and future arrival dates return no no-shows. The room-status summary counts the property's current clean, dirty, cleaning, occupied, and out-of-service rooms and includes a total and request-time `as_of` scope.

Occupancy uses the same half-open date range. Its numerator is the number of confirmed, checked-in, or completed stays covering the report date. Its denominator is the property's current sellable-room count, excluding rooms whose current status is `out_of_service`. The response reports total, out-of-service, sellable, occupied, and available rooms plus a percentage rounded to two decimal places.

## Export contract

JSON is the default. It returns the standard `data` envelope plus `meta.report`, including stable column order, property and date scope, row count, and generation time. `meta.performance` reports elapsed query time against a 250 ms target.

Set `format=csv` to receive `text/csv` with the same stable columns and a `Content-Disposition` attachment filename. `X-Report-Row-Count` contains the number of data rows, and `Server-Timing` exposes report duration. Text values that could be interpreted as spreadsheet formulas are escaped before export.
