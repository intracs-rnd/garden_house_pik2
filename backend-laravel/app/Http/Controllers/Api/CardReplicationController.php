<?php

namespace App\Http\Controllers\Api;

use App\Models\Card;
use App\Models\CardSyncAuditLog;
use App\Models\ReplicationStatus;
use App\Services\CardReplicationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CardReplicationController extends Controller
{
    private CardReplicationService $replicationService;

    public function __construct(CardReplicationService $replicationService)
    {
        $this->replicationService = $replicationService;
    }

    /**
     * GET /api/cards/replication/status
     * Cek status replication
     */
    public function getReplicationStatus(): JsonResponse
    {
        $status = $this->replicationService->getReplicationStatus();

        return response()->json([
            'success' => true,
            'data' => $status,
        ]);
    }

    /**
     * GET /api/cards/replication/changes
     * Get summary perubahan cards/kartus
     */
    public function getChangesSummary(Request $request): JsonResponse
    {
        $tableName = $request->query('table');
        $minutes = $request->query('minutes', 60);

        $summary = $this->replicationService->getChangesSummary($tableName, $minutes);

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }

    /**
     * GET /api/cards/replication/audit-logs
     * Get audit logs dengan filtering
     */
    public function getAuditLogs(Request $request): JsonResponse
    {
        $query = CardSyncAuditLog::query();

        if ($request->has('table')) {
            $query->forTable($request->query('table'));
        }

        if ($request->has('operation')) {
            $query->byOperation($request->query('operation'));
        }

        if ($request->has('status')) {
            $query->byStatus($request->query('status'));
        }

        if ($request->has('record_id')) {
            $query->where('record_id', $request->query('record_id'));
        }

        $logs = $query->orderBy('occurred_at', 'desc')
            ->paginate($request->query('per_page', 50));

        return response()->json([
            'success' => true,
            'data' => $logs,
        ]);
    }

    /**
     * GET /api/cards/replication/lag
     * Check replication lag
     */
    public function getReplicationLag(): JsonResponse
    {
        $lagSeconds = $this->replicationService->checkReplicationLag();

        return response()->json([
            'success' => true,
            'data' => [
                'lag_seconds' => $lagSeconds,
                'status' => match (true) {
                    $lagSeconds < 0 => 'error',
                    $lagSeconds === 0 => 'in_sync',
                    $lagSeconds < 5 => 'healthy',
                    $lagSeconds < 30 => 'lagging',
                    default => 'critical',
                },
            ],
        ]);
    }

    /**
     * POST /api/cards
     * Create card dengan automatic logging
     */
    public function createCard(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'uid' => 'required|string|unique:cards',
            'name' => 'required|string',
            'unit' => 'required|string',
            'status' => 'required|string',
            'expiry' => 'required|date',
            'grace_days' => 'nullable|integer',
            'kartus_id' => 'nullable|exists:kartus,id',
        ]);

        try {
            $card = $this->replicationService->createCard(
                $validated,
                auth()->user()?->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Card berhasil dibuat',
                'data' => $card,
            ], 201);
        } catch (\Exception $e) {
            Log::error("Failed to create card: {$e->getMessage()}");

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat card',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * PUT /api/cards/{id}
     * Update card dengan automatic logging
     */
    public function updateCard(Request $request, Card $card): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string',
            'unit' => 'sometimes|string',
            'status' => 'sometimes|string',
            'expiry' => 'sometimes|date',
            'grace_days' => 'nullable|integer',
        ]);

        try {
            $card = $this->replicationService->updateCard(
                $card,
                $validated,
                auth()->user()?->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Card berhasil diupdate',
                'data' => $card,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to update card: {$e->getMessage()}");

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengupdate card',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/cards/{id}
     * Delete card dengan automatic logging
     */
    public function deleteCard(Card $card): JsonResponse
    {
        try {
            $this->replicationService->deleteCard($card, auth()->user()?->id);

            return response()->json([
                'success' => true,
                'message' => 'Card berhasil dihapus',
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to delete card: {$e->getMessage()}");

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus card',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/cards/replication/retry-failed
     * Manual retry failed syncs
     */
    public function retryFailedSyncs(Request $request): JsonResponse
    {
        try {
            $limit = $request->query('limit', 10);
            $retried = $this->replicationService->retryFailedSyncs($limit);

            return response()->json([
                'success' => true,
                'message' => "Retry $retried failed syncs",
                'data' => ['retried_count' => $retried],
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to retry syncs: {$e->getMessage()}");

            return response()->json([
                'success' => false,
                'message' => 'Gagal retry syncs',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/cards/replication/refresh-status
     * Force refresh replication status dari database
     */
    public function refreshReplicationStatus(): JsonResponse
    {
        try {
            ReplicationStatus::updateFromDatabase();

            return response()->json([
                'success' => true,
                'message' => 'Replication status refreshed',
                'data' => $this->replicationService->getReplicationStatus(),
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to refresh replication status: {$e->getMessage()}");

            return response()->json([
                'success' => false,
                'message' => 'Gagal refresh status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
