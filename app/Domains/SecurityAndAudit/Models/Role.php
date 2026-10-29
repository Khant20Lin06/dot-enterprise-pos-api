<?php

namespace App\Domains\SecurityAndAudit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = [
        'code',
        'name',
        'permissions',
        'badge_color',
        'description',
        'is_system',
    ];

    protected $casts = [
        'permissions' => 'array',
        'is_system' => 'boolean',
    ];

    public function staffUsers(): HasMany
    {
        return $this->hasMany(StaffUser::class, 'role_id');
    }
}
