<?php

namespace App\Services;

class SensitiveDataMasker
{
    /**
     * Sensitive field patterns that must be redacted.
     *
     * @var array<int, string>
     */
    protected static array $sensitiveKeys = [
        'password',
        'password_confirmation',
        'pin',
        'pin_hash',
        'token',
        'secret',
        'webhook_secret',
        'signature',
        'api_key',
        'authorization',
        'card_number',
        'cvv',
    ];

    /**
     * Recursively mask sensitive fields in payload arrays.
     */
    public static function mask(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        $masked = [];

        foreach ($data as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if (in_array($normalizedKey, self::$sensitiveKeys, true)) {
                $masked[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $masked[$key] = self::mask($value);
            } else {
                $masked[$key] = $value;
            }
        }

        return $masked;
    }
}
