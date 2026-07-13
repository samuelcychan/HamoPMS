<?php

namespace Modules\Property\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Property\Models\MaintenanceTicket;
use Modules\Property\Models\Property;
use Modules\Property\Models\Room;
use Modules\Property\Services\RoomInventoryService;

class RoomController extends Controller
{
    public function __construct(private readonly RoomInventoryService $inventory) {}

    public function index(Request $request, string $propertyId): JsonResponse
    {
        Property::findOrFail($propertyId);
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'room_type_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::in(Room::STATUSES)],
        ]);

        $rooms = Room::query()
            ->where('property_id', $propertyId)
            ->with('roomType', 'amenities')
            ->when(
                isset($validated['room_type_id']),
                fn ($query) => $query->where('room_type_id', $validated['room_type_id']),
            )
            ->when(
                isset($validated['status']),
                fn ($query) => $query->where('status', $validated['status']),
            )
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::paginated($rooms);
    }

    public function store(Request $request, string $propertyId): JsonResponse
    {
        $property = Property::findOrFail($propertyId);
        $validated = $request->validate($this->rules($property));

        $room = $this->inventory->createRoom($request->user()->id, [
            ...$validated,
            'property_id' => $property->id,
        ]);

        return ApiResponse::success($room->load('roomType', 'amenities'), 201);
    }

    public function show(string $propertyId, string $roomId): JsonResponse
    {
        $room = $this->findRoom($propertyId, $roomId);

        return ApiResponse::success($room->load('roomType', 'amenities'));
    }

    public function update(Request $request, string $propertyId, string $roomId): JsonResponse
    {
        $property = Property::findOrFail($propertyId);
        $room = $this->findRoom($propertyId, $roomId);
        $room->update($request->validate($this->rules($property, $room)));

        return ApiResponse::success($room->load('roomType', 'amenities'));
    }

    public function destroy(string $propertyId, string $roomId): Response
    {
        $room = $this->findRoom($propertyId, $roomId);
        $room->delete();

        return response()->noContent();
    }

    public function updateStatus(Request $request, string $propertyId, string $roomId): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(Room::STATUSES)],
            'reason' => ['nullable', 'required_if:status,'.Room::STATUS_OUT_OF_SERVICE, 'string', 'max:1000'],
        ]);
        $room = $this->findRoom($propertyId, $roomId);

        if ($validated['status'] === Room::STATUS_CLEANING
            || ($room->status === Room::STATUS_CLEANING && $validated['status'] === Room::STATUS_CLEAN)) {
            throw ValidationException::withMessages([
                'status' => ['Use the housekeeping task lifecycle for cleaning transitions.'],
            ]);
        }

        if ($room->status === Room::STATUS_OUT_OF_SERVICE
            && $validated['status'] !== Room::STATUS_OUT_OF_SERVICE
            && MaintenanceTicket::query()
                ->where('room_id', $room->id)
                ->whereIn('status', MaintenanceTicket::ACTIVE_STATUSES)
                ->exists()) {
            throw ValidationException::withMessages([
                'status' => ['Resolve or delete the active maintenance ticket to restore this room.'],
            ]);
        }

        $room = $this->inventory->transitionStatus(
            (int) $propertyId,
            (int) $roomId,
            $validated['status'],
            $request->user()->id,
            $validated['reason'] ?? null,
        );

        return ApiResponse::success($room);
    }

    public function statusHistory(Request $request, string $propertyId, string $roomId): JsonResponse
    {
        $room = $this->findRoom($propertyId, $roomId);
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return ApiResponse::paginated(
            $room->statusHistory()
                ->latest('id')
                ->paginate($request->integer('per_page', 15)),
        );
    }

    public function syncAmenities(Request $request, string $propertyId, string $roomId): JsonResponse
    {
        $validated = $request->validate([
            'amenity_ids' => ['required', 'array', 'max:100'],
            'amenity_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('amenities', 'id')
                    ->where(fn ($query) => $query
                        ->where('property_id', $propertyId)
                        ->whereNull('deleted_at')),
            ],
        ]);
        $room = $this->findRoom($propertyId, $roomId);
        $room->amenities()->sync($validated['amenity_ids']);

        return ApiResponse::success($room->load('roomType', 'amenities'));
    }

    private function findRoom(string $propertyId, string $roomId): Room
    {
        return Room::query()
            ->where('property_id', $propertyId)
            ->findOrFail($roomId);
    }

    private function rules(Property $property, ?Room $room = null): array
    {
        $presence = $room === null ? 'required' : 'sometimes';

        return [
            'room_type_id' => [
                $presence,
                'integer',
                Rule::exists('room_types', 'id')
                    ->where(fn ($query) => $query->where('property_id', $property->id)->whereNull('deleted_at')),
            ],
            'number' => [
                $presence,
                'string',
                'max:50',
                Rule::unique('rooms', 'number')
                    ->where(fn ($query) => $query->where('property_id', $property->id))
                    ->ignore($room?->id),
            ],
            'floor' => ['nullable', 'string', 'max:50'],
        ];
    }
}
