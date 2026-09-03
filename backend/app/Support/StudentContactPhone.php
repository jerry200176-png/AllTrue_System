<?php

namespace App\Support;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Services\ParentBinding\GuardianSyncService;

/**
 * Parent-facing contact phone: UI「家長手機」(parent_phone) first, then legacy Phone.
 * When multi_guardian_enabled, prefer primary StudentGuardian phone (dual-read) then
 * fall back to legacy columns so flag-off rollback stays correct.
 * Auth matching under cutover uses any active/read_only guardian phone.
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
        $normalizedInput = Guardian::normalizePhone($normalizedInput);
        if ($normalizedInput === '') {
            return false;
        }

        if (GuardianSyncService::enabled() && GuardianSyncService::dualWriteEnabled()) {
            $matched = StudentGuardian::activeAccess()
                ->where('student_id', (int) $student->getKey())
                ->whereHas('guardian', function ($q) use ($normalizedInput) {
                    $q->where('phone_normalized', $normalizedInput);
                })
                ->exists();
            if ($matched) {
                return true;
            }

            $activeWithPhone = StudentGuardian::activeAccess()
                ->where('student_id', (int) $student->getKey())
                ->whereHas('guardian', function ($q) {
                    $q->whereNotNull('phone_normalized')->where('phone_normalized', '!=', '');
                })
                ->exists();
            // Canonical: when any guardian phone exists, do not accept stale legacy
            // parent_phone that is not on an active/read_only guardian (revoke-proof).
            if ($activeWithPhone) {
                return false;
            }
        }

        $stored = self::normalizedDigits($student);
        if ($stored === '') {
            return false;
        }

        return $stored === $normalizedInput;
    }
}
