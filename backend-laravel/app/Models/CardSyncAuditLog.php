<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CardSyncAuditLog extends Model
{
    use HasFactory;

    protected $table = 'card_sync_audit_logs';

    protected $fillable = [
        'operation',
        'table_name',
        'record_id',
        'old_values',
        'new_values',
        'changed_columns',
        'source',
        'changed_by',
        'ip_address',
        'sync_status',
        'sync_error',
        'retry_count',
        'occurred_at',
        'replicated_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'changed_columns' => 'array',
        'occurred_at' => 'datetime',
        'replicated_at' => 'datetime',
    ];

    /**
     * Scope: Filter by table name
     */
    public function scopeForTable($query, $tableName)
    {
        return $query->where('table_name', $tableName);
    }

    /**
     * Scope: Filter by operation type
     */
    public function scopeByOperation($query, $operation)
    {
        return $query->where('operation', $operation);
    }

    /**
     * Scope: Filter by sync status
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('sync_status', $status);
    }

    /**
     * Scope: Get recent changes
     */
    public function scopeRecent($query, $minutes = 60)
    {
        return $query->where('occurred_at', '>=', now()->subMinutes($minutes));
    }

    /**
     * Get summary of changes
     */
    public static function getSummary($tableName = null, $minutes = 60)
    {
        $query = self::where('occurred_at', '>=', now()->subMinutes($minutes));

        if ($tableName) {
            $query->forTable($tableName);
        }

        return [
            'total' => $query->count(),
            'by_operation' => $query->groupBy('operation')
                ->selectRaw('operation, COUNT(*) as count')
                ->get()
                ->pluck('count', 'operation')
                ->toArray(),
            'sync_status' => $query->groupBy('sync_status')
                ->selectRaw('sync_status, COUNT(*) as count')
                ->get()
                ->pluck('count', 'sync_status')
                ->toArray(),
        ];
    }
}
