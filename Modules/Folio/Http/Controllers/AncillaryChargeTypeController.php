<?php

namespace Modules\Folio\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Folio\Models\AncillaryChargeType;
use Modules\Property\Models\Property;

class AncillaryChargeTypeController extends Controller
{
    public function index(Request $request, string $propertyId): JsonResponse
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $property = Property::findOrFail($propertyId);

        return ApiResponse::paginated(
            $property->ancillaryChargeTypes()
                ->orderBy('name')
                ->paginate($request->integer('per_page', 15)),
        );
    }

    public function store(Request $request, string $propertyId): JsonResponse
    {
        $property = Property::findOrFail($propertyId);
        $chargeType = $property->ancillaryChargeTypes()->create(
            $request->validate($this->rules($property)),
        );

        return ApiResponse::success($chargeType->refresh(), 201);
    }

    public function update(Request $request, string $propertyId, string $chargeTypeId): JsonResponse
    {
        $property = Property::findOrFail($propertyId);
        $chargeType = $this->findChargeType($property, $chargeTypeId);
        $chargeType->update($request->validate($this->rules($property, $chargeType)));

        return ApiResponse::success($chargeType);
    }

    private function findChargeType(Property $property, string $chargeTypeId): AncillaryChargeType
    {
        return $property->ancillaryChargeTypes()->findOrFail($chargeTypeId);
    }

    private function rules(Property $property, ?AncillaryChargeType $chargeType = null): array
    {
        $presence = $chargeType === null ? 'required' : 'sometimes';

        return [
            'code' => [
                $presence,
                'string',
                'max:50',
                Rule::unique('ancillary_charge_types', 'code')
                    ->where(fn ($query) => $query->where('property_id', $property->id))
                    ->ignore($chargeType?->id),
            ],
            'name' => [$presence, 'string', 'max:255'],
            'tax_rate' => [$presence, 'numeric', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
