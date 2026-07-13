# Availability search

`GET /api/v1/availability` returns active room types that can accommodate the requested occupancy and have at least one sellable room for every requested night.

Required query parameters are `property_id`, `check_in`, `check_out`, and `occupancy`. Date ranges use half-open hotel-night semantics: a booking with `check_out` equal to a new search's `check_in` does not overlap.

Inventory is calculated per room type:

```text
available_inventory = total_inventory - blocked_inventory - reserved_inventory
```

Rooms in `out_of_service` status are blocked. Pending and confirmed bookings whose date ranges overlap the search are reserved. Inactive room types, room types below the occupancy requirement, and sold-out room types are excluded.

## Performance target

The API target is **p95 <= 250 ms** for a single-property search with up to 100 room types and 10,000 rooms. Every response reports the measured service duration in `meta.performance.duration_ms`, whether it met the target, the `Server-Timing` header, and `X-Response-Time-Target: 250ms`.

This in-process duration measures the inventory query and result transformation. Production p95 should be monitored at the HTTP edge so network and middleware time are included.
