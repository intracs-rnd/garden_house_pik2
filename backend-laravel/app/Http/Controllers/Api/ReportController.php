<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportExcelExporter;
use App\Services\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Transaction (card access) reports: recap & detail, per day / month / year,
 * previewable as JSON and downloadable as PDF or Excel (.xlsx).
 */
class ReportController extends Controller
{
    protected ReportService $service;
    protected ReportExcelExporter $excel;

    public function __construct(ReportService $service, ReportExcelExporter $excel)
    {
        $this->service = $service;
        $this->excel   = $excel;
    }

    /**
     * GET /api/reports/recap — aggregated recap preview (JSON).
     */
    public function recap(Request $request): JsonResponse
    {
        $params = $this->validated($request);

        $report = $this->service->recap(
            $params['period'] ?? 'bulanan',
            $params['date'] ?? null,
            $this->filters($params)
        );

        return $this->successResponse($report, 'Rekap laporan transaksi berhasil dimuat.');
    }

    /**
     * GET /api/reports/detail — per-tap detail preview (JSON).
     */
    public function detail(Request $request): JsonResponse
    {
        $params = $this->validated($request);

        $report = $this->service->detail(
            $params['period'] ?? 'bulanan',
            $params['date'] ?? null,
            $this->filters($params)
        );

        return $this->successResponse($report, 'Detail laporan transaksi berhasil dimuat.');
    }

    /**
     * GET /api/reports/recap/pdf — recap as PDF (inline, or ?download=1).
     */
    public function recapPdf(Request $request)
    {
        $params = $this->validated($request);

        $report = $this->service->recap(
            $params['period'] ?? 'bulanan',
            $params['date'] ?? null,
            $this->filters($params)
        );

        $pdf = Pdf::loadView('reports.recap', ['report' => $report])
            ->setPaper('a4', 'portrait');

        return $this->respondPdf($request, $pdf, 'rekap', $report['period']);
    }

    /**
     * GET /api/reports/detail/pdf — detail as PDF (inline, or ?download=1).
     */
    public function detailPdf(Request $request)
    {
        $params = $this->validated($request);

        $report = $this->service->detail(
            $params['period'] ?? 'bulanan',
            $params['date'] ?? null,
            $this->filters($params)
        );

        $pdf = Pdf::loadView('reports.detail', ['report' => $report])
            ->setPaper('a4', 'landscape');

        return $this->respondPdf($request, $pdf, 'detail', $report['period']);
    }

    /**
     * GET /api/reports/recap/excel — recap as an Excel (.xlsx) workbook.
     */
    public function recapExcel(Request $request)
    {
        $params = $this->validated($request);

        $report = $this->service->recap(
            $params['period'] ?? 'bulanan',
            $params['date'] ?? null,
            $this->filters($params)
        );

        return $this->respondExcel($this->excel->recap($report), 'rekap', $report['period']);
    }

    /**
     * GET /api/reports/detail/excel — detail as an Excel (.xlsx) workbook.
     */
    public function detailExcel(Request $request)
    {
        $params = $this->validated($request);

        $report = $this->service->detail(
            $params['period'] ?? 'bulanan',
            $params['date'] ?? null,
            $this->filters($params)
        );

        return $this->respondExcel($this->excel->detail($report), 'detail', $report['period']);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    protected function validated(Request $request): array
    {
        return $request->validate([
            'period'         => ['nullable', Rule::in(ReportService::PERIODS)],
            'date'           => ['nullable', 'string', 'max:20'],
            'direction'      => ['nullable', 'integer', 'in:1,2'],
            'access_granted' => ['nullable', 'in:0,1,true,false'],
            'gate'           => ['nullable', 'string', 'max:50'],
            'no_plat'        => ['nullable', 'string', 'max:20'],
            'time_from'      => ['nullable', 'date_format:H:i'],
            'time_to'        => ['nullable', 'date_format:H:i'],
            'user_id'        => ['nullable', 'integer', 'exists:users,id'],
            'download'       => ['nullable', 'boolean'],
        ]);
    }

    /**
     * Extract only the data-filtering params (period/date drive the range).
     */
    protected function filters(array $params): array
    {
        return array_filter([
            'direction'      => $params['direction'] ?? null,
            'access_granted' => $params['access_granted'] ?? null,
            'gate'           => $params['gate'] ?? null,
            'no_plat'        => $params['no_plat'] ?? null,
            'time_from'      => $params['time_from'] ?? null,
            'time_to'        => $params['time_to'] ?? null,
            'user_id'        => $params['user_id'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Stream a PDF inline for preview, or force a download when ?download=1.
     */
    protected function respondPdf(Request $request, $pdf, string $kind, string $period)
    {
        $filename = sprintf('laporan-%s-%s-%s.pdf', $kind, $period, now()->format('Ymd_His'));

        return $request->boolean('download')
            ? $pdf->download($filename)
            : $pdf->stream($filename);
    }

    /**
     * Send a generated .xlsx binary as a file download.
     */
    protected function respondExcel(string $binary, string $kind, string $period)
    {
        $filename = sprintf('laporan-%s-%s-%s.xlsx', $kind, $period, now()->format('Ymd_His'));

        return response($binary, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length'      => (string) strlen($binary),
        ]);
    }
}
