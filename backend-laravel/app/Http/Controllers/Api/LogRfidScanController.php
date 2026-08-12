<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LogRfidScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogRfidScanController extends Controller
{
    protected LogRfidScanService $service;

    public function __construct(LogRfidScanService $service)
    {
        $this->service = $service;
    }

    /**
     * Paginated list of RFID scan logs with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'gate_id',
            'uid',
            'result',
            'cctv_id',
            'date_from',
            'date_to',
            'search',
        ]);

        $perPage = (int) $request->query('per_page', 15);
        $logs    = $this->service->list($filters, $perPage);

        return $this->paginatedResponse($logs, 'RFID scan logs retrieved successfully.');
    }

    /**
     * Show a single RFID scan by id (with card + cctv snapshot).
     */
    public function show($id): JsonResponse
    {
        $scan = $this->service->find($id);

        return $this->successResponse($scan, 'RFID scan retrieved successfully.');
    }

    /**
     * Record a new RFID scan.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gate_id'    => ['required', 'string', 'max:64'],
            'uid'        => ['required', 'string', 'max:64'],
            'result'     => ['nullable', 'string', 'max:32'],
            'cctv_id'    => ['nullable', 'integer'],
            'event_ts'   => ['nullable', 'date'],
            'created_at' => ['nullable', 'date'],
        ]);

        $scan = $this->service->record($data);

        return $this->successResponse($scan, 'RFID scan recorded successfully.', 201);
    }

    /**
     * Scan history for a specific gate.
     */
    public function historyByGate(Request $request, string $gateId): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 20);
        $logs    = $this->service->historyByGate($gateId, $perPage);

        return $this->paginatedResponse($logs, 'RFID scan history for gate retrieved successfully.');
    }

    /**
     * Scan history for a specific card UID.
     */
    public function historyByUid(Request $request, string $uid): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 20);
        $logs    = $this->service->historyByUid($uid, $perPage);

        return $this->paginatedResponse($logs, 'RFID scan history for card retrieved successfully.');
    }

    /**
     * Latest scan per gate — one row per unique gate.
     */
    public function latestPerGate(): JsonResponse
    {
        $gates = $this->service->latestPerGate();

        return $this->successResponse([
            'gates' => $gates,
            'summary' => [
                'total'   => $gates->count(),
                'allowed' => $gates->where('result', 'ALLOW')->count(),
                'denied'  => $gates->where('result', 'DENY')->count(),
            ],
        ], 'Latest RFID scan per gate retrieved successfully.');
    }

    /**
     * Aggregate counters grouped by result value.
     */
    public function stats(Request $request): JsonResponse
    {
        $counts = $this->service->countsByResult(
            $request->query('date_from'),
            $request->query('date_to')
        );

        return $this->successResponse($counts, 'RFID scan statistics retrieved successfully.');
    }
}
