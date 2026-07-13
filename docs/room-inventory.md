# Room inventory

Room types, rooms, and amenities are owned by one property. Amenity codes are unique within that property, and `PUT /api/v1/properties/{propertyId}/rooms/{roomId}/amenities` replaces a room's complete amenity set. Cross-property amenity assignment is rejected.

Rooms use `clean`, `dirty`, `cleaning`, `occupied`, and `out_of_service` states. Moving a room to `out_of_service` blocks it from availability and requires an audit reason. A room cannot leave that state while it has an active maintenance ticket. Cleaning transitions are reserved for the housekeeping workflow, and occupied/cleaning rooms cannot be taken into maintenance until their current operational workflow finishes.

Every room creation and state transition appends an immutable `room_status_histories` entry with the previous state, next state, actor, reason, and timestamp. The history is available from `GET /api/v1/properties/{propertyId}/rooms/{roomId}/status-history`.
