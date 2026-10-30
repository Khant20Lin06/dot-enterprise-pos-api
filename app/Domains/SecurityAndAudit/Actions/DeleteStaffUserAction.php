<?php

namespace App\Domains\SecurityAndAudit\Actions;

use App\Domains\SecurityAndAudit\Models\AuditLog;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use DomainException;
use Illuminate\Support\Facades\DB;

class DeleteStaffUserAction
{
    /**
     * Delete staff account, preventing self-deletion and purging session tokens.
     *
     * @throws DomainException
     */
    public function execute(StaffUser $staff, ?int $operatorId = null): void
    {
        DB::transaction(function () use ($staff, $operatorId) {
            if ($operatorId && $staff->id === $operatorId) {
                throw new DomainException('Security violation: Staff users cannot delete their own account.');
            }

            $staffId = $staff->id;
            $staffName = $staff->name;
            $staffEmail = $staff->email;

            // Purge active API tokens
            $staff->tokens()->delete();
            $staff->delete();

            AuditLog::create([
                'staff_id' => $operatorId,
                'module' => 'roles',
                'action' => 'staff_deleted',
                'severity' => 'critical',
                'resource_type' => 'staff_users',
                'resource_id' => (string) $staffId,
                'description' => "Staff user '{$staffName}' ({$staffEmail}) was permanently deleted.",
                'diffs' => [
                    'id' => $staffId,
                    'name' => $staffName,
                    'email' => $staffEmail,
                ],
                'created_at' => now(),
            ]);
        });
    }
}
