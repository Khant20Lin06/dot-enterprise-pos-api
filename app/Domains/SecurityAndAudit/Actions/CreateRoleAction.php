<?php

namespace App\Domains\SecurityAndAudit\Actions;

use App\Domains\SecurityAndAudit\Models\AuditLog;
use App\Domains\SecurityAndAudit\Models\Role;
use DomainException;
use Illuminate\Support\Facades\DB;

class CreateRoleAction
{
    /**
     * Create a new custom role with granular permissions.
     *
     * @param  array<string, mixed>  $data
     * @throws DomainException
     */
    public function execute(array $data, ?int $operatorId = null): Role
    {
        return DB::transaction(function () use ($data, $operatorId) {
            $code = strtoupper(trim((string) ($data['code'] ?? '')));

            if (empty($code)) {
                throw new DomainException('Role code cannot be empty.');
            }

            if (Role::where('code', $code)->exists()) {
                throw new DomainException("Role with code '{$code}' already exists.");
            }

            $role = Role::create([
                'code' => $code,
                'name' => trim((string) ($data['name'] ?? $code)),
                'badge_color' => $data['badge_color'] ?? '#0284c7',
                'description' => $data['description'] ?? null,
                'permissions' => $data['permissions'] ?? [],
                'is_system' => false,
            ]);

            AuditLog::create([
                'staff_id' => $operatorId,
                'module' => 'roles',
                'action' => 'role_created',
                'severity' => 'info',
                'resource_type' => 'roles',
                'resource_id' => (string) $role->id,
                'description' => "Role '{$role->name}' ({$role->code}) created with custom permissions.",
                'diffs' => [
                    'code' => $role->code,
                    'name' => $role->name,
                ],
                'created_at' => now(),
            ]);

            return $role;
        });
    }
}
