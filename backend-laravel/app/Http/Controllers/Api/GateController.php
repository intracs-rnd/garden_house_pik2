<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LogGate;
use App\Models\GateManualControl;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class GateController extends Controller
{
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
            'gate_id' => 'required|string|max:100',
            'nomor_plat' => 'required|string|max:50',
            'action' => 'required|in:OPEN,CLOSE',
        ]);

        try {
            $manualControl = GateManualControl::create([
                'gate_id' => $validated['gate_id'],
                'nomor_plat' => $validated['nomor_plat'],
                'action' => $validated['action'],
                'result' => 'SUCCESS',
                'user_id' => auth()->id(),
                'user_name' => auth()->user()?->name,
                'event_ts' => now(),
            ]);

            return response()->json([
                'message' => 'Manual gate control logged successfully',
                'data' => $manualControl,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to log manual gate control',
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
