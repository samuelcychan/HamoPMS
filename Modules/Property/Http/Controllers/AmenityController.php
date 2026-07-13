<?php

namespace Modules\Property\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Property\Models\Amenity;
use Modules\Property\Models\Property;

class AmenityController extends Controller
{
    public function index(Request $request, string $propertyId): JsonResponse
    {
        Property::findOrFail($propertyId);
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return ApiResponse::paginated(
            Amenity::query()
                ->where('property_id', $propertyId)
                ->withCount('rooms')
                ->orderBy('name')
                ->paginate($request->integer('per_page', 15)),
        );
    }

    public function store(Request $request, string $propertyId): JsonResponse
    {
        $property = Property::findOrFail($propertyId);
        $amenity = $property->amenities()->create($request->validate($this->rules($property)));

        return ApiResponse::success($amenity->refresh(), 201);
    }

    public function show(string $propertyId, string $amenityId): JsonResponse
    {
        return ApiResponse::success($this->findAmenity($propertyId, $amenityId)->loadCount('rooms'));
    }

    public function update(Request $request, string $propertyId, string $amenityId): JsonResponse
    {
        $property = Property::findOrFail($propertyId);
        $amenity = $this->findAmenity($propertyId, $amenityId);
        $amenity->update($request->validate($this->rules($property, $amenity)));

        return ApiResponse::success($amenity->loadCount('rooms'));
    }

    public function destroy(string $propertyId, string $amenityId): Response
    {
        $amenity = $this->findAmenity($propertyId, $amenityId);
        $amenity->rooms()->detach();
        $amenity->delete();

        return response()->noContent();
    }

    private function findAmenity(string $propertyId, string $amenityId): Amenity
    {
        return Amenity::query()
            ->where('property_id', $propertyId)
            ->findOrFail($amenityId);
    }

    private function rules(Property $property, ?Amenity $amenity = null): array
    {
        $presence = $amenity === null ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'string', 'max:255'],
            'code' => [
                $presence,
                'string',
                'max:50',
                Rule::unique('amenities', 'code')
                    ->where(fn ($query) => $query->where('property_id', $property->id))
                    ->ignore($amenity?->id),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
