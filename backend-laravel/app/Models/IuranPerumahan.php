<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;
use App\Models\Kartu;
use App\Models\User;

class IuranPerumahan extends Model
{
    use HasFactory;

    /**
     * Status constants.
     */
    public const STATUS_BELUM_BAYAR = 'belum_bayar';
    public const STATUS_LUNAS       = 'lunas';
    public const STATUS_TERLAMBAT   = 'terlambat';

    /**
     * Human-readable label per status.
     *
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        self::STATUS_BELUM_BAYAR => 'Belum Bayar',
        self::STATUS_LUNAS       => 'Lunas',
        self::STATUS_TERLAMBAT   => 'Terlambat',
    ];

    /**
     * Badge variant per status (untuk frontend).
     *
     * @var array<string, string>
     */
    public const STATUS_VARIANTS = [
        self::STATUS_BELUM_BAYAR => 'warning',
        self::STATUS_LUNAS       => 'success',
        self::STATUS_TERLAMBAT   => 'danger',
    ];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'iuran_perumahan';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'no_kk',
        'periode',
        'jumlah',
        'deadline',
        'status',
        'keterangan',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'jumlah'   => 'decimal:2',
        'deadline' => 'date',
    ];

    /**
     * Attributes appended to array / JSON form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'status_label',
        'status_variant',
        'is_overdue',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Riwayat pembayaran untuk tagihan ini.
     */
    public function pembayaran(): HasMany
    {
        return $this->hasMany(IuranPembayaran::class, 'iuran_perumahan_id');
    }

    /**
     * Kartu akses yang terkait dengan KK ini (melalui users.no_kk).
     *
     * Gunakan hasManyThrough agar mudah mengambil semua kartu untuk KK tanpa join manual.
     */
    public function kartus(): HasManyThrough
    {
        return $this->hasManyThrough(
            Kartu::class,
            User::class,
            'no_kk',     // users.no_kk matches iuran_perumahan.no_kk
            'user_id',   // kartus.user_id matches users.id
            'no_kk',     // local key on this model
            'id'         // local key on users table
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Filter tagihan berdasarkan no_kk.
     */
    public function scopeByNoKk($query, string $noKk)
    {
        return $query->where('no_kk', $noKk);
    }

    /**
     * Filter berdasarkan status.
     */
    public function scopeByStatus($query, ?string $status)
    {
        return $status ? $query->where('status', $status) : $query;
    }

    /**
     * Filter berdasarkan periode.
     */
    public function scopeByPeriode($query, ?string $periode)
    {
        return $periode ? $query->where('periode', $periode) : $query;
    }

    /*
    |--------------------------------------------------------------------------
    | Business Logic
    |--------------------------------------------------------------------------
    */

    /**
     * Apakah tagihan ini sudah lunas.
     */
    public function isPaid(): bool
    {
        return $this->status === self::STATUS_LUNAS;
    }

    /**
     * Apakah tagihan ini sudah melewati deadline dan belum lunas.
     */
    public function isOverdue(): bool
    {
        if ($this->isPaid()) {
            return false;
        }

        return $this->deadline !== null && Carbon::today()->gt($this->deadline);
    }

    /**
     * Otomatis update status jadi terlambat jika sudah melewati deadline.
     * Dipanggil saat membaca data (accessor / observer dapat memanfaatkan ini).
     */
    public function syncStatus(): void
    {
        if (! $this->isPaid() && $this->isOverdue()) {
            $this->status = self::STATUS_TERLAMBAT;
            $this->saveQuietly();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Human-readable label untuk status.
     */
    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? 'Tidak Diketahui';
    }

    /**
     * Badge variant untuk status (success / warning / danger).
     */
    public function getStatusVariantAttribute(): string
    {
        return self::STATUS_VARIANTS[$this->status] ?? 'muted';
    }

    /**
     * Apakah tagihan sudah melewati deadline (untuk frontend).
     */
    public function getIsOverdueAttribute(): bool
    {
        return $this->isOverdue();
    }
}
