<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Services\LearningRecordResurrectionPolicy;
use App\Services\LearningRecordBackfillService;
use App\Support\AttendanceStatus;
use App\Support\SessionStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Shared logic for updating ClassSession.Status after an attendance event.
 *
 * Extracted from AttendanceController private methods so that both manual
 * attendance (AttendanceController) and RFID swipe (SwipeRfidController) go
 * through the same status-resolution rules.
 */
class AttendanceEffectsService
{
    private const LATE_GRACE_MINUTES = 15;

    /**
     * Resolve the ClassSession status string from a StudentSingIn status code.
     *
     * Used by manual attendance path.
     */
    public static function sessionStatusFromAttendanceStatus(string $attendanceStatus): string
    {
        // 相容別名：excused 早期併入 leave。
        if ($attendanceStatus === 'excused') {
            return 'leave';
        }
        // #765：其餘一律由 AttendanceStatus registry 決定（單一真相）。
        if (AttendanceStatus::exists($attendanceStatus)) {
            return AttendanceStatus::sessionStatus($attendanceStatus);
        }
        return 'attended';
    }

    /**
     * Resolve whether a swipe at $swipeAt on $session counts as on-time or late.
     *
     * Returns 'present' or 'late' (StudentSingIn.Status vocabulary).
     */
    public static function resolveSwipeStatus(ClassSession $session, Carbon $swipeAt): string
    {
        $startTime = Carbon::parse($session->SessionDate . ' ' . $session->StartTime);

        return $swipeAt->greaterThan($startTime->copy()->addMinutes(self::LATE_GRACE_MINUTES))
            ? 'late'
            : 'present';
    }

    /**
     * Update ClassSession.Status after an attendance event (swipe or manual).
     *
     * Guard: only updates when current status is 'scheduled'.
     * Rationale: never overwrite a human decision (attended/absent/leave/late).
     *
     * @param  ClassSession  $session
     * @param  string        $attendanceStatus  StudentSingIn.Status vocabulary ('present'|'late'|'absent'|…)
     */
    public static function applySessionStatus(ClassSession $session, string $attendanceStatus): void
    {
        // A leave/cancelled occurrence is not an attendance candidate.  This
        // guard must run before any caller creates a StudentSignIn so manual
        // attendance, RFID and presence-window paths cannot create a live
        // attendance row that hides/poisons the evaluation projection.
        self::assertCanRecordAttendance($session, $attendanceStatus);

        if ($session->Status !== 'scheduled') {
            return;
        }

        $newStatus = self::sessionStatusFromAttendanceStatus($attendanceStatus);
        $oldStatus = $session->Status;

        $session->Status = $newStatus;
        $session->save();

        LearningRecordResurrectionPolicy::restoreEligibleForSession($session);
        if (AttendanceStatus::requiresLog($attendanceStatus)) {
            app(LearningRecordBackfillService::class)->ensureRequiredForAttendanceSession($session);
        }

        Log::info('[attendance_effects] classsession_status_updated', [
            'class_session_id' => $session->id,
            'old_status'       => $oldStatus,
            'new_status'       => $newStatus,
            'attendance_status' => $attendanceStatus,
        ]);
    }

    /**
     * Fail closed when an attendance event tries to revive a non-attendance
     * occurrence. Leave recovery belongs to the dedicated leave-cascade undo
     * flow; it must never be achieved by writing a new attendance row.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertCanRecordAttendance(ClassSession $session, string $attendanceStatus): void
    {
        $currentStatus = strtolower(trim((string) ($session->Status ?? '')));
        if (SessionStatus::isLeaveLike($currentStatus) || $currentStatus === SessionStatus::CANCELLED) {
            throw new \InvalidArgumentException(
                '此堂目前是請假或已取消，請先用「取消請假／復原堂次」恢復為未上，再登記出席。'
            );
        }
    }
}
