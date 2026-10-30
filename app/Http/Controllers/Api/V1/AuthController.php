<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\SecurityAndAudit\Models\AuditLog;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\LoginRequest;
use App\Http\Requests\V1\VerifyPinRequest;
use App\Http\Resources\V1\StaffUserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Authenticate staff user with email and password.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $email = strtolower(trim($request->email));
        $lockoutKey = "auth_lockout:{$email}";
        $failKey = "auth_fail:{$email}";

        // OWASP API2: Check progressive brute-force account lockout
        if (Cache::has($lockoutKey)) {
            $remaining = (int) Cache::get($lockoutKey) - now()->timestamp;
            return response()->json([
                'success' => false,
                'message' => 'Account temporarily locked due to excessive failed login attempts. Please try again later.',
                'error_code' => 'ACCOUNT_LOCKED',
                'retry_after' => max(1, $remaining),
            ], 429);
        }

        $staff = StaffUser::with('role')->where('email', $request->email)->first();

        if (! $staff || ! Hash::check($request->password, $staff->password)) {
            $attempts = (int) Cache::increment($failKey, 1);
            if ($attempts === 1) {
                Cache::put($failKey, 1, now()->addMinutes(15));
            }

            if ($attempts >= 5) {
                Cache::put($lockoutKey, now()->addMinutes(15)->timestamp, now()->addMinutes(15));

                AuditLog::create([
                    'staff_id' => $staff?->id,
                    'module' => 'security',
                    'action' => 'account_locked_brute_force',
                    'severity' => 'critical',
                    'resource_type' => 'staff_users',
                    'resource_id' => $staff?->id ?? 0,
                    'description' => "Account {$email} locked after 5 consecutive failed login attempts from IP {$request->ip()}",
                    'ip_address' => $request->ip(),
                    'created_at' => now(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Account temporarily locked due to 5 consecutive failed login attempts. Please try again after 15 minutes.',
                    'error_code' => 'ACCOUNT_LOCKED',
                    'retry_after' => 900,
                ], 429);
            }

            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password credentials.',
            ], 401);
        }

        // Authentication successful: reset failure and lockout counters
        Cache::forget($failKey);
        Cache::forget($lockoutKey);

        if (strtolower($staff->status) !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Your staff account is currently inactive. Contact your supervisor.',
            ], 403);
        }

        $workstation = $request->workstation ?? $staff->workstation ?? 'POS-Counter';
        $staff->update([
            'last_login_at' => now(),
            'workstation' => $workstation,
        ]);

        // Revoke stale login session tokens to prevent unbounded token accumulation
        $staff->tokens()->where('name', 'like', 'pos-token%')->delete();

        $token = $staff->createToken("pos-token-{$staff->id}", ['*'])->plainTextToken;

        AuditLog::create([
            'staff_id' => $staff->id,
            'module' => 'security',
            'action' => 'staff_login',
            'severity' => 'info',
            'resource_type' => 'staff_users',
            'resource_id' => $staff->id,
            'description' => "Staff {$staff->name} logged in at workstation {$workstation}",
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => new StaffUserResource($staff),
        ]);
    }

    /**
     * Rapid cashier terminal unlock and switch via 4-digit PIN (Hardened against CPU DoS & Brute-Force).
     */
    public function verifyPin(VerifyPinRequest $request): JsonResponse
    {
        $ip = $request->ip();
        $pinLockoutKey = "pin_lockout:{$ip}";
        $pinFailKey = "pin_fail:{$ip}";

        // OWASP API2: Check progressive brute-force terminal lockout
        if (Cache::has($pinLockoutKey)) {
            $remaining = (int) Cache::get($pinLockoutKey) - now()->timestamp;
            return response()->json([
                'success' => false,
                'message' => 'Terminal PIN unlock temporarily locked due to excessive failed attempts. Please try again later.',
                'error_code' => 'TERMINAL_LOCKED',
                'retry_after' => max(1, $remaining),
            ], 429);
        }

        $matchedStaff = null;

        // Optimized O(1) lookup if staff identifier is provided
        if ($request->filled('staff_id')) {
            $staff = StaffUser::with('role')->where('status', 'Active')->find($request->staff_id);
            if ($staff && $staff->pin_hash && Hash::check($request->pin, $staff->pin_hash)) {
                $matchedStaff = $staff;
            }
        } elseif ($request->filled('email')) {
            $staff = StaffUser::with('role')->where('status', 'Active')->where('email', $request->email)->first();
            if ($staff && $staff->pin_hash && Hash::check($request->pin, $staff->pin_hash)) {
                $matchedStaff = $staff;
            }
        } else {
            // Limited candidate pool of active staff members with configured PINs
            $candidates = StaffUser::with('role')->where('status', 'Active')->whereNotNull('pin_hash')->limit(50)->get();

            foreach ($candidates as $staff) {
                if ($staff->pin_hash && Hash::check($request->pin, $staff->pin_hash)) {
                    $matchedStaff = $staff;
                    break;
                }
            }
        }

        if (! $matchedStaff) {
            $attempts = (int) Cache::increment($pinFailKey, 1);
            if ($attempts === 1) {
                Cache::put($pinFailKey, 1, now()->addMinutes(15));
            }

            AuditLog::create([
                'staff_id' => $request->staff_id ?? null,
                'module' => 'security',
                'action' => 'pin_unlock_failed',
                'severity' => 'warning',
                'resource_type' => 'staff_users',
                'resource_id' => 0,
                'description' => "Failed PIN unlock attempt at workstation {$request->workstation} from IP {$request->ip()}",
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);

            if ($attempts >= 5) {
                Cache::put($pinLockoutKey, now()->addMinutes(15)->timestamp, now()->addMinutes(15));

                AuditLog::create([
                    'staff_id' => $request->staff_id ?? null,
                    'module' => 'security',
                    'action' => 'terminal_locked_brute_force',
                    'severity' => 'critical',
                    'resource_type' => 'pos_terminal',
                    'resource_id' => 0,
                    'description' => "Terminal locked after 5 consecutive failed PIN unlock attempts from IP {$request->ip()}",
                    'ip_address' => $request->ip(),
                    'created_at' => now(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Terminal PIN unlock temporarily locked due to 5 consecutive failed attempts. Please try again after 15 minutes.',
                    'error_code' => 'TERMINAL_LOCKED',
                    'retry_after' => 900,
                ], 429);
            }

            return response()->json([
                'success' => false,
                'message' => 'Invalid security PIN code.',
            ], 401);
        }

        // Terminal unlock successful: reset failure and lockout counters
        Cache::forget($pinFailKey);
        Cache::forget($pinLockoutKey);

        $workstation = $request->workstation ?? $matchedStaff->workstation ?? 'POS-Counter';
        $matchedStaff->update([
            'last_login_at' => now(),
            'workstation' => $workstation,
        ]);

        // MED-02 Hardening: Revoke stale PIN session tokens to prevent unbounded token accumulation
        $matchedStaff->tokens()->where('name', 'like', 'pin-session%')->delete();

        $token = $matchedStaff->createToken("pin-session-{$matchedStaff->id}")->plainTextToken;

        AuditLog::create([
            'staff_id' => $matchedStaff->id,
            'module' => 'security',
            'action' => 'pin_unlocked',
            'severity' => 'info',
            'resource_type' => 'staff_users',
            'resource_id' => $matchedStaff->id,
            'description' => "Cashier switch / terminal unlocked for {$matchedStaff->name} via PIN",
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => new StaffUserResource($matchedStaff),
        ]);
    }

    /**
     * Terminate active bearer token session.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            $user->currentAccessToken()->delete();

            AuditLog::create([
                'staff_id' => $user->id,
                'module' => 'security',
                'action' => 'staff_logout',
                'severity' => 'info',
                'resource_type' => 'staff_users',
                'resource_id' => $user->id,
                'description' => "Staff {$user->name} logged out.",
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Successfully logged out.',
        ]);
    }

    /**
     * Retrieve authenticated staff profile.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('role');

        return response()->json([
            'success' => true,
            'user' => new StaffUserResource($user),
        ]);
    }
}
