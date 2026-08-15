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

    /**
     * Apply a LIKE filter that utf8mb3 columns can execute.
     * 4-byte-only terms match nothing instead of throwing SQLSTATE 1267 (#1788).
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     */
    public static function applyLike($query, string $column, ?string $term): void
    {
        if (trim((string) ($term ?? '')) === '') {
            return;
        }

        $sanitized = self::forLike($term);
        if ($sanitized === '') {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where($column, 'like', '%' . $sanitized . '%');
    }
}
