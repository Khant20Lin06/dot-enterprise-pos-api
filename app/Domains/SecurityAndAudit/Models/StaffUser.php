<?php

namespace App\Domains\SecurityAndAudit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

class StaffUser extends Model
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
        $permissions = $this->role?->permissions ?? [];
        return (bool) ($permissions[$module][$action] ?? false);
    }
}
