# Maintenance tickets

Maintenance tickets are property-scoped operational records tied to a room. Creating a ticket atomically moves a clean or dirty vacant room to `out_of_service`, so availability searches immediately count it as blocked inventory. Occupied and cleaning rooms must finish their current operational workflow before a ticket can take them out of service.

Tickets progress through `open` → `assigned` → `in_progress` → `resolved`. Assignment requires an active user with `rooms.write` access to the ticket's property, and only that assignee may start or resolve the ticket. Resolution notes are required; resolving the ticket moves the room from `out_of_service` to `clean`, making it sellable again.

Priority is `normal`, `high`, or `urgent`. Escalation is tracked separately as `none`, `escalated`, or `critical`, with the actor, timestamp, and reason recorded. The maintenance board sorts critical escalation first and then urgent priority, and supports filters for status, priority, escalation, assignee, and room.

The CRUD API supports list, create, retrieve, update, and soft delete. Deleting an unresolved ticket restores the room status captured before maintenance; deleting a resolved ticket leaves the already-clean room unchanged. Direct room status updates cannot restore a room while it has an active ticket.

Every create, update, escalation, assignment, start, resolution, and deletion appends an immutable ticket history entry. Room availability changes are also written to the existing room status history with the ticket ID and actor.
