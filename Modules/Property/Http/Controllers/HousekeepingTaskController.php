<?php

namespace Modules\Property\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Property\Models\HousekeepingTask;
use Modules\Property\Services\HousekeepingTaskService;

class HousekeepingTaskController extends Controller
{
    public function __construct(private readonly HousekeepingTaskService $tasks) {}

    public function index(Request $request, string $propertyId): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', Rule::in(HousekeepingTask::STATUSES)],
            'assigned_to' => ['sometimes', 'integer', 'min:1'],
        ]);
        $tasks = HousekeepingTask::query()
            ->where('property_id', $propertyId)
            ->with(['room.roomType', 'assignedToUser:id,name,email'])
            ->when(
                isset($validated['status']),
                fn ($query) => $query->where('status', $validated['status']),
            )
            ->when(
                isset($validated['assigned_to']),
                fn ($query) => $query->where('assigned_to', $validated['assigned_to']),
            )
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END")
            ->orderBy('due_at')
            ->orderBy('id')
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::paginated($tasks);
    }

    public function show(string $propertyId, string $taskId): JsonResponse
    {
        return ApiResponse::success($this->findTask($propertyId, $taskId)->load([
            'room.roomType',
            'booking',
            'assignedToUser:id,name,email',
            'assignedByUser:id,name,email',
            'completedByUser:id,name,email',
        ]));
    }

    public function shiftReport(Request $request, string $propertyId): JsonResponse
    {
        $validated = $request->validate(['date' => ['sometimes', 'date_format:Y-m-d']]);
        $date = isset($validated['date'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $validated['date'])->startOfDay()
            : CarbonImmutable::today();
        $tasks = HousekeepingTask::query()
            ->where('property_id', $propertyId)
            ->where('created_at', '>=', $date)
            ->where('created_at', '<', $date->addDay())
            ->get();
        $completed = $tasks->where('status', HousekeepingTask::STATUS_COMPLETED);
        $completedLate = $completed->filter(fn (HousekeepingTask $task): bool => $task->completed_at->greaterThan($task->due_at));
        $overdue = $tasks->filter(fn (HousekeepingTask $task): bool => $task->due_at->lessThan(
            $task->completed_at ?? CarbonImmutable::now(),
        ));
        $averageTurnaround = $completed->isEmpty()
            ? null
            : round($completed->average(fn (HousekeepingTask $task): float => $task->created_at->diffInMinutes($task->completed_at)), 2);

        return ApiResponse::success([
            'date' => $date->toDateString(),
            'total_tasks' => $tasks->count(),
            'status' => collect(HousekeepingTask::STATUSES)
                ->mapWithKeys(fn (string $status): array => [$status => $tasks->where('status', $status)->count()]),
            'priority' => collect(['low', 'normal', 'high', 'urgent'])
                ->mapWithKeys(fn (string $priority): array => [$priority => $tasks->where('priority', $priority)->count()]),
            'overdue_tasks' => $overdue->count(),
            'completed_tasks' => $completed->count(),
            'completed_on_time' => $completed->count() - $completedLate->count(),
            'completed_late' => $completedLate->count(),
            'average_turnaround_minutes' => $averageTurnaround,
        ]);
    }

    public function assign(Request $request, string $propertyId, string $taskId): JsonResponse
    {
        $validated = $request->validate(['assigned_to' => ['required', 'integer', 'exists:users,id']]);

        return ApiResponse::success($this->tasks->assign(
            (int) $propertyId,
            (int) $taskId,
            (int) $validated['assigned_to'],
            $request->user()->id,
        ));
    }

    public function start(Request $request, string $propertyId, string $taskId): JsonResponse
    {
        return ApiResponse::success($this->tasks->start(
            (int) $propertyId,
            (int) $taskId,
            $request->user()->id,
        ));
    }

    public function complete(Request $request, string $propertyId, string $taskId): JsonResponse
    {
        $validated = $request->validate([
            'completion_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        return ApiResponse::success($this->tasks->complete(
            (int) $propertyId,
            (int) $taskId,
            $request->user()->id,
            $validated['completion_notes'] ?? null,
        ));
    }

    private function findTask(string $propertyId, string $taskId): HousekeepingTask
    {
        return HousekeepingTask::query()
            ->where('property_id', $propertyId)
            ->findOrFail($taskId);
    }
}
