<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LogCctv extends Model
{
    use HasFactory;

    /**
     * The database connection for this model.
     * log_cctv lives on the server (192.168.214.163).
     *
     * @var string
     */
    protected $connection = 'pgsql_replica';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'log_cctv';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'cctv',
        'view_image_path',
        'log_time',
        'flags',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'log_time' => 'datetime',
    ];
}
