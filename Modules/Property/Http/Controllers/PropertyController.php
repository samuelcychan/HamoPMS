<?php

namespace Modules\Property\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Property\Models\Property;

class PropertyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $properties = Property::paginate(15);

        return response()->json($properties);
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

        return response()->json($property, 201);
    }

    public function show(string $id): JsonResponse
    {
        $property = Property::findOrFail($id);

        return response()->json($property);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $property = Property::findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'address' => ['sometimes', 'string', 'max:500'],
            'type' => ['sometimes', 'string', 'in:hotel,villa,apartment,resort'],
            'description' => ['nullable', 'string'],
        ]);

        $property->update($validated);

        return response()->json($property);
    }

    public function destroy(string $id): JsonResponse
    {
        $property = Property::findOrFail($id);
        $property->delete();

        return response()->json(null, 204);
    }
}
