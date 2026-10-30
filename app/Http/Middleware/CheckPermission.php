<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request, validating module action permissions.
     */
    public function handle(Request $request, Closure $next, string $module, string $action): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Super Administrator has unrestricted system-wide bypass
        $roleCode = strtoupper($user->role?->code ?? '');
        if ($roleCode === 'ADMIN' || (bool) ($user->role?->is_system ?? false)) {
            return $next($request);
        }

        if ($user->hasPermission($module, $action)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => "Forbidden: Staff member lacks required permission '{$module}.{$action}'.",
        ], 403);
    }
}
