# Housekeeping task lifecycle

Successful check-out creates one pending housekeeping task for the released room and reservation in the same database transaction. The task defaults to normal priority and is due after `HOUSEKEEPING_TURNAROUND_MINUTES` (120 minutes by default). A unique reservation reference prevents duplicate checkout tasks.

Property-scoped users with `rooms.read` can list or inspect tasks. The board supports `status`, `assigned_to`, and pagination filters and sorts by priority, due time, and identifier. Users with `rooms.write` can assign tasks to active staff who also have room-write access for that property.

## Shift report

`GET /api/v1/properties/{propertyId}/housekeeping-tasks/shift-report?date=YYYY-MM-DD` summarizes tasks created during the selected property day. It returns task counts by status and priority, overdue and on-time/late completion counts, and average completion turnaround in minutes. The date defaults to today and the same `rooms.read` property authorization as the live board applies.

## Transitions

1. `PATCH /api/v1/properties/{propertyId}/housekeeping-tasks/{taskId}/assignment` changes `pending → assigned` and records the dispatcher and assignment time.
2. The assigned staff member calls `POST .../{taskId}/start`, changing the task to `in_progress` and the room from `dirty → cleaning` with an immutable room-status history entry.
3. The same staff member calls `POST .../{taskId}/complete`, optionally with `completion_notes`. The task becomes `completed`, records the actor and time, and changes the room from `cleaning → clean`.

Only clean rooms can be checked in. Direct room-status updates cannot perform `dirty → cleaning` or `cleaning → clean`; those transitions must pass through the assigned task so completion and accountability remain synchronized with availability.
