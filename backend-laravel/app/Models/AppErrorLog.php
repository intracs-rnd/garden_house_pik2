<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single application error / exception captured by the exception handler.
 *
 * Only `created_at` is tracked (records are never updated), so the default
 * updated_at handling is disabled.
 */
class AppErrorLog extends Model
{
    protected $table = 'app_error_logs';

    public const UPDATED_AT = null;

    protected $fillable = [
        'level',
        'type',
        'message',
        'status_code',
        'file',
        'line',
        'method',
        'url',
        'ip',
        'user_id',
        'user_name',
        'trace',
        'context',
    ];

    protected $casts = [
        'line'        => 'integer',
        'status_code' => 'integer',
        'context'     => 'array',
        'created_at'  => 'datetime',
    ];

    /**
     * User who was authenticated when the error occurred (if any).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
