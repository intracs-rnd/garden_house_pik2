<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IuranPembayaran extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'iuran_pembayaran';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'iuran_perumahan_id',
        'no_kk',
        'paid_by_user_id',
        'jumlah_bayar',
        'metode_bayar',
        'bukti_bayar',
        'catatan',
        'paid_at',
        'nominal_transfer',
        'rekening_tujuan',
        // Approval fields
        'is_approved',
        'approved_by',
        'approved_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'jumlah_bayar'     => 'decimal:2',
        'nominal_transfer' => 'decimal:2',
        'paid_at'          => 'datetime',
        'is_approved'      => 'boolean',
        'approved_at'      => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Tagihan iuran yang terkait dengan pembayaran ini.
     */
    public function iuranPerumahan(): BelongsTo
    {
        return $this->belongsTo(IuranPerumahan::class, 'iuran_perumahan_id');
    }

    /**
     * Pengguna yang melakukan pembayaran.
     */
    public function paidByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }
}
