<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasFactory;

    /**
     * The database connection for this model.
     * Transactions are written to and read from the server (192.168.214.163).
     *
     * @var string
     */
    protected $connection = 'pgsql_replica';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'transactions';

    /**
     * Indicates if the model should be timestamped.
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
        'plate_number',
        'entry_image_1',
        'entry_image_2',
        'entry_image_3',
        'entry_image_4',
        'exit_image_1',
        'exit_image_2',
        'exit_image_3',
        'exit_image_4',
        'is_receipt',
        'qr_code',
        'entry_time',
        'exit_time',
        'status',
        'notes',
        'location',
        'log_cctv_id',
        'log_anpr_id',
        'code_transaction',
        'flag',
        'user_id',
        'category',
        'exit_log_anpr_id',
        'exit_log_cctv_id',
        'category_exit',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'entry_time' => 'datetime',
        'exit_time' => 'datetime',
    ];

    /**
     * Status constants
     */
    const STATUS_ACTIVE = 'ACTIVE';
    const STATUS_COMPLETED = 'COMPLETED';

    /**
     * Get the user that owns the transaction.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the log_cctv record associated with this transaction.
     */
    public function logCctv(): BelongsTo
    {
        return $this->belongsTo(LogCctv::class, 'log_cctv_id');
    }

    public function logAnprRecord(): BelongsTo
    {
        return $this->belongsTo(LogAnpr::class, 'log_anpr_id');
    }

    public function logAnprCctvRecord(): BelongsTo
    {
        return $this->belongsTo(LogCctv::class, 'log_anpr_id');
    }

    public function exitLogCctv(): BelongsTo
    {
        return $this->belongsTo(LogCctv::class, 'exit_log_cctv_id');
    }

    public function exitLogAnprRecord(): BelongsTo
    {
        return $this->belongsTo(LogAnpr::class, 'exit_log_anpr_id');
    }

    public function exitLogAnprCctvRecord(): BelongsTo
    {
        return $this->belongsTo(LogCctv::class, 'exit_log_anpr_id');
    }

    public function getEntryAnprDataAttribute()
    {
        if ($this->category === 'request_capture') {
            return $this->logAnprCctvRecord;
        }
        return $this->logAnprRecord;
    }

    public function getExitAnprDataAttribute()
    {
        if ($this->category_exit === 'request_capture') {
            return $this->exitLogAnprCctvRecord;
        }
        return $this->exitLogAnprRecord;
    }

    /**
     * Scope a query to only include active transactions.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope a query to only include completed transactions.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope a query to filter by plate number.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $plateNumber
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByPlateNumber($query, $plateNumber)
    {
        return $query->where('plate_number', 'LIKE', '%' . $plateNumber . '%');
    }
}
