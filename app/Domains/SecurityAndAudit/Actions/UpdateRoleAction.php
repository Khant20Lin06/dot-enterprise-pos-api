<?php

namespace App\Domains\SecurityAndAudit\Actions;

use App\Domains\SecurityAndAudit\Models\AuditLog;
use App\Domains\SecurityAndAudit\Models\Role;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UpdateRoleAction
{
    /**
     * Update role details and permission matrix, invalidating permission caches.
     *
     * @param  array<string, mixed>  $data
     * @throws DomainException
     */
    public function execute(Role $role, array $data, ?int $operatorId = null): Role
    {
        return DB::transaction(function () use ($role, $data, $operatorId) {
            $prevDiff = [
                'name' => $role->name,
                'permissions' => $role->permissions,
            ];

            // Protect system roles from code mutation
            if ($role->is_system && isset($data['code']) && strtoupper($data['code']) !== $role->code) {
                throw new DomainException("System role code '{$role->code}' cannot be renamed.");
            }

            $updatePayload = [];

            if (isset($data['name'])) {
                $updatePayload['name'] = trim((string) $data['name']);
            }

            if (isset($data['badge_color'])) {
                $updatePayload['badge_color'] = $data['badge_color'];
            }

            if (array_key_exists('description', $data)) {
                $updatePayload['description'] = $data['description'];
            }

            if (isset($data['permissions'])) {
                $updatePayload['permissions'] = $data['permissions'];
            }

            $role->update($updatePayload);

            // Invalidate Redis / Application Permission Cache
            Cache::forget("role:perms:{$role->id}");

            AuditLog::create([
                'staff_id' => $operatorId,
                'module' => 'roles',
                'action' => 'role_updated',
                'severity' => 'warning',
                'resource_type' => 'roles',
                'resource_id' => (string) $role->id,
                'description' => "Role '{$role->name}' ({$role->code}) updated.",
                'diffs' => [
                    'before' => $prevDiff,
                    'after' => [
                        'name' => $role->name,
                        'permissions' => $role->permissions,
                    ],
                ],
                'created_at' => now(),
            ]);

            return $role->fresh();
        });
    }
}
