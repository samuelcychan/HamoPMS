<?php

namespace Modules\Property\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Property\Models\MaintenanceTicket;
use Modules\Property\Services\MaintenanceTicketService;

class MaintenanceTicketController extends Controller
{
    public function __construct(private readonly MaintenanceTicketService $tickets) {}

    public function index(Request $request, string $propertyId): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', Rule::in(MaintenanceTicket::STATUSES)],
            'priority' => ['sometimes', Rule::in(MaintenanceTicket::PRIORITIES)],
            'escalation_status' => ['sometimes', Rule::in(MaintenanceTicket::ESCALATION_STATUSES)],
            'assigned_to' => ['sometimes', 'integer', 'min:1'],
            'room_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        $tickets = MaintenanceTicket::query()
            ->where('property_id', $propertyId)
            ->with(['room.roomType', 'assignedToUser:id,name,email'])
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->when(isset($validated['priority']), fn ($query) => $query->where('priority', $validated['priority']))
            ->when(
                isset($validated['escalation_status']),
                fn ($query) => $query->where('escalation_status', $validated['escalation_status']),
            )
            ->when(
                isset($validated['assigned_to']),
                fn ($query) => $query->where('assigned_to', $validated['assigned_to']),
            )
            ->when(isset($validated['room_id']), fn ($query) => $query->where('room_id', $validated['room_id']))
            ->orderByRaw("CASE escalation_status WHEN 'critical' THEN 1 WHEN 'escalated' THEN 2 ELSE 3 END")
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 ELSE 3 END")
            ->orderBy('id')
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::paginated($tickets);
    }

    public function store(Request $request, string $propertyId): JsonResponse
    {
        $validated = $request->validate([
            'room_id' => [
                'required',
                'integer',
                Rule::exists('rooms', 'id')->where(
                    fn ($query) => $query->where('property_id', $propertyId)->whereNull('deleted_at'),
                ),
            ],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:5000'],
            'priority' => ['sometimes', Rule::in(MaintenanceTicket::PRIORITIES)],
        ]);

        return ApiResponse::success(
            $this->tickets->create((int) $propertyId, $request->user()->id, $validated),
            201,
        );
    }

    public function show(string $propertyId, string $ticketId): JsonResponse
    {
        return ApiResponse::success($this->findTicket($propertyId, $ticketId)->load([
            'room.roomType',
            'createdByUser:id,name,email',
            'assignedToUser:id,name,email',
            'assignedByUser:id,name,email',
            'escalatedByUser:id,name,email',
            'resolvedByUser:id,name,email',
        ]));
    }

    public function update(Request $request, string $propertyId, string $ticketId): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'description' => ['sometimes', 'required', 'string', 'max:5000'],
            'priority' => ['sometimes', Rule::in(MaintenanceTicket::PRIORITIES)],
            'escalation_status' => ['sometimes', Rule::in(MaintenanceTicket::ESCALATION_STATUSES)],
            'escalation_reason' => [
                'nullable',
                'required_if:escalation_status,'.MaintenanceTicket::ESCALATION_ESCALATED.','.MaintenanceTicket::ESCALATION_CRITICAL,
                'string',
                'max:2000',
            ],
        ]);

        return ApiResponse::success($this->tickets->update(
            (int) $propertyId,
            (int) $ticketId,
            $request->user()->id,
            $validated,
        ));
    }

    public function destroy(Request $request, string $propertyId, string $ticketId): Response
    {
        $this->tickets->delete((int) $propertyId, (int) $ticketId, $request->user()->id);

        return response()->noContent();
    }

    public function assign(Request $request, string $propertyId, string $ticketId): JsonResponse
    {
        $validated = $request->validate(['assigned_to' => ['required', 'integer', 'exists:users,id']]);

        return ApiResponse::success($this->tickets->assign(
            (int) $propertyId,
            (int) $ticketId,
            (int) $validated['assigned_to'],
            $request->user()->id,
        ));
    }

    public function start(Request $request, string $propertyId, string $ticketId): JsonResponse
    {
        return ApiResponse::success($this->tickets->start(
            (int) $propertyId,
            (int) $ticketId,
            $request->user()->id,
        ));
    }

    public function resolve(Request $request, string $propertyId, string $ticketId): JsonResponse
    {
        $validated = $request->validate([
            'resolution_notes' => ['required', 'string', 'max:5000'],
        ]);

        return ApiResponse::success($this->tickets->resolve(
            (int) $propertyId,
            (int) $ticketId,
            $request->user()->id,
            $validated['resolution_notes'],
        ));
    }

    public function history(Request $request, string $propertyId, string $ticketId): JsonResponse
    {
        $ticket = $this->findTicket($propertyId, $ticketId);
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return ApiResponse::paginated(
            $ticket->history()
                ->with('changedByUser:id,name,email')
                ->latest('id')
                ->paginate($request->integer('per_page', 15)),
        );
    }

    private function findTicket(string $propertyId, string $ticketId): MaintenanceTicket
    {
        return MaintenanceTicket::query()
            ->where('property_id', $propertyId)
            ->findOrFail($ticketId);
    }
}
