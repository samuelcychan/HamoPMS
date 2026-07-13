<?php

namespace Modules\Property\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Property\Models\HousekeepingTask;
use Modules\Property\Models\Room;

class HousekeepingTaskService
{
    public function __construct(private readonly RoomInventoryService $rooms) {}

    public function createForCheckout(
        int $propertyId,
        int $roomId,
        int $bookingId,
        int $actorId,
    ): HousekeepingTask {
        return HousekeepingTask::firstOrCreate(
            ['booking_id' => $bookingId],
            [
                'property_id' => $propertyId,
                'room_id' => $roomId,
                'created_by' => $actorId,
                'status' => HousekeepingTask::STATUS_PENDING,
                'priority' => HousekeepingTask::PRIORITY_NORMAL,
                'due_at' => now()->addMinutes(max(1, (int) config('housekeeping.turnaround_minutes', 120))),
                'notes' => "Turn over room after reservation {$bookingId}.",
            ],
        )->load(['room.roomType', 'booking']);
    }

    public function assign(int $propertyId, int $taskId, int $assigneeId, int $actorId): HousekeepingTask
    {
        return DB::transaction(function () use ($propertyId, $taskId, $assigneeId, $actorId): HousekeepingTask {
            $task = $this->taskForUpdate($propertyId, $taskId);

            if (! in_array($task->status, [HousekeepingTask::STATUS_PENDING, HousekeepingTask::STATUS_ASSIGNED], true)) {
                throw ValidationException::withMessages([
                    'task' => ['Only a pending or assigned housekeeping task can be reassigned.'],
                ]);
            }

            $assignee = User::query()->where('is_active', true)->findOrFail($assigneeId);

            if (! $assignee->hasPermission('rooms.write', $propertyId)) {
                throw ValidationException::withMessages([
                    'assigned_to' => ['The assignee must have room write access for this property.'],
                ]);
            }

            $task->update([
                'assigned_to' => $assignee->id,
                'assigned_by' => $actorId,
                'assigned_at' => now(),
                'status' => HousekeepingTask::STATUS_ASSIGNED,
            ]);

            return $this->loadTask($task);
        });
    }

    public function start(int $propertyId, int $taskId, int $actorId): HousekeepingTask
    {
        return DB::transaction(function () use ($propertyId, $taskId, $actorId): HousekeepingTask {
            $task = $this->taskForUpdate($propertyId, $taskId);
            $this->assertAssignedActor($task, $actorId);

            if ($task->status !== HousekeepingTask::STATUS_ASSIGNED) {
                throw ValidationException::withMessages([
                    'task' => ['Only an assigned housekeeping task can be started.'],
                ]);
            }

            $this->rooms->transitionStatus(
                $propertyId,
                $task->room_id,
                Room::STATUS_CLEANING,
                $actorId,
                "Started housekeeping task {$task->id}.",
            );
            $task->update([
                'status' => HousekeepingTask::STATUS_IN_PROGRESS,
                'started_at' => now(),
            ]);

            return $this->loadTask($task);
        });
    }

    public function complete(
        int $propertyId,
        int $taskId,
        int $actorId,
        ?string $completionNotes = null,
    ): HousekeepingTask {
        return DB::transaction(function () use ($propertyId, $taskId, $actorId, $completionNotes): HousekeepingTask {
            $task = $this->taskForUpdate($propertyId, $taskId);
            $this->assertAssignedActor($task, $actorId);

            if ($task->status !== HousekeepingTask::STATUS_IN_PROGRESS) {
                throw ValidationException::withMessages([
                    'task' => ['Only an in-progress housekeeping task can be completed.'],
                ]);
            }

            $this->rooms->transitionStatus(
                $propertyId,
                $task->room_id,
                Room::STATUS_CLEAN,
                $actorId,
                "Completed housekeeping task {$task->id}.",
            );
            $task->update([
                'status' => HousekeepingTask::STATUS_COMPLETED,
                'completed_by' => $actorId,
                'completed_at' => now(),
                'completion_notes' => $completionNotes,
            ]);

            return $this->loadTask($task);
        });
    }

    private function taskForUpdate(int $propertyId, int $taskId): HousekeepingTask
    {
        return HousekeepingTask::query()
            ->where('property_id', $propertyId)
            ->lockForUpdate()
            ->findOrFail($taskId);
    }

    private function assertAssignedActor(HousekeepingTask $task, int $actorId): void
    {
        if ((int) $task->assigned_to !== $actorId) {
            throw ValidationException::withMessages([
                'assigned_to' => ['Only the assigned housekeeper can perform this task transition.'],
            ]);
        }
    }

    private function loadTask(HousekeepingTask $task): HousekeepingTask
    {
        return $task->refresh()->load([
            'room.roomType',
            'booking',
            'assignedToUser:id,name,email',
            'assignedByUser:id,name,email',
            'completedByUser:id,name,email',
        ]);
    }
}
