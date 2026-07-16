<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LogGate;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class GateController extends Controller
{
    /**
     * Log gate action (untuk MQTT integration)
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
     * Get gate logs by gate_id with pagination
     */
    public function getLogsByGateId(string $gateId, Request $request): JsonResponse
    {
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);

        $logs = LogGate::where('gate_id', $gateId)
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
