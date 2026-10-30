<?php

namespace App\Domains\SecurityAndAudit\Actions;

use App\Domains\SecurityAndAudit\Models\AuditLog;
use App\Domains\SecurityAndAudit\Models\Role;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DeleteRoleAction
{
    /**
     * Delete custom role safely, verifying no active staff members are assigned.
     *
     * @throws DomainException
     */
    public function execute(Role $role, ?int $operatorId = null): void
    {
        DB::transaction(function () use ($role, $operatorId) {
            if ($role->is_system) {
                throw new DomainException("System role '{$role->name}' ({$role->code}) is protected and cannot be deleted.");
            }

            $assignedCount = $role->staffUsers()->count();
            if ($assignedCount > 0) {
                throw new DomainException(
                    "Cannot delete role '{$role->name}' because {$assignedCount} staff member(s) are actively assigned to it. Reassign staff before deleting."
                );
            }

            Cache::forget("role:perms:{$role->id}");

            $roleId = $role->id;
            $roleName = $role->name;
            $roleCode = $role->code;

            $role->delete();

            AuditLog::create([
                'staff_id' => $operatorId,
                'module' => 'roles',
                'action' => 'role_deleted',
                'severity' => 'critical',
                'resource_type' => 'roles',
                'resource_id' => (string) $roleId,
                'description' => "Role '{$roleName}' ({$roleCode}) permanently deleted.",
                'diffs' => [
                    'id' => $roleId,
                    'code' => $roleCode,
                    'name' => $roleName,
                ],
                'created_at' => now(),
            ]);
        });
    }
}
