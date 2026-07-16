<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogGate extends Model
{
    protected $table = 'log_gate';
    public $timestamps = false;

    protected $fillable = [
        'gate_id',
        'event_ts',
        'action',
        'result',
        'created_at',
    ];

    protected $casts = [
        'event_ts' => 'datetime',
        'created_at' => 'datetime',
    ];
}

