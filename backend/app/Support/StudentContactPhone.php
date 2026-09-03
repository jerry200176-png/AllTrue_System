<?php

namespace App\Support;

use App\Models\Student;
use App\Services\ParentBinding\GuardianSyncService;

/**
 * Parent-facing contact phone: UI「家長手機」(parent_phone) first, then legacy Phone.
 * When multi_guardian_enabled, prefer primary StudentGuardian phone (dual-read) then
 * fall back to legacy columns so cutover is reversible.
 * @see docs/AI_REGRESSION_LESSONS.md §R10
 */
final class StudentContactPhone
{
    public static function forStudent(Student $student): string
    {
        if (GuardianSyncService::enabled()) {
            $primary = app(GuardianSyncService::class)->primaryContactPhone($student);
            if ($primary !== null && $primary !== '') {
                return $primary;
            }
        }

        $parentPhone = trim((string) ($student->parent_phone ?? ''));
        if ($parentPhone !== '') {
            return $parentPhone;
        }

        return trim((string) ($student->Phone ?? ''));
    }

    public static function normalizedDigits(Student $student): string
    {
        return preg_replace('/[^0-9]/', '', self::forStudent($student)) ?? '';
    }

    public static function matchesNormalizedInput(Student $student, string $normalizedInput): bool
    {
        $stored = self::normalizedDigits($student);
        if ($stored === '' || $normalizedInput === '') {
            return false;
        }

        return $stored === $normalizedInput;
    }
}
