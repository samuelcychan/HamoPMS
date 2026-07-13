<?php

namespace Modules\Property\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Property\Models\MaintenanceTicket;
use Modules\Property\Models\Room;
use Modules\Property\Models\RoomType;

class MaintenanceTicketService
{
    public function __construct(private readonly RoomInventoryService $rooms) {}

    public function create(int $propertyId, int $actorId, array $attributes): MaintenanceTicket
    {
        return DB::transaction(function () use ($propertyId, $actorId, $attributes): MaintenanceTicket {
            $roomTypeId = Room::query()
                ->where('property_id', $propertyId)
                ->findOrFail($attributes['room_id'])
                ->room_type_id;

            RoomType::query()->whereKey($roomTypeId)->lockForUpdate()->firstOrFail();
            $room = Room::query()
                ->where('property_id', $propertyId)
                ->lockForUpdate()
                ->findOrFail($attributes['room_id']);

            if (! in_array($room->status, [Room::STATUS_CLEAN, Room::STATUS_DIRTY], true)) {
                throw ValidationException::withMessages([
                    'room_id' => ['Only a clean or dirty vacant room can be taken out of service.'],
                ]);
            }

            $hasActiveTicket = MaintenanceTicket::query()
                ->where('room_id', $room->id)
                ->whereIn('status', MaintenanceTicket::ACTIVE_STATUSES)
                ->lockForUpdate()
                ->exists();

            if ($hasActiveTicket) {
                throw ValidationException::withMessages([
                    'room_id' => ['This room already has an active maintenance ticket.'],
                ]);
            }

            $ticket = MaintenanceTicket::create([
                ...Arr::only($attributes, ['room_id', 'title', 'description', 'priority']),
                'property_id' => $propertyId,
                'created_by' => $actorId,
                'status' => MaintenanceTicket::STATUS_OPEN,
                'priority' => $attributes['priority'] ?? MaintenanceTicket::PRIORITY_NORMAL,
                'escalation_status' => MaintenanceTicket::ESCALATION_NONE,
                'room_status_before_maintenance' => $room->status,
            ]);

            $this->rooms->transitionStatus(
                $propertyId,
                $room->id,
                Room::STATUS_OUT_OF_SERVICE,
                $actorId,
                "Opened maintenance ticket {$ticket->id}: {$ticket->title}",
            );
            $this->recordHistory($ticket, 'created', null, $ticket->status, $actorId, [
                'room_status_before_maintenance' => $ticket->room_status_before_maintenance,
                'priority' => $ticket->priority,
            ]);

            return $this->loadTicket($ticket);
        });
    }

    public function update(
        int $propertyId,
        int $ticketId,
        int $actorId,
        array $attributes,
    ): MaintenanceTicket {
        return DB::transaction(function () use ($propertyId, $ticketId, $actorId, $attributes): MaintenanceTicket {
            $ticket = $this->ticketForUpdate($propertyId, $ticketId);

            if ($ticket->status === MaintenanceTicket::STATUS_RESOLVED) {
                throw ValidationException::withMessages([
                    'ticket' => ['A resolved maintenance ticket cannot be updated.'],
                ]);
            }

            $changes = Arr::only($attributes, [
                'title',
                'description',
                'priority',
                'escalation_status',
                'escalation_reason',
            ]);
            $event = 'updated';

            if (array_key_exists('escalation_status', $changes)) {
                if ($changes['escalation_status'] === MaintenanceTicket::ESCALATION_NONE) {
                    $changes['escalated_by'] = null;
                    $changes['escalated_at'] = null;
                    $changes['escalation_reason'] = null;
                } else {
                    $changes['escalated_by'] = $actorId;
                    $changes['escalated_at'] = now();
                    $event = 'escalated';
                }
            }

            $ticket->update($changes);
            $this->recordHistory($ticket, $event, $ticket->status, $ticket->status, $actorId, $changes);

            return $this->loadTicket($ticket);
        });
    }

    public function assign(int $propertyId, int $ticketId, int $assigneeId, int $actorId): MaintenanceTicket
    {
        return DB::transaction(function () use ($propertyId, $ticketId, $assigneeId, $actorId): MaintenanceTicket {
            $ticket = $this->ticketForUpdate($propertyId, $ticketId);

            if (! in_array($ticket->status, [MaintenanceTicket::STATUS_OPEN, MaintenanceTicket::STATUS_ASSIGNED], true)) {
                throw ValidationException::withMessages([
                    'ticket' => ['Only an open or assigned maintenance ticket can be reassigned.'],
                ]);
            }

            $assignee = User::query()->where('is_active', true)->findOrFail($assigneeId);

            if (! $assignee->hasPermission('rooms.write', $propertyId)) {
                throw ValidationException::withMessages([
                    'assigned_to' => ['The assignee must have room write access for this property.'],
                ]);
            }

            $fromStatus = $ticket->status;
            $ticket->update([
                'assigned_to' => $assignee->id,
                'assigned_by' => $actorId,
                'assigned_at' => now(),
                'status' => MaintenanceTicket::STATUS_ASSIGNED,
            ]);
            $this->recordHistory($ticket, 'assigned', $fromStatus, $ticket->status, $actorId, [
                'assigned_to' => $assignee->id,
            ]);

            return $this->loadTicket($ticket);
        });
    }

