<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Connection / heartbeat log emitted by the RFID gate readers.
 *
 * Table columns: id, gate_id, event_ts, create_at, status, detail.
 * The table uses non-standard timestamp columns (event_ts / create_at) so the
 * default Eloquent created_at / updated_at handling is disabled.
 */
class LogRfidConn extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'log_rfid_conn';

    /**
     * This table does not use Laravel's default timestamp columns.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'gate_id',
        'event_ts',
        'create_at',
        'status',
        'detail',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'event_ts'  => 'datetime',
        'create_at' => 'datetime',
    ];

    /**
     * Computed attributes appended to array / JSON form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'is_online',
        'status_label',
    ];

    /**
     * Raw status values (case-insensitive) that are considered "online".
     *
     * @var array<int, string>
     */
    public const ONLINE_VALUES = ['1', 'connected', 'online', 'up', 'ok', 'true', 'active'];

    /**
     * Whether the reader is currently connected, derived from the raw status.
     */
    public function getIsOnlineAttribute(): bool
    {
        return in_array(strtolower(trim((string) $this->status)), self::ONLINE_VALUES, true);
    }

    /**
     * Human-readable status label.
     */
    public function getStatusLabelAttribute(): string
    {
        if ($this->status === null || $this->status === '') {
            return 'Tidak diketahui';
        }

        return $this->is_online ? 'Terhubung' : 'Terputus';
    }
}
