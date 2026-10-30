<?php

namespace App\Domains\SecurityAndAudit\Actions;

use App\Domains\SecurityAndAudit\Models\AuditLog;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use DomainException;
use Illuminate\Support\Facades\DB;

class UpdateStaffStatusAction
{
    /**
     * Update staff account status, revoking active session tokens if suspended.
     *
     * @throws DomainException
     */
    public function execute(StaffUser $staff, string $status, ?int $operatorId = null): StaffUser
    {
        return DB::transaction(function () use ($staff, $status, $operatorId) {
            $status = ucfirst(strtolower(trim($status)));
            $allowed = ['Active', 'Suspended', 'On Leave'];

            if (! in_array($status, $allowed)) {
                throw new DomainException("Invalid status '{$status}'. Allowed: " . implode(', ', $allowed));
            }

            $oldStatus = $staff->status;
            $staff->update(['status' => $status]);

            // If account suspended, immediately revoke all active Sanctum tokens for zero-trust termination
            if ($status === 'Suspended') {
                $staff->tokens()->delete();
            }

            AuditLog::create([
                'staff_id' => $operatorId,
                'module' => 'roles',
                'action' => 'staff_status_changed',
                'severity' => $status === 'Suspended' ? 'warning' : 'info',
                'resource_type' => 'staff_users',
                'resource_id' => (string) $staff->id,
                'description' => "Staff member '{$staff->name}' status transitioned from {$oldStatus} to {$status}.",
                'diffs' => [
                    'previous_status' => $oldStatus,
                    'new_status' => $status,
                ],
                'created_at' => now(),
            ]);

            return $staff->fresh('role');
        });
    }
}