    public function start(int $propertyId, int $ticketId, int $actorId): MaintenanceTicket
    {
        return DB::transaction(function () use ($propertyId, $ticketId, $actorId): MaintenanceTicket {
            $ticket = $this->ticketForUpdate($propertyId, $ticketId);
            $this->assertAssignedActor($ticket, $actorId);

            if ($ticket->status !== MaintenanceTicket::STATUS_ASSIGNED) {
                throw ValidationException::withMessages([
                    'ticket' => ['Only an assigned maintenance ticket can be started.'],
                ]);
            }

            $ticket->update([
                'status' => MaintenanceTicket::STATUS_IN_PROGRESS,
                'started_at' => now(),
            ]);
            $this->recordHistory(
                $ticket,
                'started',
                MaintenanceTicket::STATUS_ASSIGNED,
                $ticket->status,
                $actorId,
            );

            return $this->loadTicket($ticket);
        });
    }

    public function resolve(
        int $propertyId,
        int $ticketId,
        int $actorId,
        string $resolutionNotes,
    ): MaintenanceTicket {
        return DB::transaction(function () use ($propertyId, $ticketId, $actorId, $resolutionNotes): MaintenanceTicket {
            $ticket = $this->ticketForUpdate($propertyId, $ticketId);
            $this->assertAssignedActor($ticket, $actorId);

            if ($ticket->status !== MaintenanceTicket::STATUS_IN_PROGRESS) {
                throw ValidationException::withMessages([
                    'ticket' => ['Only an in-progress maintenance ticket can be resolved.'],
                ]);
            }

            $this->rooms->transitionStatus(
                $propertyId,
                $ticket->room_id,
                Room::STATUS_CLEAN,
                $actorId,
                "Resolved maintenance ticket {$ticket->id}: {$resolutionNotes}",
            );
            $ticket->update([
                'status' => MaintenanceTicket::STATUS_RESOLVED,
                'resolved_by' => $actorId,
                'resolved_at' => now(),
                'resolution_notes' => $resolutionNotes,
            ]);
            $this->recordHistory(
                $ticket,
                'resolved',
                MaintenanceTicket::STATUS_IN_PROGRESS,
                $ticket->status,
                $actorId,
                ['resolution_notes' => $resolutionNotes],
            );

            return $this->loadTicket($ticket);
        });
    }

    public function delete(int $propertyId, int $ticketId, int $actorId): void
    {
        DB::transaction(function () use ($propertyId, $ticketId, $actorId): void {
            $ticket = $this->ticketForUpdate($propertyId, $ticketId);

            if ($ticket->status !== MaintenanceTicket::STATUS_RESOLVED) {
                $this->rooms->transitionStatus(
                    $propertyId,
                    $ticket->room_id,
                    $ticket->room_status_before_maintenance,
                    $actorId,
                    "Cancelled maintenance ticket {$ticket->id}.",
                );
            }

            $this->recordHistory($ticket, 'deleted', $ticket->status, null, $actorId);
            $ticket->delete();
        });
    }

    private function ticketForUpdate(int $propertyId, int $ticketId): MaintenanceTicket
    {
        return MaintenanceTicket::query()
            ->where('property_id', $propertyId)
            ->lockForUpdate()
            ->findOrFail($ticketId);
    }

    private function assertAssignedActor(MaintenanceTicket $ticket, int $actorId): void
    {
        if ((int) $ticket->assigned_to !== $actorId) {
            throw ValidationException::withMessages([
                'assigned_to' => ['Only the assigned staff member can perform this transition.'],
            ]);
        }
    }

    private function recordHistory(
        MaintenanceTicket $ticket,
        string $event,
        ?string $fromStatus,
        ?string $toStatus,
        int $actorId,
        ?array $details = null,
    ): void {
        $ticket->history()->create([
            'event' => $event,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'changed_by' => $actorId,
            'details' => $details,
        ]);
    }

    private function loadTicket(MaintenanceTicket $ticket): MaintenanceTicket
    {
        return $ticket->refresh()->load([
            'room.roomType',
            'createdByUser:id,name,email',
            'assignedToUser:id,name,email',
            'assignedByUser:id,name,email',
            'escalatedByUser:id,name,email',
            'resolvedByUser:id,name,email',
        ]);
    }
}
