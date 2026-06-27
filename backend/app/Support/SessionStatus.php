<?php

namespace App\Support;

/**
 * Canonical session/leave status vocabulary — single source of truth (NM-7 / ADR-0008 / ADR-0011).
 *
 * Before this class the leave/non-quota status sets were hardcoded as array literals in ≥5 files
 * (LearningRecord scope, StudentClassController, ClassSessionController, LearningRecordController,
 * ScheduleGuardService). A new status value (e.g. FR-005's 'excused') had to be edited in every copy.
 * This class owns the vocabulary; every consumer references it. Adding a status = one edit here.
 *
 * Ratcheted by scripts/arch-fitness-check.mjs FIT-5 (status-literal duplication).
 */
final class SessionStatus
{
    /** Leave-family: the class did not consume a quota unit and is excluded from pending-review. */
    public const LEAVE = ['leave', 'leave_adjusted', 'excused'];

    /** Statuses that do NOT consume a session quota unit (cancelled + leave-family). */
    public const NON_QUOTA = ['cancelled', 'leave', 'leave_adjusted', 'excused'];

    /** Statuses excluded from the active calendar/occurrence projection. */
    public const EXCLUDED_FROM_CALENDAR = ['leave', 'leave_adjusted', 'excused', 'rescheduled', 'cancelled'];
}
