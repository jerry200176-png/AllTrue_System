<?php

namespace App\Support\ParentBinding;

/** PII-safe phone helpers — keyed HMAC fingerprint; never store raw phones. */
final class ParentBindingPhonePrivacy
{
    private const DOMAIN = 'parent-binding-phone-v1';

    public static function normalizeDigits(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone) ?? '';
    }

    public static function fingerprint(?string $phoneOrDigits): ?string
    {
        $digits = self::normalizeDigits((string) $phoneOrDigits);
        $key = (string) config('parent_binding.phone_fingerprint_key', '');
        if ($digits === '' || $key === '') {
            return null;
        }

        return hash_hmac('sha256', self::DOMAIN . '|' . $digits, $key);
    }

    public static function mask(?string $phoneOrDigits): ?string
    {
        $digits = self::normalizeDigits((string) $phoneOrDigits);
        if (strlen($digits) < 8) {
            return null;
        }

        return substr($digits, 0, 2) . str_repeat('*', max(0, strlen($digits) - 6)) . substr($digits, -4);
    }
}
