<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LogGate;
use App\Models\GateManualControl;
use App\Services\ReportExcelExporter;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class GateController extends Controller
{
    protected ReportExcelExporter $excel;

    public function __construct(ReportExcelExporter $excel)
    {
        $this->excel = $excel;
    }

    /**
     * Log gate action (untuk MQTT integration - automatic RFID)
     */
    public function logGateAction(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'gate_id' => 'required|string|max:100',
            'open' => 'required|boolean',
            'event_ts' => 'sometimes|nullable|string',
        ]);

        // Store action as uppercase
        // TODO: uncomment CLOSE when ready
        $action = $validated['open'] ? 'OPEN' : 'CLOSE';
        // $action = 'OPEN'; // For now, only OPEN is allowed
        
        // Parse event_ts if provided, otherwise use now()
        $createdAt = now();
        $eventTs = $createdAt;
        
        if (!empty($validated['event_ts'])) {
            try {
                $eventTs = \Carbon\Carbon::parse($validated['event_ts']);
            } catch (\Exception $e) {
                $eventTs = $createdAt;
            }
        }

        try {
            $logGate = LogGate::create([
                'gate_id' => $validated['gate_id'],
                'event_ts' => $eventTs,
                'action' => $action,
                'result' => 'SUCCESS',
                'created_at' => $createdAt,
            ]);

            return response()->json([
                'message' => 'Gate action logged successfully',
                'data' => $logGate,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to log gate action',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Log manual gate control (ketika user klik Buka/Tutup gate dari dashboard)
     */
    public function logManualControl(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'gate_id'         => 'required|string|max:100',
            'nomor_plat'      => 'required|string|max:50',
            'action'          => 'required|in:OPEN,CLOSE',
            'view_image_path' => 'nullable|string',
            'entry_image_1'   => 'nullable|string',
            'entry_image_2'   => 'nullable|string',
            'entry_image_3'   => 'nullable|string',
            'entry_image_4'   => 'nullable|string',
        ]);

        try {
            $manualControl = GateManualControl::create([
                'gate_id'         => $validated['gate_id'],
                'nomor_plat'      => $validated['nomor_plat'],
                'action'          => $validated['action'],
                'result'          => 'SUCCESS',
                'user_id'         => auth()->id(),
                'user_name'       => auth()->user()?->name,
                'event_ts'        => now(),
                'view_image_path' => $validated['view_image_path'] ?? null,
                'entry_image_1'   => $validated['entry_image_1'] ?? null,
                'entry_image_2'   => $validated['entry_image_2'] ?? null,
                'entry_image_3'   => $validated['entry_image_3'] ?? null,
                'entry_image_4'   => $validated['entry_image_4'] ?? null,
            ]);

            return response()->json([
                'message' => 'Manual gate control logged successfully',
                'data'    => $manualControl,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to log manual gate control',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get gate logs by gate_id with pagination
     * Combine log_gate dan gate_manual_control
     */
    public function getLogsByGateId(string $gateId, Request $request): JsonResponse
    {
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);

        // Query dari log_gate (automatic RFID)
        $logGateQuery = LogGate::where('gate_id', $gateId)
            ->selectRaw("id, gate_id, event_ts, action, result, NULL as nomor_plat, 'auto' as control_type")
            ->where('gate_id', $gateId);

        // Query dari gate_manual_control (manual control)
        $manualControlQuery = GateManualControl::where('gate_id', $gateId)
            ->selectRaw("id, gate_id, event_ts, action, result, nomor_plat, 'manual' as control_type");

        // Combine dan sort by event_ts
        $logs = $logGateQuery
            ->union($manualControlQuery)
            ->orderBy('event_ts', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'gate_id' => $gateId,
            'logs' => $logs->items(),
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
                'has_more' => $logs->hasMorePages(),
            ],
        ]);
    }

    /**
     * GET /api/reports/gate-control
     * Queries gate_manual_control (manual gate events) filtered by period/date.
     * Image paths are stored directly on the row at the time of the action.
     */
    public function getLogsReport(Request $request): JsonResponse
    {
        $validated = $this->validateGateReportParams($request);
        $report    = $this->buildGateControlReport($validated);

        return $this->successResponse([
            'rows'    => $report['rows'],
            'summary' => $report['summary'],
        ], 'Laporan kontrol gate berhasil dimuat.');
    }

    /**
     * GET /api/reports/gate-control/pdf — gate control as PDF (inline / ?download=1).
     */
    public function gateControlPdf(Request $request)
    {
        $validated = $this->validateGateReportParams($request);
        $report    = $this->buildGateControlReport($validated);

        $pdf = Pdf::loadView('reports.gate-control', ['report' => $report])
            ->setPaper('a4', 'landscape');

        $filename = sprintf(
            'laporan-kontrol-gate-%s-%s.pdf',
            $report['period'],
            now()->format('Ymd_His')
        );

        return $request->boolean('download')
            ? $pdf->download($filename)
            : $pdf->stream($filename);
    }

    /**
     * GET /api/reports/gate-control/excel — gate control as Excel (.xlsx).
     */
    public function gateControlExcel(Request $request)
    {
        $validated = $this->validateGateReportParams($request);
        $report    = $this->buildGateControlReport($validated);

        $binary   = $this->excel->gateControl($report);
        $filename = sprintf(
            'laporan-kontrol-gate-%s-%s.xlsx',
            $report['period'],
            now()->format('Ymd_His')
        );

        return response($binary, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length'      => (string) strlen($binary),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Private helpers
    |--------------------------------------------------------------------------
    */

    private function validateGateReportParams(Request $request): array
    {
        return $request->validate([
            'period'   => ['nullable', Rule::in(['harian', 'bulanan', 'tahunan'])],
            'date'     => ['nullable', 'string', 'max:20'],
            'gate'     => ['nullable', 'string', 'max:100'],
            'download' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * Build the gate control report payload shared by JSON, PDF, and Excel endpoints.
     */
    private function buildGateControlReport(array $validated): array
    {
        $period = $validated['period'] ?? 'bulanan';
        [$from, $to] = $this->resolveDateRange($period, $validated['date'] ?? null);
        $gate = $validated['gate'] ?? null;

        $query = GateManualControl::whereBetween('event_ts', [$from, $to]);
        if ($gate) {
            $query->where('gate_id', 'ilike', "%{$gate}%");
        }
        $rawRows = $query->orderBy('event_ts', 'desc')->get();

        $total = $rawRows->count();
        $open  = $rawRows->filter(fn ($r) => strtoupper((string) $r->action) === 'OPEN')->count();
        $close = $rawRows->filter(fn ($r) => strtoupper((string) $r->action) === 'CLOSE')->count();

        $mappedRows = $rawRows->values()->map(function ($row, $i) {
            $ts = $row->event_ts instanceof Carbon
                ? $row->event_ts
                : ($row->event_ts ? Carbon::parse($row->event_ts) : null);

            $candidates = [
                $row->view_image_path,
                $row->entry_image_1,
                $row->entry_image_2,
                $row->entry_image_3,
                $row->entry_image_4,
            ];
            $imagePaths = array_values(array_slice(
                array_unique(array_filter($candidates, fn ($p) => (string) $p !== '')),
                0, 4
            ));

            return [
                'no'           => $i + 1,
                'event_ts'     => $ts ? $ts->format('d/m/Y H:i:s') : '—',
                'gate_id'      => $row->gate_id ?: '—',
                'action'       => $row->action ?: '—',
                'action_label' => strtoupper((string) $row->action) === 'OPEN' ? 'Buka' : 'Tutup',
                'result'       => $row->result ?: '—',
                'nomor_plat'   => $row->nomor_plat ?: '—',
                'user_name'    => $row->user_name ?: '—',
                'image_paths'  => $imagePaths,
            ];
        })->all();

        $periodLabels = ['harian' => 'Harian', 'bulanan' => 'Bulanan', 'tahunan' => 'Tahunan'];

        return [
            'rows'         => $mappedRows,
            'summary'      => compact('total', 'open', 'close'),
            'period'       => $period,
            'period_label' => $periodLabels[$period] ?? ucfirst($period),
            'range_label'  => $this->buildRangeLabel($period, $validated['date'] ?? null),
            'gate_filter'  => $gate,
            'generated_at' => Carbon::now()->locale('id')->isoFormat('D MMMM Y, HH:mm'),
        ];
    }

    /** Human-readable range label (e.g. "Juli 2026", "22 Juli 2026", "2026"). */
    private function buildRangeLabel(string $period, ?string $date): string
    {
        try {
            $anchor = match(true) {
                $period === 'tahunan' && $date && preg_match('/^\d{4}$/', $date)
                    => Carbon::createFromDate((int) $date, 1, 1),
                $period === 'bulanan' && $date && preg_match('/^\d{4}-\d{2}$/', $date)
                    => Carbon::createFromFormat('Y-m-d', $date . '-01'),
                $date !== null => Carbon::parse($date),
                default => Carbon::now(),
            };
        } catch (\Throwable) {
            $anchor = Carbon::now();
        }

        return match($period) {
            'harian'  => $anchor->locale('id')->isoFormat('dddd, D MMMM Y'),
            'tahunan' => $anchor->format('Y'),
            default   => $anchor->locale('id')->isoFormat('MMMM Y'),
        };
    }

    /**
     * Resolve [from, to] Carbon range from period + date string.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveDateRange(string $period, ?string $date): array
    {
        $now = Carbon::now();

        try {
            if (! $date) {
                $anchor = $now;
            } elseif ($period === 'tahunan' && preg_match('/^\d{4}$/', $date)) {
                $anchor = Carbon::createFromDate((int) $date, 1, 1)->startOfDay();
            } elseif ($period === 'bulanan' && preg_match('/^\d{4}-\d{2}$/', $date)) {
                $anchor = Carbon::createFromFormat('Y-m-d', $date . '-01')->startOfDay();
            } else {
                $anchor = Carbon::parse($date);
            }
        } catch (\Throwable $e) {
            $anchor = $now;
        }

        switch ($period) {
            case 'harian':
                return [$anchor->copy()->startOfDay(), $anchor->copy()->endOfDay()];
            case 'tahunan':
                return [$anchor->copy()->startOfYear(), $anchor->copy()->endOfYear()];
            default: // bulanan
                return [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth()];
        }
    }

    /**
     * Get all gate logs (latest first)
     */
    public function getAllLogs(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 100);
        $gateId = $request->query('gate_id');

        $query = LogGate::query();

        if ($gateId) {
            $query->where('gate_id', $gateId);
        }

        $logs = $query->orderBy('event_ts', 'desc')
            ->limit($limit)
            ->get();

        return response()->json([
            'total' => $logs->count(),
            'logs' => $logs,
        ]);
    }
}
