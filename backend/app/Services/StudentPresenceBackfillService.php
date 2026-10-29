<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\Notification;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Support\SessionStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single implementation of the RFID "presence window" (#2809): a student who was
 * on campus between SignInDT and SignOutDT is treated as attending every session
 * that started inside that window (same effect as the teacher marking 已上,
 * including session deduction). Used by the swipe sign-out and by
 * student-signin:close-orphans.
 */
class StudentPresenceBackfillService
{
    /**
     * Idempotent: sessions that already have a non-voided sign-in (teacher-made or
     * from an earlier run) are excluded.
     *
     * #4192: the candidate query is only a snapshot. Each candidate is re-checked
     * under StudentClass FOR UPDATE -> ClassSession FOR UPDATE (the order used by
     * cancellation/billing-correction and SessionDeductionService), so a session
     * cancelled after the candidate read is skipped instead of overwritten.
     */
    public static function backfill(Student $student, Carbon $signInDT, Carbon $signOutDT, int $campusId): void
    {
        // Both callers already run inside a transaction; this keeps the locks held
        // until the effects are written for any future caller as well.
        DB::transaction(fn () => self::backfillLocked($student, $signInDT, $signOutDT, $campusId));
    }

    private static function backfillLocked(Student $student, Carbon $signInDT, Carbon $signOutDT, int $campusId): void
    {
        $today = $signInDT->toDateString();

        $sessions = ClassSession::query()
            ->with('studentClass')
            ->whereHas('studentClass', fn ($q) => $q
                ->where('StudentID', $student->id)
                ->where('Stop', 0)
            )
            ->whereDate('SessionDate', $today)
            ->whereTime('StartTime', '>=', $signInDT->format('H:i:s'))
            ->whereTime('StartTime', '<=', $signOutDT->format('H:i:s'))
            // Only untouched sessions: never override a teacher/leave/cancel decision.
            ->where(DB::raw('LOWER(Status)'), SessionStatus::SCHEDULED)
            // Any sign-in, voided included: a staff void/undo must not be re-created.
            ->whereDoesntHave('signIns')
            ->orderBy('StartTime')
            ->orderBy('id')
            ->get();

        foreach ($sessions as $candidate) {
            if (!$candidate->studentClass) {
                continue;
            }

            $sc = StudentClass::query()->where('ID', $candidate->StudentClassID)->lockForUpdate()->first();
            $session = $sc
                ? ClassSession::query()->where('StudentClassID', $sc->ID)
                    ->where('id', $candidate->id)->lockForUpdate()->first()
                : null;
            // Current read: a cancel/leave/teacher decision or a sign-in committed
            // after the candidate snapshot wins; skip, never overwrite.
            if (!$sc || !$session
                || (int) $sc->StudentID !== (int) $student->id
                || (int) $sc->Stop !== 0
                || strtolower((string) $session->Status) !== SessionStatus::SCHEDULED
                || StudentSignIn::query()->where('ClassSessionID', $session->id)->lockForUpdate()->exists()) {
                Log::info('presence_window_skip_changed_after_candidate', [
                    'class_session_id' => $candidate->id,
                    'student_id'       => $student->id,
                    'status'           => $session?->Status,
                ]);
                continue;
            }
            $session->setRelation('studentClass', $sc);

            // TD-009: 防禦 EndTime=null，跳過避免 SignOutDT=00:00
            if (!$session->EndTime) {
                Log::warning('presence_window_skip_null_end_time', [
                    'class_session_id' => $session->id,
                    'student_id'       => $student->id,
                ]);
                continue;
            }

            $sessionSignInDT  = Carbon::parse($today . ' ' . $session->StartTime);
            $sessionSignOutDT = Carbon::parse($today . ' ' . $session->EndTime);

            $newSignIn = StudentSignIn::create([
                'StudentClassID'   => $sc->ID,
                'StudentID'        => $student->id,
                'TeacherID'        => $sc->TeacherID,
                'RecordedByUserID' => null,
                'GradeID'          => $sc->GradeID,
                'SubjectID'        => $sc->SubjectID,
                'Get1byID'         => $sc->by1,
                'Hours'            => $sc->TotalHours ? (int) $sc->TotalHours : null,
                'Memo'             => 'presence-window',
                'SignInDT'         => $sessionSignInDT,
                'SignOutDT'        => $sessionSignOutDT,
                'MDT'              => now(),
                'ClassSessionID'   => $session->id,
                'Status'           => 'present',
                'CampusID'         => $campusId,
                'PersonType'       => 'student',
                'SessionDeducted'  => false,
            ]);

            // FR-004: backfilled rows use session StartTime as SignInDT, so 'present' (not late).
            AttendanceEffectsService::applySessionStatus($session, 'present');

            SessionDeductionService::deductOnAttendance($sc, $newSignIn);
            self::alertIfNotDeducted($newSignIn, $campusId);

            Log::info('presence_window_backfill', [
                'student_id'       => $student->id,
                'student_name'     => $student->name,
                'class_session_id' => $session->id,
                'sign_in_dt'       => $sessionSignInDT->toDateTimeString(),
                'sign_out_dt'      => $sessionSignOutDT->toDateTimeString(),
            ]);
        }
    }

    /**
     * Call after deductOnAttendance for a sign-in that was expected to deduct.
     * deductOnAttendance swallows exceptions, so the DB flag is the source of truth.
     * Logs and raises a staff-visible Notification (action inbox "ops" lane).
     */
    public static function alertIfNotDeducted(StudentSignIn $signIn, int $campusId): void
    {
        if ($signIn->fresh()?->SessionDeducted) {
            return;
        }

        $context = [
            'sign_in_id'       => $signIn->id,
            'student_id'       => $signIn->StudentID,
            'student_class_id' => $signIn->StudentClassID,
            'class_session_id' => $signIn->ClassSessionID,
            'campus_id'        => $campusId,
        ];
        Log::error('swipe_attendance_deduction_failed', $context);

        try {
            $name = Student::find($signIn->StudentID)?->name ?? ('#' . $signIn->StudentID);
            Notification::firstOrCreate(
                ['SourceKey' => 'deduction_failed:' . $signIn->id],
                [
                    'CampusID'   => $campusId,
                    'Type'       => 'deduction_failed',
                    'Severity'   => 'high',
                    'Title'      => "刷卡出席但扣堂失敗：{$name}",
                    'Body'       => '已記錄出席但未扣堂，請至出席管理確認並補扣或更正。',
                    'SourceType' => 'StudentSignIn',
                    'SourceID'   => $signIn->id,
                    'Payload'    => array_merge($context, ['student_name' => $name]),
                    'OccurredAt' => now(),
                ]
            );
        } catch (\Throwable $e) {
            Log::error('swipe_attendance_deduction_alert_failed', $context + ['error' => $e->getMessage()]);
        }
    }
}
