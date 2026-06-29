<?php

namespace App\Support;

use App\Models\Student;

/**
 * Parent-facing contact phone: UI「家長手機」(parent_phone) first, then legacy Phone.
 * @see docs/AI_REGRESSION_LESSONS.md §R10
 */
final class StudentContactPhone
{
    public static function forStudent(Student $student): string
    {
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
