<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KartuAccessLog extends Model
{
    /**
     * Direction constants.
     */
    public const DIRECTION_IN  = 1;
    public const DIRECTION_OUT = 2;

    /**
     * Map of direction value => human-readable label.
     *
     * @var array<int, string>
     */
    public const DIRECTIONS = [
        self::DIRECTION_IN  => 'Tab In',
        self::DIRECTION_OUT => 'Tab Out',
    ];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'kartu_access_logs';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'kartu_id',
        'user_id',
        'card_number',
        'no_plat',
        'direction',
        'access_granted',
        'reason',
        'gate',
        'tapped_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'direction'      => 'integer',
        'access_granted' => 'boolean',
        'tapped_at'      => 'datetime',
    ];

    /**
     * Computed attributes appended to array / JSON form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'direction_label',
    ];

    /**
     * The card that was tapped.
     */
    public function kartu(): BelongsTo
    {
        return $this->belongsTo(Kartu::class);
    }

    /**
     * The card owner (snapshot at tap time).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to a given direction.
     */
    public function scopeDirection($query, ?int $direction)
    {
        return $direction ? $query->where('direction', $direction) : $query;
    }

    /**
     * Human-readable direction label.
     */
    public function getDirectionLabelAttribute(): string
    {
        return self::DIRECTIONS[(int) $this->direction] ?? 'Unknown';
    }

    /**
     * Prepare a date for array / JSON serialization.
     * Override to ensure timestamps are always serialized with timezone offset.
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d\TH:i:sP');
    }
}
