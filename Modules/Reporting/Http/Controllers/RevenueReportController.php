<?php

namespace Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Support\PropertyContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Reporting\Models\RevenuePeriodClose;
use Modules\Reporting\Services\FinancialReportService;

class RevenueReportController extends Controller
{
    public function __construct(
        private readonly FinancialReportService $reports,
        private readonly PropertyContext $propertyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        [$start, $end, $currency] = $this->options($request);
        $startedAt = hrtime(true);
        $report = $this->reports->report(
            $this->propertyContext->id($request),
            $start,
            $end,
            $currency,
        );
        $durationMs = round((hrtime(true) - $startedAt) / 1_000_000, 2);

        return ApiResponse::success($report, 200, [
            'performance' => [
                'duration_ms' => $durationMs,
                'target_ms' => 500,
                'within_target' => $durationMs <= 500,
            ],
        ]);
    }

    public function close(Request $request): JsonResponse
    {
        [$start, $end, $currency] = $this->options($request);
        $result = $this->reports->closePeriod(
            $this->propertyContext->id($request),
            $request->user()->id,
            $start,
            $end,
            $currency,
        );

        return ApiResponse::success(
            $result['period_close'],
            $result['replayed'] ? 200 : 201,
            ['period_close' => ['replayed' => $result['replayed']]],
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $periodClose = RevenuePeriodClose::query()
            ->where('property_id', $this->propertyContext->id($request))
            ->findOrFail($id);

        return ApiResponse::success($periodClose);
    }

    private function options(Request $request): array
    {
        $validated = $request->validate([
            'property_id' => ['sometimes', 'integer', 'min:1'],
            'start_date' => ['sometimes', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'date_format:Y-m-d'],
            'currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
        ]);
        $start = isset($validated['start_date'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $validated['start_date'])->startOfDay()
            : CarbonImmutable::today();
        $end = isset($validated['end_date'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $validated['end_date'])->startOfDay()
            : $start;

        if ($end->lt($start)) {
            throw ValidationException::withMessages([
                'end_date' => ['The end date must be on or after the start date.'],
            ]);
        }

        if ($start->diffInDays($end) > 365) {
            throw ValidationException::withMessages([
                'end_date' => ['Revenue reports are limited to 366 days.'],
            ]);
        }

        return [$start, $end, strtoupper($validated['currency'] ?? 'USD')];
    }
}
