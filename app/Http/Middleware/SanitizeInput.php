<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SanitizeInput
{
    /**
     * Keys to exclude from HTML stripping (passwords, PINs, cryptographic signatures).
     *
     * @var array<int, string>
     */
    protected array $except = [
        'password',
        'password_confirmation',
        'pin',
        'pin_hash',
        'secret',
        'signature',
        'token',
        'webhook_secret',
    ];

    /**
     * Sanitize all incoming request inputs against Stored XSS and script injections.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $input = $request->all();

        if (! empty($input)) {
            $sanitized = $this->cleanArray($input);
            $request->merge($sanitized);
        }

        return $next($request);
    }

    /**
     * Recursively clean array inputs.
     */
    protected function cleanArray(array $data): array
    {
        $cleaned = [];

        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), $this->except, true)) {
                $cleaned[$key] = $value;
                continue;
            }

            if (is_array($value)) {
                $cleaned[$key] = $this->cleanArray($value);
            } elseif (is_string($value)) {
                $cleaned[$key] = $this->sanitizeString($value);
            } else {
                $cleaned[$key] = $value;
            }
        }

        return $cleaned;
    }

    /**
     * Disarm dangerous HTML and scripting vectors while preserving UTF-8 / Myanmar characters.
     */
    protected function sanitizeString(string $value): string
    {
        // 1. Strip all HTML/XML tags
        $stripped = strip_tags($value);

        // 2. Disarm inline JavaScript pseudo-protocol (e.g. javascript:...)
        $stripped = preg_replace('/javascript\s*:/i', '', $stripped);

        // 3. Disarm HTML event handler remnants (e.g. onerror=, onload=)
        $stripped = preg_replace('/on[a-z]+\s*=/i', '', $stripped);

        return trim($stripped);
    }
}
