<?php

namespace Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Support\PropertyContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Reporting\Services\OperationalReportService;

class OperationalReportController extends Controller
{
    public function __construct(
        private readonly OperationalReportService $reports,
        private readonly PropertyContext $propertyContext,
    ) {}

    public function arrivals(Request $request): JsonResponse|Response
    {
        $startedAt = hrtime(true);
        [$date, $format] = $this->dateOptions($request);
        $propertyId = $this->propertyContext->id($request);

        return $this->respond(
            'arrivals',
            OperationalReportService::STAY_COLUMNS,
            $this->reports->arrivals($propertyId, $date),
            ['date' => $date->toDateString()],
            $format,
            $startedAt,
            $propertyId,
        );
    }

    public function departures(Request $request): JsonResponse|Response
    {
        $startedAt = hrtime(true);
        [$date, $format] = $this->dateOptions($request);
        $propertyId = $this->propertyContext->id($request);

        return $this->respond(
            'departures',
            OperationalReportService::STAY_COLUMNS,
            $this->reports->departures($propertyId, $date),
            ['date' => $date->toDateString()],
            $format,
            $startedAt,
            $propertyId,
        );
    }

    public function inHouse(Request $request): JsonResponse|Response
    {
        $startedAt = hrtime(true);
        $validated = $request->validate([
            'property_id' => ['sometimes', 'integer', 'min:1'],
            'format' => ['sometimes', 'string', 'in:json,csv'],
        ]);
        $asOf = CarbonImmutable::now();
        $propertyId = $this->propertyContext->id($request);

        return $this->respond(
            'in-house',
            OperationalReportService::STAY_COLUMNS,
            $this->reports->inHouse($propertyId, $asOf),
            ['as_of' => $asOf->toIso8601String()],
            $validated['format'] ?? 'json',
            $startedAt,
            $propertyId,
        );
    }

    public function occupancy(Request $request): JsonResponse|Response
    {
        $startedAt = hrtime(true);
        [$date, $format] = $this->dateOptions($request);
        $propertyId = $this->propertyContext->id($request);

        return $this->respond(
            'occupancy',
            OperationalReportService::OCCUPANCY_COLUMNS,
            $this->reports->occupancy($propertyId, $date),
            ['date' => $date->toDateString()],
            $format,
            $startedAt,
            $propertyId,
        );
    }

    private function dateOptions(Request $request): array
    {
        $validated = $request->validate([
            'property_id' => ['sometimes', 'integer', 'min:1'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'format' => ['sometimes', 'string', 'in:json,csv'],
        ]);

        return [
            isset($validated['date'])
                ? CarbonImmutable::createFromFormat('Y-m-d', $validated['date'])->startOfDay()
                : CarbonImmutable::today(),
            $validated['format'] ?? 'json',
        ];
    }

    private function respond(
        string $report,
        array $columns,
        array $rows,
        array $scope,
        string $format,
        int $startedAt,
        int $propertyId,
    ): JsonResponse|Response {
        $durationMs = round((hrtime(true) - $startedAt) / 1_000_000, 2);

        if ($format === 'csv') {
            $scopeDate = $scope['date'] ?? substr($scope['as_of'], 0, 10);

            return response($this->csv($columns, $rows), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$report}-property-{$propertyId}-{$scopeDate}.csv\"",
                'X-Report-Row-Count' => (string) count($rows),
                'Server-Timing' => "report;dur={$durationMs}",
            ]);
        }

        return ApiResponse::success($report === 'occupancy' ? $rows[0] : $rows, 200, [
            'report' => array_merge([
                'name' => $report,
                'property_id' => $propertyId,
                'format' => 'json',
                'columns' => $columns,
                'row_count' => count($rows),
                'generated_at' => CarbonImmutable::now()->toIso8601String(),
            ], $scope),
            'performance' => [
                'duration_ms' => $durationMs,
                'target_ms' => 250,
                'within_target' => $durationMs <= 250,
            ],
        ]);
    }

    private function csv(array $columns, array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $columns, escape: '');

        foreach ($rows as $row) {
            fputcsv($stream, array_map($this->csvValue(...), array_values($row)), escape: '');
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    private function csvValue(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^[=+\-@]/', $value) === 1) {
            return "'{$value}";
        }

        return $value;
    }
}
