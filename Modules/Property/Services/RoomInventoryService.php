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
}
