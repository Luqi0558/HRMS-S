<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function index(Request $request, string $type): JsonResponse
    {
        $report = $this->reports->generate($request->user(), $type, $this->filters($request));
        return response()->json($report + ['columns' => array_keys($report['rows'][0] ?? [])]);
    }

    public function csv(Request $request, string $type): StreamedResponse
    {
        $report = $this->reports->generate($request->user(), $type, $this->filters($request));
        $this->reports->recordExport($request->user(), $type, count($report['rows']));
        $columns = array_keys($report['rows'][0] ?? []);

        return response()->streamDownload(function () use ($columns, $report) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);
            foreach ($report['rows'] as $row) {
                fputcsv($out, array_map(fn ($column) => $this->csvValue($row[$column] ?? ''), $columns));
            }
            fclose($out);
        }, "{$type}-report.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'employee' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', 'string', 'max:60'],
            'dateFrom' => ['nullable', 'date'], 'dateTo' => ['nullable', 'date', 'after_or_equal:dateFrom'],
            'query' => ['nullable', 'string', 'max:120'],
        ]);
    }

    private function csvValue(mixed $value): string
    {
        $value = (string) $value;
        return preg_match('/^[=+\-@]/', $value) ? "'{$value}" : $value;
    }
}
