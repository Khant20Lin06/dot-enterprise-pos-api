<?php

namespace App\Domains\SecurityAndAudit\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

class StaffUser extends Authenticatable
{
    use HasApiTokens;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'role_id',
        'pin_hash',
        'password',
        'status',
        'workstation',
        'avatar',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'pin_hash',
    ];

    protected $casts = [
        'last_login_at' => 'datetime',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'staff_id');
    }

    public function hasPermission(string $module, string $action): bool
    {
        if (! $this->role_id) {
            return false;
        }

        $permissions = \Illuminate\Support\Facades\Cache::remember(
            "role:perms:{$this->role_id}",
            3600,
            fn () => $this->role?->permissions ?? []
        );

        return (bool) ($permissions[$module][$action] ?? false);
    }
}
