<?php

namespace App\Services\Scheduling;

/**
 * RFC_NONSTANDARD_SESSION_DURATION_BILLING D2 — how a course converts an attended
 * session into consumed entitlement.
 *
 * `fixed_session` is the default and the behaviour every pre-existing course has:
 * one attended session consumes exactly one lesson, regardless of how long it
 * actually ran. `actual_duration` is strictly opt-in per course and consumes
 * minutes in proportion to that course's own `standard_lesson_minutes`.
 *
 * Single source of truth for these string values — model, validation, deduction
 * engine and preview contract all reference these constants so the set cannot
 * drift apart across layers.
 */
final class DeductionBasis
{
    public const FIXED_SESSION = 'fixed_session';
    public const ACTUAL_DURATION = 'actual_duration';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::FIXED_SESSION, self::ACTUAL_DURATION];
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::all(), true);
    }

    /** Comma-joined list for Laravel's `in:` validation rule. */
    public static function validationList(): string
    {
        return implode(',', self::all());
    }
}
