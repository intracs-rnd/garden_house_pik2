<?php

namespace App\Models;

use App\Support\ReadWriteSplit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Card extends Model
{
    use ReadWriteSplit;

    /**
     * Koneksi database yang digunakan model ini.
     *
     * READ/WRITE split (identik dengan Kartu):
     *   READ  → 192.168.214.161  (PC Admin – replica, SELECT otomatis)
     *   WRITE → 192.168.214.163  (Virtual IP – master, DML otomatis)
     *
     * Tabel `cards` adalah mirror dari `kartus` yang diisi via auto-sync
     * (Kartu::booted) dan dikonsumsi oleh perangkat gate (RFID reader).
     *
     * @var string
     */
    protected $connection = 'pgsql';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'cards';

    /**
     * Cards table has no created_at / updated_at columns.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'uid',
        'name',
        'unit',
        'status',
        'expiry',
        'grace_days',
        'kartus_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'expiry' => 'date',
        'grace_days' => 'integer',
    ];

    /**
     * Relationship: Card belongs to Kartu (access card).
     * 
     * Menghubungkan card dengan kartus melalui foreign key kartus_id.
     * uid di cards juga bisa disamakan dengan rfid_tag di kartus.
     */
    public function kartu(): BelongsTo
    {
        return $this->belongsTo(Kartu::class, 'kartus_id');
    }
}
