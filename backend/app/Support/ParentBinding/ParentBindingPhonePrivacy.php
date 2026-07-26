<?php

namespace App\Support\ParentBinding;

/**
 * PII-safe phone helpers for parent-binding observability.
 * Never log or store raw phones; fingerprint uses keyed HMAC + domain separation.
 */
final class ParentBindingPhonePrivacy
{
    private const DOMAIN = 'parent-binding-phone-v1';

    public static function normalizeDigits(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone) ?? '';
    }

    /**
     * Keyed HMAC fingerprint. Returns null when key missing or digits empty.
     */
    public static function fingerprint(?string $phoneOrDigits): ?string
    {
        $digits = self::normalizeDigits((string) $phoneOrDigits);
        if ($digits === '') {
            return null;
        }

        $key = (string) config('parent_binding.phone_fingerprint_key', '');
        if ($key === '') {
            return null;
        }

        return hash_hmac('sha256', self::DOMAIN . '|' . $digits, $key);
    }

    /**
     * Ops-facing mask only when explicitly needed. Default observability prefers no phone at all.
     * Example: 0912345678 → 09****5678
     */
    public static function mask(?string $phoneOrDigits): ?string
    {
        $digits = self::normalizeDigits((string) $phoneOrDigits);
        if (strlen($digits) < 8) {
            return null;
        }

        return substr($digits, 0, 2) . str_repeat('*', max(0, strlen($digits) - 6)) . substr($digits, -4);
    }

    /**
     * Negative assertion helper: does haystack contain a raw phone-like value?
     *
     * @param  list<string>  $rawValues
     */
    public static function containsRawPii(string $haystack, array $rawValues): bool
    {
        foreach ($rawValues as $raw) {
            if ($raw === '' || $raw === null) {
                continue;
            }
            if (str_contains($haystack, (string) $raw)) {
                return true;
            }
        }

        return false;
    }
}
