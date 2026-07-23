<?php

namespace App\Services;

use App\Models\Card;
use App\Models\CardSyncAuditLog;
use App\Models\ReplicationStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class CardReplicationService
{
    /**
     * Log card operation untuk audit trail
     */
    public function logCardOperation(
        string $operation,
        string $tableName,
        int $recordId,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $source = null,
        ?string $changedBy = null,
        ?string $ipAddress = null
    ): CardSyncAuditLog {
        $changedColumns = [];
        
        if ($operation === 'UPDATE' && $oldValues && $newValues) {
            $changedColumns = array_keys(array_diff_assoc($newValues, $oldValues));
        }

        $auditLog = CardSyncAuditLog::create([
            'operation' => $operation,
            'table_name' => $tableName,
            'record_id' => $recordId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'changed_columns' => $changedColumns,
            'source' => $source ?? 'api',
            'changed_by' => $changedBy ?? auth()?->user()?->id,
            'ip_address' => $ipAddress ?? request()?->ip(),
            'sync_status' => 'synced', // Default synced karena logical replication handle-nya
            'occurred_at' => now(),
            'replicated_at' => now(), // Assuming real-time replication
        ]);

        Log::info("Card operation logged: {$operation} {$tableName} #{$recordId}");

        // Notify webhook jika dikonfigurasi
        $this->notifyWebhook($auditLog);

        return $auditLog;
    }

    /**
     * Create card dengan automatic logging
     */
    public function createCard(array $data, ?string $changedBy = null): Card
    {
        $card = Card::create($data);

        $this->logCardOperation(
            'INSERT',
            'cards',
            $card->id,
            null,
            $card->toArray(),
            'api',
            $changedBy
        );

        return $card;
    }

    /**
     * Update card dengan automatic logging
     */
    public function updateCard(Card $card, array $data, ?string $changedBy = null): Card
    {
        $oldValues = $card->toArray();
        $card->update($data);
        $newValues = $card->fresh()->toArray();

        $this->logCardOperation(
            'UPDATE',
            'cards',
            $card->id,
            $oldValues,
            $newValues,
            'api',
            $changedBy
        );

        return $card;
    }

    /**
     * Delete card dengan automatic logging
     */
    public function deleteCard(Card $card, ?string $changedBy = null): bool
    {
        $oldValues = $card->toArray();

        $result = $card->delete();

        if ($result) {
            $this->logCardOperation(
                'DELETE',
                'cards',
                $card->id,
                $oldValues,
                null,
                'api',
                $changedBy
            );
        }

        return $result;
    }

    /**
     * Notify webhook tentang card changes
     */
    private function notifyWebhook(CardSyncAuditLog $auditLog): void
    {
        $webhookUrl = config('replication.webhook.url');
        
        if (!$webhookUrl) {
            return;
        }

        try {
            Http::withHeaders([
                'X-Webhook-Secret' => config('replication.webhook.secret'),
            ])->post($webhookUrl, [
                'event' => 'card.sync',
                'operation' => $auditLog->operation,
                'table' => $auditLog->table_name,
                'record_id' => $auditLog->record_id,
                'old_values' => $auditLog->old_values,
                'new_values' => $auditLog->new_values,
                'changed_at' => $auditLog->occurred_at,
            ]);
        } catch (\Exception $e) {
            Log::error("Webhook notification failed: {$e->getMessage()}");
        }
    }

    /**
     * Get replication health status
     */
    public function getReplicationStatus(): array
    {
        $status = ReplicationStatus::current();

        if (!$status) {
            ReplicationStatus::updateFromDatabase();
            $status = ReplicationStatus::current();
        }

        return [
            'healthy' => $status->isHealthy(),
            'status' => $status->status,
            'is_connected' => $status->is_connected,
            'subscription' => $status->subscription_name,
            'lag_bytes' => $status->lag_bytes,
            'total_replicated' => $status->total_replicated,
            'failed_count' => $status->failed_count,
            'last_replicated_at' => $status->last_replicated_at,
            'error_message' => $status->error_message,
        ];
    }

    /**
     * Get card changes summary
     */
    public function getChangesSummary($tableName = null, $minutes = 60): array
    {
        return CardSyncAuditLog::getSummary($tableName, $minutes);
    }

    /**
     * Check replication lag.
     *
     * READ → 192.168.214.161 (replica)
     * pg_last_xact_replay_timestamp() hanya tersedia di replica (subscriber).
     * SELECT otomatis dikirim ke read host — eksplisit di sini untuk dokumentasi.
     */
    public function checkReplicationLag(): int
    {
        try {
            // Eksplisit gunakan koneksi pgsql — SELECT akan dikirim ke read host
            // (192.168.214.161 / replica) di mana pg_last_xact_replay_timestamp() aktif.
            $lag = DB::connection('pgsql')->selectOne(
                'SELECT 
                    EXTRACT(EPOCH FROM (now() - pg_last_xact_replay_timestamp()))::INTEGER as lag_seconds'
            );

            return $lag?->lag_seconds ?? 0;
        } catch (\Exception $e) {
            Log::error("Failed to check replication lag: {$e->getMessage()}");
            return -1;
        }
    }

    /**
     * Manual retry failed sync attempts
     */
    public function retryFailedSyncs(int $limit = 10): int
    {
        $failedLogs = CardSyncAuditLog::byStatus('failed')
            ->where('retry_count', '<', 3)
            ->orderBy('occurred_at')
            ->limit($limit)
            ->get();

        foreach ($failedLogs as $log) {
            try {
                // WRITE → 192.168.214.163 — retry harus ke master langsung.
                // useWritePdo() memastikan SELECT di updateOrInsert pun ke write host
                // sehingga kita membaca state terbaru sebelum INSERT/UPDATE.
                if ($log->operation === 'INSERT' || $log->operation === 'UPDATE') {
                    if ($log->new_values) {
                        DB::connection('pgsql')->table($log->table_name)
                            ->useWritePdo()
                            ->updateOrInsert(
                                ['id' => $log->record_id],
                                $log->new_values
                            );
                    }
                } elseif ($log->operation === 'DELETE') {
                    DB::connection('pgsql')->table($log->table_name)
                        ->where('id', $log->record_id)
                        ->delete();
                }

                $log->update([
                    'sync_status' => 'synced',
                    'sync_error' => null,
                    'retry_count' => $log->retry_count + 1,
                    'replicated_at' => now(),
                ]);

                Log::info("Retry sync successful for {$log->table_name} #{$log->record_id}");
            } catch (\Exception $e) {
                $log->increment('retry_count');
                $log->update(['sync_error' => $e->getMessage()]);

                Log::error("Retry sync failed for {$log->table_name} #{$log->record_id}: {$e->getMessage()}");
            }
        }

        return count($failedLogs);
    }
}
