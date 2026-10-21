<?php

namespace App\Services;

/**
 * The one definition of 「進行中課程」 for 學生管理 (in-app #427).
 *
 * Input is one row of the student-classes index projection (StudentClassController::index), so the
 * remaining count is the self-healed one, package members read the shared pool, and "settled" is the
 * server's own payment_status. The duplicate-course prompt (StudentController::activeCourses) filters
 * with this rule; the student page's course list hides the same rows client-side
 * (frontend courseMoneyState.isStudentPageActiveCourse). Both sides are pinned to one fixture:
 * tests/Fixtures/active_course_parity.json.
 *
 * Display only: it never decides what is owed or changes a billing state.
 */
final class EnrollmentActiveCourseRule
{
    /** payment_status values that mean nothing is left to collect (frontend SETTLED_PAYMENT_STATUSES). */
    private const SETTLED_PAYMENT_STATUSES = ['paid', 'free'];

    /** @param array<string, mixed> $row */
    public static function isActive(array $row): bool
    {
        // Stopped (paused or closed) courses are never 進行中.
        if (($row['status'] ?? 'active') !== 'active') {
            return false;
        }
        // Any recorded closure (settled, settled_pending, waived, completed, converted_trial, ...) ends the contract.
        if ((string) ($row['closed_reason'] ?? '') !== '') {
            return false;
        }
        // Monthly courses do not count down; package members draw from the shared pool.
        if (($row['payment_type'] ?? '') === 'monthly' || self::isPackageMember($row)) {
            return true;
        }
        $remaining = $row['remaining_sessions'] ?? null;
        if (!is_numeric($remaining)) {
            return true;
        }

        // Used up and fully paid = finished; used up but still owed stays visible.
        return !((int) $remaining <= 0
            && in_array(strtolower((string) ($row['payment_status'] ?? '')), self::SETTLED_PAYMENT_STATUSES, true));
    }

    /**
     * The remaining count the course card shows: the shared pool for package members, else the course's own.
     *
     * @param array<string, mixed> $row
     */
    public static function remainingSessions(array $row): int
    {
        if (self::isPackageMember($row) && is_numeric($row['package_remaining_sessions'] ?? null)) {
            return (int) $row['package_remaining_sessions'];
        }

        return (int) ($row['remaining_sessions'] ?? 0);
    }

    /** @param array<string, mixed> $row */
    private static function isPackageMember(array $row): bool
    {
        return (int) ($row['PackageID'] ?? $row['package_id'] ?? 0) > 0;
    }
}
