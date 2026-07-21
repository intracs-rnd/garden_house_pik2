<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\IuranPerumahan;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'type',
        'phone',
        'no_kk',
        'is_active',
        'outstanding_balance',
        'is_deleted',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at'   => 'datetime',
        'is_active'           => 'boolean',
        'outstanding_balance' => 'decimal:2',
        'is_deleted'          => 'boolean',
    ];

    /**
     * The "booted" method of the model.
     *
     * Registers a global scope so users flagged as deleted
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
     * Vehicles created / owned by the user.
     */
    public function kendaraans()
    {
        return $this->hasMany(Kendaraan::class, 'user_id');
    }

    /**
     * Access cards owned by the user.
     */
    public function kartus(): HasMany
    {
        return $this->hasMany(Kartu::class, 'user_id');
    }

    /**
     * Tab-in / tab-out access logs for the user.
     */
    public function accessLogs(): HasMany
    {
        return $this->hasMany(KartuAccessLog::class, 'user_id');
    }

    /**
     * Riwayat pembayaran iuran yang dilakukan oleh user ini.
     */
    public function iuranPembayaran(): HasMany
    {
        return $this->hasMany(IuranPembayaran::class, 'paid_by_user_id');
    }

    /**
     * Determine whether the user has unpaid dues (tunggakan).
     *
     * For gate access purposes we only consider an outstanding condition when
     * there are overdue invoices (status == 'terlambat'). Future invoices
     * (belum_bayar with a future deadline) should not block access. The
     * outstanding_balance field is retained for accounting but does not by
     * itself deny gate access.
     */
    public function hasOutstanding(): bool
    {
        if (empty($this->no_kk)) {
           return false;
        }

        return IuranPerumahan::where('no_kk', $this->no_kk)
           ->where('status', IuranPerumahan::STATUS_TERLAMBAT)
           ->exists();
    }

    /**
     * Scope a query to only active users.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Include soft-deleted users in the query.
     */
    public function scopeWithDeleted(Builder $query): Builder
    {
        return $query->withoutGlobalScope('notDeleted');
    }

    /**
     * Restrict the query to only soft-deleted users.
     */
    public function scopeOnlyDeleted(Builder $query): Builder
    {
        return $query->withoutGlobalScope('notDeleted')
            ->where($this->getTable() . '.is_deleted', true);
    }

    /**
     * Soft delete the user: flag it as deleted instead of removing it.
     */
    public function softDelete(): bool
    {
        $this->is_deleted = true;

        return $this->save();
    }

    /**
     * Restore a soft-deleted user.
     */
    public function restore(): bool
    {
        $this->is_deleted = false;

        return $this->save();
    }
}
