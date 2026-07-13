<?php

namespace Modules\Property\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Support\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Property\Models\Property;

class PropertyController extends Controller
{
    public function __construct(private readonly PropertyContext $propertyContext) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $properties = Property::paginate($request->integer('per_page', 15));

        return ApiResponse::paginated($properties);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:500'],
            'type' => ['required', 'string', 'in:hotel,villa,apartment,resort'],
            'description' => ['nullable', 'string'],
        ]);

        $property = Property::create($validated);

        return ApiResponse::success($property, 201);
    }

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->propertyContext->property($request));
    }

    public function update(Request $request): JsonResponse
    {
        $property = $this->propertyContext->property($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'address' => ['sometimes', 'string', 'max:500'],
            'type' => ['sometimes', 'string', 'in:hotel,villa,apartment,resort'],
            'description' => ['nullable', 'string'],
        ]);

        $property->update($validated);

        return ApiResponse::success($property);
    }

    public function destroy(Request $request): Response
    {
        $property = $this->propertyContext->property($request);
        $property->delete();

        return response()->noContent();
    }
}
