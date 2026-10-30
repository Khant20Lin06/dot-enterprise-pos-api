<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceSecurityHeaders
{
    /**
     * Enforce OWASP Recommended Security Response Headers on all API responses.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // 1. Prevent MIME-type Sniffing (OWASP ASVS 14.4.4)
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // 2. Prevent Clickjacking in iframes/webviews (OWASP ASVS 14.4.3)
        $response->headers->set('X-Frame-Options', 'DENY');

        // 3. Modern XSS Protection Header (Disables legacy buggy browser filters)
        $response->headers->set('X-XSS-Protection', '0');

        // 4. Strict Referrer Policy (Prevents leaking query parameters to third parties)
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // 5. Pure REST API Content-Security-Policy (Disallow execution of scripts & frames)
        $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; sandbox");

        // 6. Restrictive Permissions Policy (Disable unnecessary hardware APIs)
        $response->headers->set(
            'Permissions-Policy',
            'accelerometer=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), usb=()'
        );

        // 7. Sensitive Financial / PII Cache Control (OWASP ASVS 14.4.1)
        if ($request->is('api/*')) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private');
            $response->headers->set('Pragma', 'no-cache');
        }

        // 8. HTTP Strict Transport Security (HSTS) - Enforce HTTPS in production / TLS
        if ($request->isSecure() || app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        return $response;
    }
}
