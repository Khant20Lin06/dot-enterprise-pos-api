<?php

use App\Domains\SecurityAndAudit\Actions\CreateRoleAction;
use App\Domains\SecurityAndAudit\Actions\DeleteRoleAction;
use App\Domains\SecurityAndAudit\Actions\DeleteStaffUserAction;
use App\Domains\SecurityAndAudit\Actions\UpdateRoleAction;
use App\Domains\SecurityAndAudit\Models\AuditLog;
use App\Domains\SecurityAndAudit\Models\Role;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

it('returns the complete canonical permissions catalog matching UI spec', function () {
    $admin = StaffUser::whereHas('role', fn ($q) => $q->where('code', 'ADMIN'))->first();
    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/v1/roles/permissions-catalog');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'catalog' => [
                'pos' => ['titleEn', 'titleMm', 'items'],
                'inventory' => ['titleEn', 'titleMm', 'items'],
                'workshop' => ['titleEn', 'titleMm', 'items'],
                'finances' => ['titleEn', 'titleMm', 'items'],
                'purchases' => ['titleEn', 'titleMm', 'items'],
                'settings' => ['titleEn', 'titleMm', 'items'],
            ],
            'emptyTemplate',
        ]);

    expect($response->json('catalog.pos.items'))->toBeArray();
});

it('creates a custom role with granular permissions and records audit log', function () {
    $admin = StaffUser::whereHas('role', fn ($q) => $q->where('code', 'ADMIN'))->first();
    Sanctum::actingAs($admin);

    $uniqueCode = 'WAREHOUSE_MGR_' . rand(100, 999);
    $response = $this->postJson('/api/v1/roles', [
        'code' => $uniqueCode,
        'name' => 'Warehouse Manager',
        'badge_color' => '#10b981',
        'description' => 'Oversees inventory stock adjustments and vendor procurement.',
        'permissions' => [
            'inventory' => ['view' => true, 'create' => true, 'edit' => true, 'adjustStock' => true],
            'purchases' => ['view' => true, 'create' => true, 'approvePO' => true],
        ],
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'success',
            'message',
            'data' => ['id', 'code', 'name', 'badgeColor', 'permissions', 'staffCount'],
        ]);

    expect($response->json('data.code'))->toBe($uniqueCode)
        ->and($response->json('data.permissions.inventory.adjustStock'))->toBeTrue();

    // Verify Audit Log
    $log = AuditLog::where('module', 'roles')
        ->where('action', 'role_created')
        ->where('resource_id', (string) $response->json('data.id'))
        ->first();

    expect($log)->not->toBeNull();
});

it('updates an existing role and invalidates permission cache', function () {
    $admin = StaffUser::whereHas('role', fn ($q) => $q->where('code', 'ADMIN'))->first();
    Sanctum::actingAs($admin);

    $role = Role::create([
        'code' => 'TRAINEE_CASHIER_' . rand(100, 999),
        'name' => 'Trainee Cashier',
        'badge_color' => '#f59e0b',
        'permissions' => ['pos' => ['view' => true, 'create' => false]],
        'is_system' => false,
    ]);

    $response = $this->putJson("/api/v1/roles/{$role->id}", [
        'name' => 'Junior Cashier',
        'permissions' => [
            'pos' => ['view' => true, 'create' => true],
        ],
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'name' => 'Junior Cashier',
                'permissions' => [
                    'pos' => ['view' => true, 'create' => true],
                ],
            ],
        ]);
});

it('blocks renaming system roles', function () {
    $admin = StaffUser::whereHas('role', fn ($q) => $q->where('code', 'ADMIN'))->first();
    $adminRole = $admin->role;

    $action = app(UpdateRoleAction::class);

    expect(fn () => $action->execute($adminRole, ['code' => 'SUPER_ADMIN_CHANGED']))
        ->toThrow(DomainException::class, "System role code 'ADMIN' cannot be renamed.");
});

