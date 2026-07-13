<?php

namespace Modules\Property\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Property\Models\Room;
use Modules\Property\Models\RoomType;

class RoomInventoryService
{
    public function createRoom(int $userId, array $attributes): Room
    {
        return DB::transaction(function () use ($userId, $attributes): Room {
            $room = Room::create([
                ...$attributes,
                'status' => Room::STATUS_CLEAN,
            ]);

            $room->statusHistory()->create([
                'from_status' => null,
                'to_status' => Room::STATUS_CLEAN,
                'changed_by' => $userId,
                'reason' => 'Room created.',
            ]);

            return $room->load('roomType');
        });
    }

    public function transitionStatus(
        int $propertyId,
        int $roomId,
        string $toStatus,
        int $userId,
        ?string $reason = null,
    ): Room {
        return DB::transaction(function () use ($propertyId, $roomId, $toStatus, $userId, $reason): Room {
            $roomTypeId = Room::query()
                ->where('property_id', $propertyId)
                ->findOrFail($roomId)
                ->room_type_id;

            RoomType::query()
                ->whereKey($roomTypeId)
                ->lockForUpdate()
                ->firstOrFail();

            $room = Room::query()
                ->where('property_id', $propertyId)
                ->lockForUpdate()
                ->findOrFail($roomId);

            $fromStatus = $room->status;
            $allowed = Room::ALLOWED_STATUS_TRANSITIONS[$fromStatus] ?? [];

            if (! in_array($toStatus, $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => ["Room status cannot transition from {$fromStatus} to {$toStatus}."],
                ]);
            }

            $room->update(['status' => $toStatus]);
            $room->statusHistory()->create([
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'changed_by' => $userId,
                'reason' => $reason,
            ]);

            return $room->load('roomType');
        });
    }

    public function moveOccupiedRoom(
        int $propertyId,
        int $fromRoomId,
        int $toRoomId,
        int $userId,
        string $reason,
    ): array {
        if ($fromRoomId === $toRoomId) {
            throw ValidationException::withMessages([
                'room_id' => ['The destination room must be different from the current room.'],
            ]);
        }

        return DB::transaction(function () use ($propertyId, $fromRoomId, $toRoomId, $userId, $reason): array {
            $roomTypeIds = Room::query()
                ->where('property_id', $propertyId)
                ->whereIn('id', [$fromRoomId, $toRoomId])
                ->pluck('room_type_id');

            abort_unless($roomTypeIds->count() === 2, 404);

            $roomTypes = RoomType::query()
                ->whereIn('id', $roomTypeIds->unique())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $rooms = Room::query()
                ->where('property_id', $propertyId)
                ->whereIn('id', [$fromRoomId, $toRoomId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $from = $rooms->get($fromRoomId);
            $to = $rooms->get($toRoomId);

            abort_unless($from && $to, 404);

            $destinationType = $roomTypes->get((int) $to->room_type_id);

            if ($from->status !== Room::STATUS_OCCUPIED) {
                throw ValidationException::withMessages([
                    'room_id' => ['The current room must be occupied.'],
                ]);
            }

            if ($to->status !== Room::STATUS_CLEAN || ! $destinationType?->is_active) {
                throw ValidationException::withMessages([
                    'room_id' => ['The destination room must be clean and active.'],
                ]);
            }

            $from->update(['status' => Room::STATUS_DIRTY]);
            $from->statusHistory()->create([
                'from_status' => Room::STATUS_OCCUPIED,
                'to_status' => Room::STATUS_DIRTY,
                'changed_by' => $userId,
                'reason' => $reason,
            ]);
            $to->update(['status' => Room::STATUS_OCCUPIED]);
            $to->statusHistory()->create([
                'from_status' => Room::STATUS_CLEAN,
                'to_status' => Room::STATUS_OCCUPIED,
                'changed_by' => $userId,
                'reason' => $reason,
            ]);

            return [
                'from' => $from->load('roomType'),
                'to' => $to->load('roomType'),
            ];
        });
    }
}
