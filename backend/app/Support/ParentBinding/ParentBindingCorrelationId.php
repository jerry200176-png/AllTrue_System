<?php

namespace App\Support\ParentBinding;

use Illuminate\Support\Str;

/**
 * Correlation ID for a single bind/login attempt.
 * Never trust arbitrary inbound strings; UUID format only.
 */
final class ParentBindingCorrelationId
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public static function generate(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Accept inbound request id only when it is a well-formed UUID; else generate.
     */
    public static function fromRequest(?string $inbound): string
    {
        if (is_string($inbound) && preg_match(self::UUID_RE, $inbound) === 1) {
            return strtolower($inbound);
        }

        return self::generate();
    }
}