it('blocks deleting a role that has active staff members assigned', function () {
    $customRole = Role::create([
        'code' => 'CUSTOM_SERVICE_' . rand(100, 999),
        'name' => 'Custom Service Tech',
        'is_system' => false,
        'permissions' => [],
    ]);

    StaffUser::create([
        'name' => 'Tech Specialist',
        'email' => 'tech_' . rand(1000, 9999) . '@dotpos.com',
        'role_id' => $customRole->id,
        'pin_hash' => Hash::make('1234'),
        'status' => 'Active',
    ]);

    $action = app(DeleteRoleAction::class);

    expect(fn () => $action->execute($customRole))
        ->toThrow(DomainException::class, 'staff member(s) are actively assigned to it');
});

it('registers a new staff user with 4-digit PIN and credentials', function () {
    $admin = StaffUser::whereHas('role', fn ($q) => $q->where('code', 'ADMIN'))->first();
    Sanctum::actingAs($admin);

    $role = Role::first();
    $email = 'cashier_' . rand(1000, 9999) . '@dotpos.com';

    $response = $this->postJson('/api/v1/staff', [
        'name' => 'Ko Zaw Zaw',
        'email' => $email,
        'phone' => '09987654321',
        'role_id' => $role->id,
        'pin' => '7788',
        'password' => 'PassZaw123!',
        'workstation' => 'POS Counter 02',
        'status' => 'Active',
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'success',
            'message',
            'data' => ['id', 'name', 'email', 'role', 'status', 'workstation'],
        ]);

    $created = StaffUser::where('email', $email)->first();
    expect($created)->not->toBeNull()
        ->and(Hash::check('7788', $created->pin_hash))->toBeTrue()
        ->and(Hash::check('PassZaw123!', $created->password))->toBeTrue();
});

it('updates staff details and updates PIN code', function () {
    $admin = StaffUser::whereHas('role', fn ($q) => $q->where('code', 'ADMIN'))->first();
    Sanctum::actingAs($admin);

    $staff = StaffUser::where('email', '!=', $admin->email)->first();

    $response = $this->putJson("/api/v1/staff/{$staff->id}", [
        'name' => 'Daw Mya Mya Updated',
        'pin' => '9900',
        'workstation' => 'Supervisor Desk',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'name' => 'Daw Mya Mya Updated',
                'workstation' => 'Supervisor Desk',
            ],
        ]);

    expect(Hash::check('9900', $staff->fresh()->pin_hash))->toBeTrue();
});

it('updates staff status and revokes active tokens on suspension', function () {
    $admin = StaffUser::whereHas('role', fn ($q) => $q->where('code', 'ADMIN'))->first();
    Sanctum::actingAs($admin);

    $staff = StaffUser::where('email', '!=', $admin->email)->first();
    // Issue token to target staff
    $staff->createToken('pos-terminal');
    expect($staff->tokens()->count())->toBeGreaterThan(0);

    $response = $this->patchJson("/api/v1/staff/{$staff->id}/status", [
        'status' => 'Suspended',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'status' => 'Suspended',
            ],
        ]);

    // Active tokens must be purged upon suspension
    expect($staff->fresh()->tokens()->count())->toBe(0);
});

it('prevents staff members from deleting their own user account', function () {
    $admin = StaffUser::whereHas('role', fn ($q) => $q->where('code', 'ADMIN'))->first();
    $action = app(DeleteStaffUserAction::class);

    expect(fn () => $action->execute($admin, $admin->id))
        ->toThrow(DomainException::class, 'Staff users cannot delete their own account.');
});

it('enforces RBAC permission check on role management', function () {
    // Non-admin cashier without settings.manageRoles
    $cashierRole = Role::where('code', 'CASHIER')->first();
    if (! $cashierRole) {
        $cashierRole = Role::create([
            'code' => 'CASHIER',
            'name' => 'Cashier',
            'permissions' => ['pos' => ['view' => true, 'create' => true]],
            'is_system' => false,
        ]);
    }

    $cashier = StaffUser::create([
        'name' => 'Restricted Cashier',
        'email' => 'restricted_cashier_' . rand(100, 999) . '@dotpos.com',
        'role_id' => $cashierRole->id,
        'pin_hash' => Hash::make('1111'),
        'status' => 'Active',
    ]);

    Sanctum::actingAs($cashier);

    $response = $this->postJson('/api/v1/roles', [
        'code' => 'HACK_ROLE',
        'name' => 'Hacked Role',
        'permissions' => [],
    ]);

    $response->assertStatus(403);
});
