<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\LogRfidConnRepository;
use Illuminate\Http\JsonResponse;

class LogRfidConnController extends Controller
{
    protected LogRfidConnRepository $logRfidConnRepository;

    public function __construct(LogRfidConnRepository $logRfidConnRepository)
    {
        $this->logRfidConnRepository = $logRfidConnRepository;
    }

    /**
     * Current RFID reader connection status, one entry per gate.
     */
    public function index(): JsonResponse
    {
        $gates = $this->logRfidConnRepository->latestPerGate();

        $data = [
            'gates'   => $gates,
            'summary' => [
                'total'   => $gates->count(),
                'online'  => $gates->where('is_online', true)->count(),
                'offline' => $gates->where('is_online', false)->count(),
            ],
        ];

        return $this->successResponse($data, 'RFID connection status retrieved successfully.');
    }

    /**
     * Connection history for a specific gate.
     */
    public function history(string $gateId): JsonResponse
    {
        $perPage = (int) request()->query('per_page', 20);
        $logs = $this->logRfidConnRepository->historyByGate($gateId, $perPage);

        return $this->paginatedResponse($logs, 'RFID connection history retrieved successfully.');
    }
}
