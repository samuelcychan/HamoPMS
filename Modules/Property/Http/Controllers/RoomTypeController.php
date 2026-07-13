<?php

namespace Modules\Property\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Property\Models\Property;
use Modules\Property\Models\RoomType;

class RoomTypeController extends Controller
{
    public function index(Request $request, string $propertyId): JsonResponse
    {
        $property = Property::findOrFail($propertyId);
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return ApiResponse::paginated(
            $property->roomTypes()
                ->withCount('rooms')
                ->paginate($request->integer('per_page', 15)),
        );
    }

    public function store(Request $request, string $propertyId): JsonResponse
    {
        $property = Property::findOrFail($propertyId);
        $validated = $request->validate($this->rules($property));

        $roomType = $property->roomTypes()->create($validated);

        return ApiResponse::success($roomType, 201);
    }

    public function show(string $propertyId, string $roomTypeId): JsonResponse
    {
        $roomType = $this->findRoomType($propertyId, $roomTypeId);

        return ApiResponse::success($roomType->loadCount('rooms'));
    }

    public function update(Request $request, string $propertyId, string $roomTypeId): JsonResponse
    {
        $property = Property::findOrFail($propertyId);
        $roomType = $this->findRoomType($propertyId, $roomTypeId);
        $roomType->update($request->validate($this->rules($property, $roomType)));

        return ApiResponse::success($roomType);
    }

    public function destroy(string $propertyId, string $roomTypeId): JsonResponse|Response
    {
        $roomType = $this->findRoomType($propertyId, $roomTypeId);

        if ($roomType->rooms()->withTrashed()->exists()) {
            return ApiResponse::error(
                'ROOM_TYPE_IN_USE',
                'A room type with rooms cannot be deleted.',
                409,
            );
        }

        $roomType->delete();

        return response()->noContent();
    }

    private function findRoomType(string $propertyId, string $roomTypeId): RoomType
    {
        return RoomType::query()
            ->where('property_id', $propertyId)
            ->findOrFail($roomTypeId);
    }

    private function rules(Property $property, ?RoomType $roomType = null): array
    {
        $presence = $roomType === null ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'string', 'max:255'],
            'code' => [
                $presence,
                'string',
                'max:50',
                Rule::unique('room_types', 'code')
                    ->where(fn ($query) => $query->where('property_id', $property->id))
                    ->ignore($roomType?->id),
            ],
            'description' => ['nullable', 'string'],
            'max_occupancy' => [$presence, 'integer', 'min:1', 'max:100'],
            'base_rate' => [$presence, 'numeric', 'min:0', 'max:99999999.99'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
