<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RFID card scan log emitted by the RFID gate readers.
 *
 * Table columns: id, gate_id, event_ts, created_at, uid, result, cctv_id.
 *   - gate_id  : identifier of the gate (e.g. GATE_IN_01, GATE_OUT_01)
 *   - event_ts : timestamp when the RFID scan happened
 *   - uid      : RFID UID, references cards.uid
 *   - result   : gate decision (e.g. ALLOW, DENY)
 *   - cctv_id  : references log_cctv.id (snapshot captured at scan)
 */
class LogRfidScan extends Model
{
    /**
     * log_rfid_scan lives on the replica server (192.168.214.163),
     * same as log_gate / log_cctv.
     *
     * @var string
     */
    protected $connection = 'pgsql_replica';

    /**
     * @var string
     */
    protected $table = 'log_rfid_scan';

    /**
     * The table has a created_at column but no updated_at; disable Eloquent
     * timestamp automation to avoid touching a non-existent column.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'gate_id',
        'event_ts',
        'created_at',
        'uid',
        'result',
        'cctv_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'event_ts'   => 'datetime',
        'created_at' => 'datetime',
        'cctv_id'    => 'integer',
    ];

    /**
     * Common result values.
     */
    public const RESULT_ALLOW = 'ALLOW';
    public const RESULT_DENY  = 'DENY';

    /**
     * Relation: the card that was scanned (matched via UID).
     *
     * Card lives on the pgsql_cards connection, but Eloquent will still use
     * the connection declared on the Card model when the relation is loaded.
     */
    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class, 'uid', 'uid');
    }

    /**
     * Relation: the CCTV snapshot associated with this scan.
     */
    public function cctv(): BelongsTo
    {
        return $this->belongsTo(LogCctv::class, 'cctv_id');
    }
}
