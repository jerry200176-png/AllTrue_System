<?php

namespace App\Support\ParentBinding;

use Illuminate\Support\Str;

/** PB-00 reason/outcome contract + PII-safe helpers. */
final class ParentBindingCodes
{
    public const OUTCOME_SUCCESS = 'success';
    public const OUTCOME_FAILURE = 'failure';
    public const OUTCOME_NOOP = 'noop';
    public const CHANNEL_LINE = 'line';
    public const CHANNEL_PORTAL = 'parent_portal';
    public const METHOD_NAME = 'name';
    public const METHOD_STUDENT_ID = 'student_id';
    public const METHOD_UNKNOWN = 'unknown';
    public const STUDENT_NOT_FOUND = 'STUDENT_NOT_FOUND';
    public const CONTACT_PHONE_MISSING = 'CONTACT_PHONE_MISSING';
    public const PHONE_MISMATCH = 'PHONE_MISMATCH';
    public const AMBIGUOUS_MATCH = 'AMBIGUOUS_MATCH';
    public const CAMPUS_MISMATCH = 'CAMPUS_MISMATCH';
    public const ALREADY_BOUND = 'ALREADY_BOUND';
    public const INVALID_INPUT = 'INVALID_INPUT';
    public const AUTHORIZATION_DENIED = 'AUTHORIZATION_DENIED';
    public const INTERNAL_ERROR = 'INTERNAL_ERROR';

    public static function pb00Reasons(): array
    {
        return [
            self::STUDENT_NOT_FOUND, self::CONTACT_PHONE_MISSING, self::PHONE_MISMATCH, self::AMBIGUOUS_MATCH,
            self::CAMPUS_MISMATCH, self::ALREADY_BOUND, self::INVALID_INPUT, self::AUTHORIZATION_DENIED, self::INTERNAL_ERROR,
        ];
    }

    public static function correlationId(?string $inbound = null): string
    {
        return is_string($inbound) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $inbound)
            ? strtolower($inbound) : (string) Str::uuid();
    }

    public static function phoneFingerprint(?string $phone): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $phone) ?? '';
        $key = (string) config('parent_binding.phone_fingerprint_key', '');
        return ($digits === '' || $key === '') ? null : hash_hmac('sha256', 'parent-binding-phone-v1|' . $digits, $key);
    }
}
