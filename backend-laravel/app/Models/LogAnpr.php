<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LogAnpr extends Model
{
    use HasFactory;

    protected $connection = 'pgsql_replica';
    protected $table = 'log_anpr';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'device',
        'log_time',
        'plate',
        'vehicle_type',
        'confidence',
        'plate_image_path',
        'full_image_path',
        'created_at',
        'flags',
        'cctv_id'
    ];
}
