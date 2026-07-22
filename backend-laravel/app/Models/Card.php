<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Card extends Model
{
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
