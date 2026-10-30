<?php

namespace App\Http\Middleware;

use App\Domains\SecurityAndAudit\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyPaymentGatewaySignature
{
    /**
     * Verify that incoming payment gateway webhook payload matches HMAC-SHA256 signature.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-Signature');
        $secret = config('services.payment_gateway.webhook_secret', 'dot_enterprise_webhook_secret_key_2026');

        if (! $signature) {
            AuditLog::create([
                'staff_id' => null,
                'module' => 'security',
                'action' => 'webhook_signature_missing',
                'severity' => 'critical',
                'resource_type' => 'payment_webhook',
                'resource_id' => null,
                'description' => "Rejected unsigned payment gateway webhook callback from IP {$request->ip()}",
                'diffs' => ['ip' => $request->ip(), 'path' => $request->path()],
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Missing cryptographic payment gateway signature (X-Signature).',
            ], 403);
        }

        $expectedSignature = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expectedSignature, $signature)) {
            AuditLog::create([
                'staff_id' => null,
                'module' => 'security',
                'action' => 'webhook_signature_forgery_blocked',
                'severity' => 'critical',
                'resource_type' => 'payment_webhook',
                'resource_id' => null,
                'description' => "Blocked forged payment gateway webhook callback with invalid signature from IP {$request->ip()}",
                'diffs' => [
                    'ip' => $request->ip(),
                    'provided_signature' => $signature,
                ],
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid cryptographic payment gateway signature.',
            ], 403);
        }

        return $next($request);
    }
}
