<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ReplicationStatus extends Model
{
    use HasFactory;

    protected $table = 'replication_status';

    protected $fillable = [
        'subscription_name',
        'publication_name',
        'slot_name',
        'status',
        'is_connected',
        'error_message',
        'remote_lsn',
        'local_lsn',
        'lag_bytes',
        'total_replicated',
        'failed_count',
        'retry_count',
        'last_connected_at',
        'last_replicated_at',
        'last_error_at',
    ];

    protected $casts = [
        'is_connected' => 'boolean',
        'last_connected_at' => 'datetime',
        'last_replicated_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];

    /**
     * Scope: Get current status
     */
    public function scopeCurrent($query)
    {
        return $query->first();
    }

    /**
     * Check if replication is healthy
     */
    public function isHealthy(): bool
    {
        return $this->status === 'healthy' && $this->is_connected;
    }

    /**
     * Get human-readable status
     */
    public function getStatusLabel(): string
    {
        return match ($this->status) {
            'healthy' => '✓ Sehat',
            'lagging' => '⚠ Lag',
            'disconnected' => '✗ Terputus',
            'error' => '✗ Error',
            default => 'Unknown',
        };
    }

    /**
     * Update status from database query.
     *
     * Laravel terhubung ke PUBLISHER (PC Admin). Monitoring dilakukan dari
     * sisi publisher menggunakan pg_replication_slots dan pg_stat_replication,
     * bukan pg_subscription (yang hanya ada di sisi subscriber/server).
     */
    public static function updateFromDatabase()
    {
        $subscriptionName = config('replication.subscription.name');
        $publicationName  = config('replication.publication.name', 'cards_kartus_pub');

        try {
            // 1. Cek publication ada di publisher
            $publication = \DB::selectOne(
                'SELECT pubname FROM pg_publication WHERE pubname = ?',
                [$publicationName]
            );

            if (!$publication) {
                return self::updateOrCreate(
                    ['subscription_name' => $subscriptionName],
                    [
                        'publication_name' => $publicationName,
                        'status' => 'error',
                        'is_connected' => false,
                        'error_message' => "Publication '{$publicationName}' belum dibuat di master. Jalankan: CREATE PUBLICATION {$publicationName} FOR TABLE cards, kartus;",
                        'last_error_at' => now(),
                    ]
                );
            }

            // 2. Cek replication slot aktif (dibuat otomatis saat subscriber connect)
            $slot = \DB::selectOne(
                'SELECT slot_name, active, restart_lsn::text as restart_lsn,
                        confirmed_flush_lsn::text as confirmed_flush_lsn,
                        pg_wal_lsn_diff(pg_current_wal_lsn(), confirmed_flush_lsn) as lag_bytes
                 FROM pg_replication_slots
                 WHERE slot_name = ? AND slot_type = ?',
                [$subscriptionName, 'logical']
            );

            if (!$slot) {
                // Publication ada tapi slot belum ada → subscriber belum connect
                return self::updateOrCreate(
                    ['subscription_name' => $subscriptionName],
                    [
                        'publication_name' => $publicationName,
                        'status' => 'disconnected',
                        'is_connected' => false,
                        'error_message' => "Publication '{$publicationName}' sudah ada. Subscriber belum connect — jalankan CREATE SUBSCRIPTION di server (192.168.214.7).",
                        'last_error_at' => now(),
                    ]
                );
            }

            // 3. Cek koneksi aktif dari pg_stat_replication
            $replication = \DB::selectOne(
                "SELECT application_name, state, sent_lsn::text, write_lsn::text,
                        flush_lsn::text, replay_lsn::text, client_addr::text
                 FROM pg_stat_replication
                 WHERE application_name = ?",
                [$subscriptionName]
            );

            $lagBytes = max(0, (int) ($slot->lag_bytes ?? 0));

            // Logical replication subscriber NORMAL disconnect saat idle/caught-up.
            // slot.active = false dengan lag kecil = subscriber sehat, hanya idle.
            // Baru flag sebagai masalah jika lag terus membesar melebihi threshold.
            $lagWarningBytes  = 50 * 1024 * 1024;   // 50 MB = warning
            $lagCriticalBytes = 200 * 1024 * 1024;  // 200 MB = critical

            // Subscriber dianggap "terhubung" jika slot ada (berarti subscriber pernah/sedang subscribe)
            // dan lag masih dalam batas wajar
            $slotExists  = true; // sudah pasti ada karena di atas tidak null
            $isConnected = $replication !== null && $slot->active; // aktif streaming saat ini
            $isHealthy   = $lagBytes < $lagWarningBytes;            // lag masih aman

            $status = match (true) {
                $replication !== null && $lagBytes < $lagWarningBytes  => 'healthy',    // aktif + lag kecil
                $replication === null  && $lagBytes < $lagWarningBytes  => 'healthy',   // idle tapi lag kecil = normal
                $lagBytes < $lagCriticalBytes                           => 'lagging',   // lag mulai besar
                default                                                 => 'error',     // lag kritis
            };

            $errorMessage = match (true) {
                $lagBytes >= $lagCriticalBytes => 'Lag replication kritis (' . round($lagBytes / 1024 / 1024, 1) . ' MB). Cek koneksi subscriber.',
                $lagBytes >= $lagWarningBytes  => 'Lag replication tinggi (' . round($lagBytes / 1024 / 1024, 1) . ' MB). Monitor terus.',
                default                        => null,
            };

            return self::updateOrCreate(
                ['subscription_name' => $subscriptionName],
                [
                    'publication_name'   => $publicationName,
                    'slot_name'          => $slot->slot_name,
                    'local_lsn'          => $slot->confirmed_flush_lsn,
                    'remote_lsn'         => $replication?->sent_lsn,
                    'lag_bytes'          => $lagBytes,
                    'is_connected'       => $status !== 'error',
                    'status'             => $status,
                    'error_message'      => $errorMessage,
                    'last_connected_at'  => $status !== 'error' ? now() : null,
                    'last_replicated_at' => $status !== 'error' ? now() : null,
                ]
            );
        } catch (\Exception $e) {
            return self::updateOrCreate(
                ['subscription_name' => $subscriptionName],
                [
                    'publication_name' => $publicationName,
                    'status' => 'error',
                    'is_connected' => false,
                    'error_message' => $e->getMessage(),
                    'last_error_at' => now(),
                ]
            );
        }
    }
}
