<?php

namespace App\Support;

class Utf8mb3SearchSanitizer
{
    public static function forLike(?string $term): string
    {
        $raw = trim((string) ($term ?? ''));
        if ($raw === '') {
            return '';
        }

        // Remove 4-byte chars and common emoji join/variant markers.
        $sanitized = preg_replace('/[\x{10000}-\x{10FFFF}\x{200D}\x{FE0E}\x{FE0F}]/u', '', $raw);
        if (!is_string($sanitized)) {
            return $raw;
        }

        return trim($sanitized);
    }
}
