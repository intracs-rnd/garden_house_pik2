<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoleFeaturePermission extends Model
{
    use HasFactory;

    /** Access level constants. */
    public const ACCESS_VIEW = 'view';
    public const ACCESS_MANAGE = 'manage';

    protected $fillable = [
        'role',
        'feature_id',
        'access',
    ];

    /**
     * The feature this permission belongs to.
     */
    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }
}
