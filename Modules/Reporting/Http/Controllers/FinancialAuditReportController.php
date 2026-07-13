<?php

namespace Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Support\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Reporting\Services\FinancialAuditReportService;

class FinancialAuditReportController extends Controller
{
    public function __construct(
        private readonly FinancialAuditReportService $reports,
        private readonly PropertyContext $propertyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'property_id' => ['sometimes', 'integer', 'min:1'],
            'source' => ['sometimes', 'string', Rule::in(FinancialAuditReportService::SOURCES)],
            'start_date' => ['sometimes', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return ApiResponse::paginated($this->reports->paginate(
            $this->propertyContext->id($request),
            $validated['source'] ?? null,
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
            $request->integer('per_page', 15),
        ));
    }
}
