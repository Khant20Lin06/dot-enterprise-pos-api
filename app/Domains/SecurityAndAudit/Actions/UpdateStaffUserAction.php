<?php

namespace App\Domains\SecurityAndAudit\Actions;

use App\Domains\SecurityAndAudit\Models\AuditLog;
use App\Domains\SecurityAndAudit\Models\Role;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UpdateStaffUserAction
{
    /**
     * Update staff profile, credentials, role or workstation.
     *
     * @param  array<string, mixed>  $data
     * @throws DomainException
     */
    public function execute(StaffUser $staff, array $data, ?int $operatorId = null): StaffUser
    {
        return DB::transaction(function () use ($staff, $data, $operatorId) {
            $prevDiff = [
                'name' => $staff->name,
                'email' => $staff->email,
                'role_id' => $staff->role_id,
                'status' => $staff->status,
                'workstation' => $staff->workstation,
            ];

            $updatePayload = [];

            if (isset($data['name'])) {
                $updatePayload['name'] = trim((string) $data['name']);
            }

            if (isset($data['email'])) {
                $email = strtolower(trim((string) $data['email']));
                if ($email !== $staff->email && StaffUser::where('email', $email)->where('id', '!=', $staff->id)->exists()) {
                    throw new DomainException("Email '{$email}' is already taken by another staff user.");
                }
                $updatePayload['email'] = $email;
            }

            if (isset($data['phone'])) {
                $updatePayload['phone'] = $data['phone'];
            }

            if (isset($data['role_id'])) {
                $roleId = (int) $data['role_id'];
                if (! Role::where('id', $roleId)->exists()) {
                    throw new DomainException("Designated role ID #{$roleId} does not exist.");
                }
                $updatePayload['role_id'] = $roleId;
            }

            if (isset($data['workstation'])) {
                $updatePayload['workstation'] = $data['workstation'];
            }

            if (isset($data['status'])) {
                $updatePayload['status'] = $data['status'];
            }

            if (isset($data['avatar'])) {
                $updatePayload['avatar'] = $data['avatar'];
            }

            if (! empty($data['pin'])) {
                $pin = trim((string) $data['pin']);
                if (! preg_match('/^\d{4,6}$/', $pin)) {
                    throw new DomainException('Quick unlock PIN must be 4 to 6 digits.');
                }
                $updatePayload['pin_hash'] = Hash::make($pin);
            }

            if (! empty($data['password'])) {
                $updatePayload['password'] = Hash::make($data['password']);
            }

            $staff->update($updatePayload);

            AuditLog::create([
                'staff_id' => $operatorId,
                'module' => 'roles',
                'action' => 'staff_updated',
                'severity' => 'warning',
                'resource_type' => 'staff_users',
                'resource_id' => (string) $staff->id,
                'description' => "Staff member '{$staff->name}' ({$staff->email}) updated.",
                'diffs' => [
                    'before' => $prevDiff,
                    'after' => [
                        'name' => $staff->name,
                        'email' => $staff->email,
                        'role_id' => $staff->role_id,
                        'status' => $staff->status,
                        'workstation' => $staff->workstation,
                    ],
                ],
                'created_at' => now(),
            ]);

            return $staff->fresh('role');
        });
    }
}
