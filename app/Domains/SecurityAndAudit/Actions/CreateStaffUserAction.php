<?php

namespace App\Domains\SecurityAndAudit\Actions;

use App\Domains\SecurityAndAudit\Models\AuditLog;
use App\Domains\SecurityAndAudit\Models\Role;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateStaffUserAction
{
    /**
     * Create a new staff user with secure PIN hash and credentials.
     *
     * @param  array<string, mixed>  $data
     * @throws DomainException
     */
    public function execute(array $data, ?int $operatorId = null): StaffUser
    {
        return DB::transaction(function () use ($data, $operatorId) {
            $email = strtolower(trim((string) $data['email']));

            if (StaffUser::where('email', $email)->exists()) {
                throw new DomainException("Staff user with email '{$email}' already exists.");
            }

            $roleId = (int) $data['role_id'];
            if (! Role::where('id', $roleId)->exists()) {
                throw new DomainException("Designated role ID #{$roleId} does not exist.");
            }

            $pin = trim((string) ($data['pin'] ?? '1234'));
            if (! preg_match('/^\d{4,6}$/', $pin)) {
                throw new DomainException('Quick unlock PIN must be 4 to 6 digits.');
            }

            $password = ! empty($data['password'])
                ? Hash::make($data['password'])
                : Hash::make($pin . '@DotPos');

            $staff = StaffUser::create([
                'name' => trim((string) $data['name']),
                'email' => $email,
                'phone' => $data['phone'] ?? null,
                'role_id' => $roleId,
                'pin_hash' => Hash::make($pin),
                'password' => $password,
                'status' => $data['status'] ?? 'Active',
                'workstation' => $data['workstation'] ?? 'POS Counter 01',
                'avatar' => $data['avatar'] ?? null,
            ]);

            AuditLog::create([
                'staff_id' => $operatorId,
                'module' => 'roles',
                'action' => 'staff_created',
                'severity' => 'info',
                'resource_type' => 'staff_users',
                'resource_id' => (string) $staff->id,
                'description' => "Staff member '{$staff->name}' ({$staff->email}) registered.",
                'diffs' => [
                    'name' => $staff->name,
                    'email' => $staff->email,
                    'role_id' => $roleId,
                    'status' => $staff->status,
                ],
                'created_at' => now(),
            ]);

            return $staff->load('role');
        });
    }
}
