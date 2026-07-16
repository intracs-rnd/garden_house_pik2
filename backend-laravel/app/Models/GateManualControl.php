<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GateManualControl extends Model
{
    protected $table = 'gate_manual_control';

    protected $fillable = [
        'gate_id',
        'nomor_plat',
        'action',
        'result',
        'user_id',
        'user_name',
        'event_ts',
    ];

    protected $casts = [
        'event_ts' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
