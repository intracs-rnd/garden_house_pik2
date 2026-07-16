<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Kendaraan extends Model
{
    use HasFactory;

    /**
     * Status constants (integer-based).
     */
    public const STATUS_AKTIF     = 1;
    public const STATUS_NONAKTIF  = 2;
    public const STATUS_BLACKLIST = 3;

    /**
     * Map of status value => human-readable label.
     *
     * @var array<int, string>
     */
    public const STATUSES = [
        self::STATUS_AKTIF     => 'Aktif',
        self::STATUS_NONAKTIF  => 'Non Aktif',
        self::STATUS_BLACKLIST => 'Blacklist',
    ];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'kendaraans';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'nama',
        'nomor_plat',
        'merk',
        'model',
        'tahun',
        'warna',
        'harga',
        'status',
        'keterangan',
        'is_deleted',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'tahun'      => 'integer',
        'harga'      => 'decimal:2',
        'is_deleted' => 'boolean',
    ];


    /**
     * The "booted" method of the model.
     *
     * Registers a global scope so vehicles flagged as deleted
     * (is_deleted = true) are hidden from every query by default,
     * emulating a soft delete without removing the row.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('notDeleted', function (Builder $builder) {
            $builder->where($builder->getModel()->getTable() . '.is_deleted', false);
        });
    }

    /**
     * The user who owns / registered the vehicle.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Include soft-deleted vehicles in the query.
     */
    public function scopeWithDeleted(Builder $query): Builder
    {
        return $query->withoutGlobalScope('notDeleted');
    }

    /**
     * Restrict the query to only soft-deleted vehicles.
     */
    public function scopeOnlyDeleted(Builder $query): Builder
    {
        return $query->withoutGlobalScope('notDeleted')
            ->where($this->getTable() . '.is_deleted', true);
    }

    /**
     * Soft delete the vehicle: flag it as deleted instead of removing it.
     */
    public function softDelete(): bool
    {
        $this->is_deleted = true;

        return $this->save();
    }

    /**
     * Restore a soft-deleted vehicle.
     */
    public function restore(): bool
    {
        $this->is_deleted = false;

        return $this->save();
    }
}
