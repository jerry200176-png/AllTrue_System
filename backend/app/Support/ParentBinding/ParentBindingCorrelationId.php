<?php

namespace App\Support\ParentBinding;

use Illuminate\Support\Str;

/** UUID correlation id; reject untrusted inbound strings. */
final class ParentBindingCorrelationId
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public static function generate(): string
    {
        return (string) Str::uuid();
    }

    public static function fromRequest(?string $inbound): string
    {
        return is_string($inbound) && preg_match(self::UUID_RE, $inbound) === 1
            ? strtolower($inbound)
            : self::generate();
    }
}
