<?php

use App\Domains\Inventory\Models\Category;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Domains\SecurityAndAudit\Models\AuditLog;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use App\Services\SensitiveDataMasker;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

beforeEach(function () {
    Cache::flush();

    $this->adminStaff = StaffUser::whereHas('role', fn ($q) => $q->where('code', 'ADMIN'))->first();
    $this->category = Category::first() ?? Category::create(['code' => 'SPARE', 'name' => 'Spare Parts']);
    $this->uom = UnitOfMeasure::first() ?? UnitOfMeasure::create(['code' => 'PCS', 'name' => 'Piece', 'symbol' => 'pc']);
});

/*
|--------------------------------------------------------------------------
| Test 1: OWASP Security Response Headers Enforcement (ASVS 14.4 & API8)
|--------------------------------------------------------------------------
*/

it('enforces strict OWASP security response headers on all API responses', function () {
    $response = $this->getJson('/api/v1/categories');

    // OWASP ASVS 14.4.4: Prevent MIME-sniffing
    $response->assertHeader('X-Content-Type-Options', 'nosniff');

    // OWASP ASVS 14.4.3: Anti-Clickjacking Frame Option
    $response->assertHeader('X-Frame-Options', 'DENY');

    // Modern XSS Protection Header (Disables legacy buggy browser filters)
    $response->assertHeader('X-XSS-Protection', '0');

    // Strict Referrer Policy
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    // Content-Security-Policy pure REST API restriction
    expect($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'");

    // Restrictive Permissions-Policy
    expect($response->headers->get('Permissions-Policy'))->toContain('camera=()')
        ->and($response->headers->get('Permissions-Policy'))->toContain('microphone=()')
        ->and($response->headers->get('Permissions-Policy'))->toContain('geolocation=()');

    // OWASP ASVS 14.4.1: No-store cache headers for sensitive API endpoints
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache');
});

/*
|--------------------------------------------------------------------------
| Test 2: OWASP Stored XSS & Script Injection Neutralization (SanitizeInput)
|--------------------------------------------------------------------------
*/

it('neutralizes malicious stored XSS script tags from API request inputs', function () {
    Sanctum::actingAs($this->adminStaff);

    $xssName = "Premium Brake Fluid <script>alert('XSS-INJECT')</script>";
    $xssDescription = "<img src=x onerror=alert(document.cookie)> High performance brake fluid";

    $response = $this->postJson('/api/v1/categories', [
        'code' => 'XSS-CAT-' . uniqid(),
        'name' => $xssName,
        'description' => $xssDescription,
    ]);

    $response->assertCreated();

    // Verify script and event tags are stripped
    $createdCategory = Category::latest('id')->first();
    expect($createdCategory->name)->not->toContain('<script>')
        ->and($createdCategory->name)->not->toContain('</script>')
        ->and($createdCategory->description)->not->toContain('<img')
        ->and($createdCategory->description)->not->toContain('onerror=');
});

/*
|--------------------------------------------------------------------------
| Test 3: OWASP API2 - Progressive Brute-Force Password Lockout
|--------------------------------------------------------------------------
*/

it('locks out user accounts after 5 consecutive failed login attempts', function () {
    $targetEmail = 'cashier.test@dotpos.com';
    $password = 'Secret123!';

    $staff = StaffUser::create([
        'name' => 'Target Cashier',
        'email' => $targetEmail,
        'password' => Hash::make($password),
        'pin_hash' => Hash::make('4321'),
        'role_id' => $this->adminStaff->role_id,
        'status' => 'Active',
    ]);

    // Attempts 1 to 4 should return 401
    for ($i = 1; $i <= 4; $i++) {
        $res = $this->postJson('/api/v1/auth/login', [
            'email' => $targetEmail,
            'password' => 'WrongPassword999!',
        ]);
        $res->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    // 5th attempt must trigger 429 Account Lockout
    $lockoutRes = $this->postJson('/api/v1/auth/login', [
        'email' => $targetEmail,
        'password' => 'WrongPassword999!',
    ]);

    $lockoutRes->assertStatus(429)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error_code', 'ACCOUNT_LOCKED');

    // Verify Critical Audit Log was generated
    $auditLog = AuditLog::where('module', 'security')
        ->where('action', 'account_locked_brute_force')
        ->latest('id')
        ->first();

    expect($auditLog)->not->toBeNull();
    expect($auditLog->severity)->toBe('critical');

    // 6th attempt with correct password should STILL be blocked during lockout window
    $blockedValidRes = $this->postJson('/api/v1/auth/login', [
        'email' => $targetEmail,
        'password' => $password,
    ]);

    $blockedValidRes->assertStatus(429)
        ->assertJsonPath('error_code', 'ACCOUNT_LOCKED');
});

/*
|--------------------------------------------------------------------------
| Test 4: OWASP API2 - POS Terminal PIN Brute-Force Lockout
|--------------------------------------------------------------------------
*/

it('locks out terminal PIN verification after 5 consecutive failed attempts', function () {
    // 4 failed PIN attempts return 401
    for ($i = 1; $i <= 4; $i++) {
        $res = $this->postJson('/api/v1/auth/verify-pin', [
            'pin' => '0000',
            'workstation' => 'Terminal-Owasp',
        ]);
        $res->assertStatus(401);
    }

    // 5th failed attempt must trigger 429 Terminal Lockout
    $lockoutRes = $this->postJson('/api/v1/auth/verify-pin', [
        'pin' => '0000',
        'workstation' => 'Terminal-Owasp',
    ]);

    $lockoutRes->assertStatus(429)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error_code', 'TERMINAL_LOCKED');

    // Verify Critical Audit Log was generated
    $auditLog = AuditLog::where('module', 'security')
        ->where('action', 'terminal_locked_brute_force')
        ->latest('id')
        ->first();

    expect($auditLog)->not->toBeNull();
    expect($auditLog->severity)->toBe('critical');
});

/*
|--------------------------------------------------------------------------
| Test 5: OWASP API8 / CWE-209 - Standardized Sanitized Error Responses
|--------------------------------------------------------------------------
*/

it('returns standardized sanitized JSON error payloads without stack traces', function () {
    // 1. Unauthenticated request to protected endpoint -> 401 AUTH_REQUIRED
    $unauthRes = $this->getJson('/api/v1/products');
    $unauthRes->assertStatus(401)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error_code', 'AUTH_REQUIRED');

    // 2. Non-existent route -> 404 NOT_FOUND
    $notFoundRes = $this->getJson('/api/v1/completely-unknown-route-endpoint');
    $notFoundRes->assertStatus(404)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error_code', 'NOT_FOUND');

    // 3. Method Not Allowed -> 405 METHOD_NOT_ALLOWED
    $methodNotAllowedRes = $this->postJson('/api/v1/auth/me');
    $methodNotAllowedRes->assertStatus(405)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error_code', 'METHOD_NOT_ALLOWED');
});

/*
|--------------------------------------------------------------------------
| Test 6: OWASP API3 - Sensitive Data Redaction & Hidden Attributes
|--------------------------------------------------------------------------
*/

it('hides credentials and sensitive fields from model serialization and logging', function () {
    $staff = StaffUser::first();
    $staffArray = $staff->toArray();

    // Verify password, pin_hash, and remember_token are NEVER serialized into array/JSON
    expect($staffArray)->not->toHaveKey('password')
        ->and($staffArray)->not->toHaveKey('pin_hash')
        ->and($staffArray)->not->toHaveKey('remember_token');

    // Verify SensitiveDataMasker redacts confidential tokens and PINs
    $sensitivePayload = [
        'user' => 'admin',
        'password' => 'supersecret',
        'pin' => '4321',
        'card_number' => '4111111111111111',
        'token' => 'plain-text-token',
        'details' => [
            'webhook_secret' => 'whsec_12345',
            'amount' => 50000,
        ],
    ];

    $masked = SensitiveDataMasker::mask($sensitivePayload);

    expect($masked['password'])->toBe('***REDACTED***')
        ->and($masked['pin'])->toBe('***REDACTED***')
        ->and($masked['card_number'])->toBe('***REDACTED***')
        ->and($masked['token'])->toBe('***REDACTED***')
        ->and($masked['details']['webhook_secret'])->toBe('***REDACTED***')
        ->and($masked['details']['amount'])->toBe(50000);
});
