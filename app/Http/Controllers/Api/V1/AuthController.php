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
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Authenticate staff user with email and password.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $staff = StaffUser::with('role')->where('email', $request->email)->first();

        if (! $staff || ! Hash::check($request->password, $staff->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password credentials.',
            ], 401);
        }

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
     * Rapid cashier terminal unlock and switch via 4-digit PIN.
     */
    public function verifyPin(VerifyPinRequest $request): JsonResponse
    {
        $staffUsers = StaffUser::with('role')->where('status', 'Active')->get();

        $matchedStaff = null;
        foreach ($staffUsers as $staff) {
            if ($staff->pin_hash && Hash::check($request->pin, $staff->pin_hash)) {
                $matchedStaff = $staff;
                break;
            }
        }

        if (! $matchedStaff) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid security PIN code.',
            ], 401);
        }

        $workstation = $request->workstation ?? $matchedStaff->workstation ?? 'POS-Counter';
        $matchedStaff->update([
            'last_login_at' => now(),
            'workstation' => $workstation,
        ]);

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
