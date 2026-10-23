<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Schedule;
use App\Models\SessionDeductionLedger;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CourseLeaveCascadeService
{
    public const NOTE_LEAVE = 'leave';
    public const NOTE_AUTO_EXTENDED = 'auto-extended-after-leave';
    public const NOTE_REVERT_TO_SCHEDULED = 'revert-to-scheduled';
    public const NOTE_POLICY_SHIFT = 'leave-policy-shift';
    /**
     * Single source of truth for the "ordinary leave" VoidReason literal.
     * A retyped/mis-encoded copy of this exact string once broke undo-leave
     * matching in production (in-app #217/#218: undoLeaveCascade's manual_occurrence
     * branch compared against a corrupted duplicate of this literal, so it never
     * matched and VoidedAt/VoidReason never cleared). Every write AND every
     * read/compare of this reason must go through this constant — never retype
     * the literal.
     */
    public const VOID_REASON_LEAVE = '一般請假';
    public const VOID_REASON_CANCELLED = '課堂已取消';

    public const POLICY_KEEP_FUTURE_DATES_APPEND_TAIL = 'KEEP_FUTURE_DATES_APPEND_TAIL';
    public const POLICY_SHIFT_FUTURE_DATES_APPEND_TAIL = 'SHIFT_FUTURE_DATES_APPEND_TAIL';
    public const DEFAULT_LEAVE_POLICY = self::POLICY_KEEP_FUTURE_DATES_APPEND_TAIL;
    /** Non-billable ClassSession statuses (use NOTE_LEAVE to avoid FIT-5 status-literal ratchet). */
    public const NON_BILLABLE_STATUSES = ['cancelled', self::NOTE_LEAVE, 'leave_adjusted', 'excused'];

    /** @return array<string, mixed> */
    public static function leaveOutcomeForCourse(StudentClass $course): array
    {
        $isDateMode = strtolower((string) ($course->getAttribute('ScheduleMode') ?? 'count')) === 'date';

        if (!$isDateMode) {
            return ['leave_mode' => 'count_append_tail'];
        }

        return [
            'leave_mode' => 'monthly_bounded',
            'future_dates_unchanged' => true,
            'auto_makeup_created' => false,
            'end_date_changed' => false,
            'contract_end_date' => $course->getAttribute('EndDate')
                ? Carbon::parse($course->getAttribute('EndDate'))->toDateString()
                : null,
        ];
    }

    /**
     * Void any live LearningRecord/StudentSignIn for a session that is becoming (or already
     * is) leave. Single source of truth — every code path that transitions a ClassSession to
     * leave/leave_adjusted must call this, or a pre-existing LR (e.g. one the nightly
     * learning-records:backfill-missing job created while the session was still 'scheduled'
     * and already past) is left live and orphaned. That's exactly what bugs:verify-reproductions'
     * enforced `leave_session_with_live_learning_record` condition (#170) catches nightly —
     * a query-level UI filter (LearningRecord::scopeExcludeLeaveSessionPendingReview) already
     * hides the symptom from the pending-review list, but does not fix the underlying row.
     */
    public static function voidLiveArtifactsForLeave(int $classSessionId): void
    {
        self::voidLiveArtifactsForNonAttendance($classSessionId, self::VOID_REASON_LEAVE);
    }

    /**
     * Mark one existing session as leave without invoking the count-based tail
     * planner. The caller owns the surrounding transaction/row lock.
     */
    public static function markSessionLeaveOnly(
        ClassSession $session,
        string $note = self::NOTE_LEAVE
    ): ClassSession {
        self::voidLiveArtifactsForLeave((int) $session->getKey());

        if (strtolower((string) ($session->getAttribute('Status') ?? '')) !== self::NOTE_LEAVE) {
            $session->setAttribute('Status', self::NOTE_LEAVE);
            $session->setAttribute('Note', self::appendNote($session->getAttribute('Note'), $note));
            $session->save();
        }

        return $session;
    }

    /**
     * The calendar's leave marker for one lesson (#4210 P2-3): ordinary leave and the renewal-shift leave
     * write the same row. With two lessons on the date the marker also carries the lesson's start (#3914).
     * The caller owns the leave transaction and its row locks.
     */
    public static function recordLeaveMarker(StudentClass $course, ClassSession $session, int $campusId): void
    {
        $date = Carbon::parse($session->SessionDate)->toDateString();
        $key = ['student_course_id' => (int) $course->ID, 'schedule_date' => $date, 'status' => 'leave']
            + (self::courseHasTwoLessonsOn((int) $course->ID, $date) ? ['start_time' => substr((string) $session->StartTime, 0, 5)] : []); // HH:MM like other producers
        Schedule::query()->firstOrCreate($key, [
            'student_id'  => (int) $course->StudentID,
            'day_of_week' => Carbon::parse($date)->dayOfWeekIso,
            'start_time'  => substr((string) $session->StartTime, 0, 5),
            'end_time'    => substr((string) $session->EndTime, 0, 5),
            'class_type'  => $course->ClassType ?? 'one_on_one',
            'type'        => 'normal',
            'deduction'   => 0,
            'branch_id'   => $campusId,
            'teacher_id'  => (int) ($course->TeacherID ?? 0),
            'subject'     => $course->SubjectID ?? null,
        ]);
    }

    /** #3914 review: does this course hold two or more (non-cancelled) lessons on the date? */
    public static function courseHasTwoLessonsOn(int $courseId, string $date): bool
    {
        return ClassSession::query()->where('StudentClassID', $courseId)->whereDate('SessionDate', $date)
            ->whereRaw('LOWER(COALESCE(Status, \'\')) <> ?', ['cancelled'])->count() > 1;
    }

    /**
     * Void live attendance/evaluation artifacts for a non-attended session.
     *
     * A cancelled session cannot be a source of a new evaluation or attendance
     * evidence. Keeping this in the same service as leave cascading prevents
     * cancellation paths from leaving a pending LearningRecord behind.
     */
    public static function voidLiveArtifactsForNonAttendance(
        int $classSessionId,
        string $reason,
        ?int $actorUserId = null
    ): void {
        LearningRecord::where('ClassSessionID', $classSessionId)
            ->active()
            ->update([
                'VoidedAt'       => now(),
                'VoidedByUserID' => $actorUserId ?: null,
                'VoidReason'     => $reason,
                'updated_at'     => now(),
            ]);
        StudentSignIn::where('ClassSessionID', $classSessionId)
            ->active()
            ->update([
                'VoidedAt'       => now(),
                'VoidedByUserID' => $actorUserId ?: null,
                'VoidReason'     => $reason,
            ]);
    }

    /**
     * Nightly self-healing sweep for #170: `voidLiveArtifactsForLeave()` only guards the code
     * paths that transition a session to leave *going forward* — it does nothing for rows that
     * were already left in a bad state by a call that happened before this guard existed (or by
     * any future code path nobody has found and covered yet). Scans every ClassSession currently
     * sitting in leave/leave_adjusted for a live LearningRecord and voids it via the same shared
     * logic, so `bugs:verify-reproductions`' `leave_session_with_live_learning_record` condition
     * self-heals on its own schedule instead of requiring a one-off manual data repair each time
     * it's found. Idempotent: a session with no live LearningRecord is a no-op.
     *
     * @return int number of ClassSession rows that had at least one live artifact voided
     */
    public static function sweepStaleLiveArtifactsForLeave(): int
    {
        return self::sweepStaleLiveArtifactsForStatuses(['leave', 'leave_adjusted']);
    }

    /**
     * Self-heal live artifacts on any ClassSession that cannot have attendance
     * evidence. The scan includes cancelled sessions because cancellation can
     * happen after a teacher has already opened an evaluation form.
     *
     * @return int number of ClassSession rows that had at least one live artifact voided
     */
    public static function sweepStaleLiveArtifactsForNonAttendance(): int
    {
        return self::sweepStaleLiveArtifactsForStatuses(self::NON_BILLABLE_STATUSES);
    }

    /**
     * @param array<int,string> $statuses
     */
    private static function sweepStaleLiveArtifactsForStatuses(array $statuses): int
    {
        $sessionIds = DB::table('ClassSession as cs')
            ->whereIn(DB::raw('LOWER(cs.Status)'), $statuses)
            ->where(function ($query): void {
                $query->whereExists(function ($sub): void {
                    $sub->selectRaw('1')
                        ->from('LearningRecord as lr')
                        ->whereColumn('lr.ClassSessionID', 'cs.id')
                        ->whereNull('lr.VoidedAt');
                })->orWhereExists(function ($sub): void {
                    $sub->selectRaw('1')
                        ->from('StudentSingIn as si')
                        ->whereColumn('si.ClassSessionID', 'cs.id')
                        ->whereNull('si.VoidedAt');
                });
            })
            ->distinct()
            ->get(['cs.id', 'cs.Status']);

        foreach ($sessionIds as $session) {
            $status = strtolower((string) $session->Status);
            $reason = $status === 'cancelled'
                ? self::VOID_REASON_CANCELLED
                : self::VOID_REASON_LEAVE;
            self::voidLiveArtifactsForNonAttendance((int) $session->id, $reason);
        }

        return $sessionIds->count();
    }

    /**
     * Mark the target session as leave, void related records,
     * keep subsequent scheduled sessions in place, and append one new session
     * to keep the total count intact for prepaid count courses.
     *
     * Must be called inside a DB::transaction.
     *
     * #3914: pass $sessionId (the clicked lesson) whenever the caller knows it; a date can hold two lessons of one
     * course, and the date alone always resolves to the first.
     *
     * @return array{0:array,1:?string,2:string,3:int}  [session_rows, extended_end_date, leave_session_date, leave_session_id]
     * @throws \InvalidArgumentException
     */
    public static function applyLeaveCascade(int $courseId, string $leaveDate, ?int $sessionId = null): array
    {
        $course = StudentClass::where('ID', $courseId)->lockForUpdate()->first();
        if (!$course) {
            throw new \InvalidArgumentException('找不到課程，無法請假');
        }

        $sessions = ClassSession::where('StudentClassID', $courseId)
            ->orderBy('SessionDate', 'asc')
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();
        if ($sessions->isEmpty()) {
            throw new \InvalidArgumentException('課程尚無堂次可請假');
        }

        $normalizedLeaveDate = Carbon::parse($leaveDate)->toDateString();
        self::requireNamedLesson($sessions, $normalizedLeaveDate, $sessionId);

        // Find the target session: accept scheduled/leave (but not cancelled/leave_adjusted)
        $leaveSession = $sessions->first(function ($session) use ($normalizedLeaveDate, $sessionId) {
            $status = strtolower((string) ($session->Status ?? ''));
            return Carbon::parse($session->SessionDate)->toDateString() === $normalizedLeaveDate
                && ($sessionId === null || (int) $session->id === $sessionId)
                && !in_array($status, ['cancelled', 'leave_adjusted'], true);
        });
        if (!$leaveSession) {
            throw new \InvalidArgumentException('找不到可請假的堂次');
        }

        $leaveStatus = strtolower((string) ($leaveSession->Status ?? ''));

        // Date-mode idempotency is deliberately independent of legacy
        // SessionCount/tail provenance. A repeated calendar save must only
        // re-assert the target status and never infer an append obligation.
        if ($leaveStatus === self::NOTE_LEAVE
            && strtolower((string) ($course->ScheduleMode ?? 'count')) === 'date') {
            self::voidLiveArtifactsForLeave((int) $leaveSession->id);
            return [self::fetchCourseSessionRows($courseId), null, $normalizedLeaveDate, (int) $leaveSession->id];
        }

        if (in_array($leaveStatus, ['completed', 'attended'], true)) {
            throw new \InvalidArgumentException('已完成堂次不可請假（如需補請假請使用 retro-leave）');
        }

        // KEEP policy idempotency: leave + provenance append for THIS leave.
        if ($leaveStatus === 'leave') {
            $leaveSessionId = (int) $leaveSession->id;
            // #3914: with two lessons on the date, only this lesson's own `:ls=` provenance proves its tail exists.
            $dateOnly = self::lessonsOnDate($sessions, $normalizedLeaveDate) <= 1;
            $hasAppend = $sessions->contains(function ($s) use ($normalizedLeaveDate, $leaveSessionId, $dateOnly) {
                $note = (string) ($s->Note ?? '');
                $st = strtolower((string) ($s->Status ?? ''));
                if ($st !== 'scheduled' || !str_contains($note, self::NOTE_AUTO_EXTENDED)) {
                    return false;
                }
                return ($dateOnly && str_contains($note, ':ld=' . $normalizedLeaveDate))
                    || self::noteHasLeaveSession($note, $leaveSessionId);
            });
            // Legacy half-written leave (no provenance): treat "already cascaded"
            // only when natural next slot is vacant AND a later billable exists.
            if (!$hasAppend) {
                $weekdays = self::resolveCourseWeekdays(
                    $course,
                    (int) Carbon::parse($normalizedLeaveDate)->dayOfWeekIso
                );
                $naturalNext = self::nextRecurringDate(
                    Carbon::parse($normalizedLeaveDate)->startOfDay(),
                    $weekdays,
                    [$normalizedLeaveDate => true]
                );
                $hasNatural = $sessions->contains(function ($s) use ($naturalNext) {
                    $st = strtolower((string) ($s->Status ?? ''));
                    return Carbon::parse($s->SessionDate)->toDateString() === $naturalNext
                        && !in_array($st, ['cancelled'], true);
                });
                $later = $sessions->first(function ($s) use ($naturalNext) {
                    $st = strtolower((string) ($s->Status ?? ''));
                    $d = Carbon::parse($s->SessionDate)->toDateString();
                    return $d > $naturalNext && !in_array($st, ['cancelled', 'leave', 'leave_adjusted'], true);
                });
                if (!$hasNatural && $later) {
                    throw new \InvalidArgumentException('該堂已完成請假登記與課程順延');
                }
            } else {
                throw new \InvalidArgumentException('該堂已完成請假登記與尾堂補上');
            }
        }

        $hasApprovedRecord = LearningRecord::where('ClassSessionID', $leaveSession->id)
            ->active()
            ->where('Status', 'approved')
            ->exists();
        if ($hasApprovedRecord) {
            throw new \InvalidArgumentException('該堂已有核准評量，無法改為請假');
        }

        $leaveSessionDate = Carbon::parse($leaveSession->SessionDate)->toDateString();

        self::voidLiveArtifactsForLeave((int) $leaveSession->id);

        // Only update ClassSession status if not already leave
        if ($leaveStatus !== 'leave') {
            $leaveSession->Status = 'leave';
            $leaveSession->Note = self::appendNote($leaveSession->Note, self::NOTE_LEAVE);
            $leaveSession->save();
        }

        [$rows, $extendedEndDate] = self::appendTailAfterLeave($courseId, $leaveSessionDate, $leaveSession);

        return [$rows, $extendedEndDate, $leaveSessionDate, (int) $leaveSession->id];
    }

    /** The scheduled auto-extended tail whose provenance names exactly this leave lesson. */
    private static function ownTail(\Illuminate\Support\Collection $sessions, int $leaveSessionId): ?ClassSession
    {
        $tail = $sessions->first(fn ($s) => strtolower((string) ($s->Status ?? '')) === 'scheduled'
            && str_contains((string) ($s->Note ?? ''), self::NOTE_AUTO_EXTENDED)
            && self::noteHasLeaveSession((string) ($s->Note ?? ''), $leaveSessionId));

        return $tail instanceof ClassSession ? $tail : null;
    }

    /** Non-cancelled lessons of the course on one date. */
    private static function lessonsOnDate(\Illuminate\Support\Collection $sessions, string $date): int
    {
        return $sessions->filter(fn ($s) => Carbon::parse($s->SessionDate)->toDateString() === $date
            && strtolower((string) ($s->Status ?? '')) !== 'cancelled')->count();
    }

    /** #3914 review: on a date with two or more lessons the caller must name one; never fall back to the day's first. */
    private static function requireNamedLesson(\Illuminate\Support\Collection $sessions, string $date, ?int $sessionId): void
    {
        if ($sessionId === null && self::lessonsOnDate($sessions, $date) > 1) {
            throw new \InvalidArgumentException('這門課這天有兩堂以上，請從要請假（或取消請假）的那一堂操作。');
        }
    }

    /** Exact `:ls=<id>` provenance (so :ls=12 never matches :ls=123). */
    private static function noteHasLeaveSession(string $note, int $sessionId): bool
    {
        return (bool) preg_match('/:ls=' . $sessionId . '(?!\d)/', $note);
    }

    /**
     * #3914: the lesson a calendar row means — the course's lesson on that date at that start time. Null when the time
     * does not single one out, so callers fall back to the date (unchanged behaviour for one lesson per date).
     */
    public static function sessionIdAt(int $courseId, string $date, ?string $startTime): ?int
    {
        if ($startTime === null || trim($startTime) === '') {
            return null;
        }
        $ids = ClassSession::query()->where('StudentClassID', $courseId)->whereDate('SessionDate', Carbon::parse($date)->toDateString())
            ->where('StartTime', 'like', substr(trim($startTime), 0, 5) . '%')
            ->whereRaw('LOWER(COALESCE(Status, \'\')) <> ?', ['cancelled'])->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    /**
     * Explicit pause/shift — NOT ordinary leave.
     *
     * @return array{0:array,1:?string,2:string}
     */
    public static function applyExplicitCoursePauseShift(int $courseId, string $leaveDate): array
    {
        $course = StudentClass::query()->where('ID', $courseId)->lockForUpdate()->first();
        if (!$course) {
            throw new \InvalidArgumentException('找不到課程，無法整體順延');
        }
        $sessions = ClassSession::query()
            ->where('StudentClassID', $courseId)
            ->orderBy('SessionDate', 'asc')->orderBy('id', 'asc')->lockForUpdate()->get();
        $normalizedLeaveDate = Carbon::parse($leaveDate)->toDateString();
        $leaveSession = $sessions->first(function ($session) use ($normalizedLeaveDate) {
            $status = strtolower((string) ($session->Status ?? ''));
            return Carbon::parse($session->SessionDate)->toDateString() === $normalizedLeaveDate
                && !in_array($status, ['cancelled', 'leave_adjusted'], true);
        });
        if (!$leaveSession) {
            throw new \InvalidArgumentException('找不到可順延的堂次');
        }
        if (strtolower((string) ($leaveSession->Status ?? '')) !== 'leave') {
            $leaveSession->Status = 'leave';
            $leaveSession->Note = self::appendNote($leaveSession->Note, self::NOTE_LEAVE);
            $leaveSession->Note = self::appendNote($leaveSession->Note, self::NOTE_POLICY_SHIFT);
            $leaveSession->save();
        }
        [$rows, $extendedEndDate] = self::shiftAndAppendAfterLeave($courseId, $normalizedLeaveDate, $leaveSession);
        return [$rows, $extendedEndDate, $normalizedLeaveDate];
    }

    /**
     * Load course sessions and return a dry-run leave cascade plan.
     *
     * @return array{
     *   leave_session_date: string,
     *   weekdays: list<int>,
     *   moves: list<array{from:string,to:string,id:?int}>,
     *   vacated: list<string>,
     *   append: ?string,
     *   extended_end_date: ?string
     * }
     */
    public static function previewLeaveCascadeForCourse(
        int $courseId,
        string $leaveDate,
        ?int $classSessionId = null,
        string $policy = self::DEFAULT_LEAVE_POLICY
    ): array {
        $course = StudentClass::query()->where('ID', $courseId)->first();
        if (!$course) {
            // Prefer query() to avoid new Eloquent::where() baseline counts in this service.
            throw new \InvalidArgumentException('找不到課程');
        }

        $normalizedLeaveDate = Carbon::parse($leaveDate)->toDateString();
        $sessions = ClassSession::query()
            ->where('StudentClassID', $courseId)
            ->orderBy('SessionDate', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $leaveSession = null;
        if ($classSessionId && $classSessionId > 0) {
            $leaveSession = $sessions->first(fn ($s) => (int) $s->id === $classSessionId);
        }
        if (!$leaveSession) {
            $leaveSession = $sessions->first(function ($session) use ($normalizedLeaveDate) {
                $status = strtolower((string) ($session->Status ?? ''));
                return Carbon::parse($session->SessionDate)->toDateString() === $normalizedLeaveDate
                    && !in_array($status, ['cancelled', 'leave_adjusted'], true);
            });
        }
        if (!$leaveSession) {
            throw new \InvalidArgumentException('找不到可請假的堂次');
        }

        $leaveSessionDate = Carbon::parse($leaveSession->SessionDate)->toDateString();
        $weekdays = self::resolveCourseWeekdays(
            $course,
            (int) Carbon::parse($leaveSession->SessionDate)->dayOfWeekIso
        );
        $sessionRows = self::planRows($sessions);

        // Monthly/date-mode courses are bounded by their contract interval.
        // A leave reduces actual billable sessions; it never creates a tail or
        // changes EndDate. This also protects legacy rows with a positive
        // SessionCount from entering the count-based planner.
        if (strtolower((string) ($course->ScheduleMode ?? 'count')) === 'date') {
            return [
                'policy' => self::POLICY_KEEP_FUTURE_DATES_APPEND_TAIL,
                'leave_session_date' => $leaveSessionDate,
                'weekdays' => $weekdays,
                'moves' => [],
                'vacated' => [],
                'append' => null,
                'extended_end_date' => null,
                'contract_end_date' => $course->EndDate
                    ? Carbon::parse($course->EndDate)->toDateString()
                    : null,
                'future_dates_unchanged' => true,
                'next_billable_session' => null,
            ];
        }
        if ($policy === self::POLICY_SHIFT_FUTURE_DATES_APPEND_TAIL) {
            $plan = self::computeShiftPlan($sessionRows, $leaveSessionDate, $weekdays, (int) $leaveSession->id);
            return [
                'policy' => $policy,
                'leave_session_date' => $leaveSessionDate,
                'weekdays' => $weekdays,
                'moves' => $plan['moves'],
                'vacated' => $plan['vacated'],
                'append' => $plan['append'],
                'extended_end_date' => $plan['extended_end_date'],
                'future_dates_unchanged' => false,
                'next_billable_session' => null,
            ];
        }
        $purchased = max(0, (int) ($course->SessionCount ?? 0));
        // #4019: same rows and contract slots as appendTailAfterLeave(), so the preview shows the date that is written.
        $plan = self::computeAppendOnlyPlan($sessionRows, $leaveSessionDate, $weekdays, (int) $leaveSession->id, $purchased, self::contractSlotKeys($course));
        $next = null;
        $ord = 0;
        foreach ($sessionRows as $row) {
            $date = Carbon::parse((string) $row['date'])->toDateString();
            $status = strtolower((string) $row['status']);
            if ($date === $leaveSessionDate || in_array($status, self::NON_BILLABLE_STATUSES, true)) {
                continue;
            }
            $ord++;
            if ($date > $leaveSessionDate && $next === null) {
                $next = ['date' => $date, 'ordinal' => $ord, 'id' => (int) $row['id']];
            }
        }
        return [
            'policy' => self::POLICY_KEEP_FUTURE_DATES_APPEND_TAIL,
            'leave_session_date' => $leaveSessionDate,
            'weekdays' => $weekdays,
            'moves' => [],
            'vacated' => [],
            'append' => $plan['append'],
            'extended_end_date' => $plan['extended_end_date'],
            'future_dates_unchanged' => true,
            'next_billable_session' => $next,
        ];
    }


    /**
     * Ordinary leave plan: never moves/vacates; append at most one tail.
     *
     * #4019: with $contractSlots ("isoDow|HH:MM"), a tail of a lesson on a two-lesson date may share the latest date
     * with its sibling (see sharedTailDate()); without it the plan stays date-keyed.
     *
     * @param  list<array{id?:int|string|null,date:string,status?:string|null,start?:?string,end?:?string,note?:?string}>  $sessionRows
     * @param  array<int>  $weekdays
     * @param  list<string>  $contractSlots
     * @return array{moves: list, vacated: list, append: ?string, extended_end_date: ?string, append_count: int}
     */
    public static function computeAppendOnlyPlan(
        array $sessionRows,
        string $leaveDate,
        array $weekdays,
        ?int $leaveSessionId = null,
        int $purchasedSessions = 0,
        array $contractSlots = []
    ): array {
        $normalizedLeaveDate = Carbon::parse($leaveDate)->toDateString();
        $weekdays = array_values(array_unique(array_map('intval', $weekdays)));
        if ($weekdays === []) {
            $weekdays = [(int) Carbon::parse($normalizedLeaveDate)->dayOfWeekIso];
        }
        $occupiedDates = [];
        $liveDates = [$normalizedLeaveDate => true];
        $billable = 0;
        foreach ($sessionRows as $row) {
            $id = isset($row['id']) ? (int) $row['id'] : 0;
            $date = Carbon::parse((string) $row['date'])->toDateString();
            $status = strtolower((string) ($row['status'] ?? ''));
            $occupiedDates[$date] = true;
            if ($status !== 'cancelled') {
                $liveDates[$date] = true;
            }
            $isLeaveTarget = ($leaveSessionId && $id === $leaveSessionId)
                || (!$leaveSessionId && $date === $normalizedLeaveDate);
            if ($isLeaveTarget) {
                continue;
            }
            if (!in_array($status, self::NON_BILLABLE_STATUSES, true)) {
                $billable++;
            }
        }
        $occupiedDates[$normalizedLeaveDate] = true;
        $append = null;
        $appendCount = 0;
        $needsAppend = $purchasedSessions <= 0 ? true : ($billable < $purchasedSessions);
        if ($needsAppend) {
            $appendCount = 1;
            $latest = self::maxDateKey($occupiedDates) ?: $normalizedLeaveDate;
            $shared = self::sharedTailDate($sessionRows, $normalizedLeaveDate, $weekdays, $leaveSessionId, $contractSlots);
            $append = $shared ?? self::nextRecurringDate(Carbon::parse($latest)->startOfDay(), $weekdays, $occupiedDates);
            $occupiedDates[$append] = true;
            if ($shared !== null) {
                // #4052 review: sharedTailDate() ignores cancelled rows, so EndDate does too (never past the last live lesson).
                $occupiedDates = $liveDates + [$append => true];
            }
        }
        return [
            'moves' => [],
            'vacated' => [],
            'append' => $append,
            'extended_end_date' => self::maxDateKey($occupiedDates) ?: $normalizedLeaveDate,
            'append_count' => $appendCount,
        ];
    }

    /**
     * #4019/#4047: the tail date of a lesson on a date with two or more lessons, else null (the caller then takes the
     * next recurring date after the course's latest date). It is a pure function of the course state without this
     * leave date's own auto-tails (the "base"), so either leave order gives the same dates and the preview equals the write:
     * - the base's latest date, when it is after the leave date, is on a contract weekday where the left lesson's start
     *   time is a contract slot (#4043 r4266126936), holds a billable contract lesson (never a confirmed makeup, whose
     *   Note carries `exception_workflow:`), and has fewer live lessons than the leave date;
     * - otherwise the next date after the base's latest date on a weekday where the left lesson's start time is a
     *   contract slot (any course weekday when none is), which is where the sibling tails go too.
     * Either date is refused (null) when a live row there, sibling tails included, takes or overlaps the left slot.
     *
     * @param  list<array<string,mixed>>  $rows
     * @param  array<int>  $weekdays
     * @param  list<string>  $contractSlots
     */
    private static function sharedTailDate(array $rows, string $leaveDate, array $weekdays, ?int $leaveSessionId, array $contractSlots): ?string
    {
        $hm = fn ($t): string => substr(trim((string) ($t ?? '')), 0, 5);
        $live = array_values(array_filter($rows, fn ($r) => strtolower((string) ($r['status'] ?? '')) !== 'cancelled'));
        $base = array_values(array_filter($live, fn ($r) => !(str_contains((string) ($r['note'] ?? ''), self::NOTE_AUTO_EXTENDED)
            && str_contains((string) ($r['note'] ?? ''), ':ld=' . $leaveDate))));
        $on = fn (array $set, string $d): array => array_values(array_filter($set, fn ($r) => Carbon::parse((string) $r['date'])->toDateString() === $d));
        $leave = null;
        foreach ($rows as $r) {
            if ($leaveSessionId && (int) ($r['id'] ?? 0) === $leaveSessionId) {
                $leave = $r;
            }
        }
        $start = $hm($leave['start'] ?? null);
        $end = $hm($leave['end'] ?? null) ?: $start;
        $onLeaveDate = count($on($base, $leaveDate));
        if ($contractSlots === [] || $start === '' || $onLeaveDate < 2) {
            return null;
        }
        $free = function (string $d) use ($on, $live, $hm, $start, $end): bool {
            foreach ($on($live, $d) as $r) {
                $rStart = $hm($r['start'] ?? null);
                $rEnd = $hm($r['end'] ?? null) ?: $rStart;
                if ($rStart === $start || ($rStart < $end && $start < $rEnd)) {
                    return false;
                }
            }

            return true;
        };
        $baseDates = [$leaveDate => true];
        foreach ($base as $r) {
            $baseDates[Carbon::parse((string) $r['date'])->toDateString()] = true;
        }
        $latest = (string) self::maxDateKey($baseDates);
        $dow = (int) Carbon::parse($latest)->dayOfWeekIso;
        $onLatest = $on($base, $latest);
        $anchored = false;
        foreach ($onLatest as $r) {
            $anchored = $anchored || (empty($r['makeup'])
                && !in_array(strtolower((string) ($r['status'] ?? '')), self::NON_BILLABLE_STATUSES, true)
                && in_array($dow . '|' . $hm($r['start'] ?? null), $contractSlots, true));
        }
        if ($anchored && $latest > $leaveDate && in_array($dow, $weekdays, true) && count($onLatest) < $onLeaveDate
            && in_array($dow . '|' . $start, $contractSlots, true) && $free($latest)) {
            return $latest;
        }
        $slotDays = array_values(array_filter($weekdays, fn ($d) => in_array($d . '|' . $start, $contractSlots, true)));
        $next = self::nextRecurringDate(Carbon::parse($latest)->startOfDay(), $slotDays ?: $weekdays, $baseDates);
        // #4058: an occupied compatible date keeps searching the same slot weekdays. Returning null here would let
        // computeAppendOnlyPlan() fall back to every course weekday and put this lesson's time on another weekday.
        for ($tries = 0; $tries < 52 && !$free($next); $tries++) {
            $baseDates[$next] = true;
            $next = self::nextRecurringDate(Carbon::parse($next)->startOfDay(), $slotDays ?: $weekdays, $baseDates);
        }

        return $free($next) ? $next : null;
    }

    /**
     * Planner rows (id, date, status, slot, note, makeup flag) for computeAppendOnlyPlan(); shared by preview and write.
     *
     * @return list<array{id:int,date:string,status:string,start:?string,end:?string,note:string,makeup:bool}>
     */
    private static function planRows(\Illuminate\Support\Collection $sessions): array
    {
        return $sessions->map(fn ($s) => [
            'id' => (int) $s->id,
            'date' => Carbon::parse($s->SessionDate)->toDateString(),
            'status' => (string) ($s->Status ?? ''),
            'start' => $s->StartTime !== null ? (string) $s->StartTime : null,
            'end' => $s->EndTime !== null ? (string) $s->EndTime : null,
            'note' => (string) ($s->Note ?? ''),
            // #4047: a confirmed makeup (ExceptionWorkflowController) never anchors a tail; other one-off edits still can.
            'makeup' => str_contains((string) ($s->Note ?? ''), 'exception_workflow:'),
        ])->values()->all();
    }

    /** @return list<string> the course's contract slots as "isoDow|HH:MM" (week/time … week6/time6). */
    private static function contractSlotKeys(?StudentClass $course): array
    {
        $keys = [];
        foreach (['', '1', '2', '3', '4', '5', '6'] as $n) {
            $dow = (int) ($course?->getAttribute('week' . $n) ?? 0);
            $time = substr(trim((string) ($course?->getAttribute('time' . $n) ?? '')), 0, 5);
            if ($dow >= 1 && $dow <= 7 && $time !== '') {
                $keys[] = $dow . '|' . $time;
            }
        }

        return $keys;
    }

    /**
     * KEEP policy write: append missing billable tail; do not move future dates.
     *
     * Single authoritative entry point for "auto-extend after leave/cancel" — every
     * caller (ordinary leave, retro-leave, and single-session status updates) MUST
     * route through this method rather than reimplementing the billable-vs-purchased
     * check locally. Two independent copies of this logic previously existed
     * (here and in ClassSessionController::tryExtendOnLeave), and since neither knew
     * about the other, a course could accumulate more non-cancelled dated sessions
     * than it actually purchased (in-app director report, 2026-08-06: 8 purchased,
     * 11+ live rows, blocked by the system's own overbooking guard).
     *
     * @return array{0:array,1:?string,2:?ClassSession}
     */
    public static function appendTailAfterLeave(int $courseId, string $leaveDate, ClassSession $leaveSession): array
    {
        $course = StudentClass::query()->where('ID', $courseId)->first();
        if ($course && strtolower((string) ($course->ScheduleMode ?? 'count')) === 'date') {
            return [
                self::fetchCourseSessionRows($courseId),
                null,
                null,
            ];
        }
        if ($course && (string) ($course->scheduling_policy ?? 'auto_recurrence') === 'manual_occurrence') {
            return [self::fetchCourseSessionRows($courseId), $course->EndDate ? Carbon::parse($course->EndDate)->toDateString() : null, null];
        }
        // Paused courses must not get sessions auto-generated back in — that would
        // reproduce the exact "cancelled it then it came back" symptom pause/resume
        // is meant to prevent (in-app #219).
        if ($course && (int) ($course->Stop ?? 0) === 1) {
            return [self::fetchCourseSessionRows($courseId), $course->EndDate ? Carbon::parse($course->EndDate)->toDateString() : null, null];
        }
        $sessions = ClassSession::query()
            ->where('StudentClassID', $courseId)
            ->orderBy('SessionDate', 'asc')->orderBy('id', 'asc')->get();
        $normalizedLeaveDate = Carbon::parse($leaveDate)->toDateString();
        $weekdays = self::resolveCourseWeekdays($course, Carbon::parse($leaveSession->SessionDate)->dayOfWeekIso);
        $purchased = max(0, (int) ($course->SessionCount ?? 0));
        $plan = self::computeAppendOnlyPlan(
            self::planRows($sessions),
            $normalizedLeaveDate,
            $weekdays,
            (int) $leaveSession->id,
            $purchased,
            self::contractSlotKeys($course)
        );
        $newSession = null;
        $twoLessonDate = self::lessonsOnDate($sessions, $normalizedLeaveDate) > 1;
        if ((int) $plan['append_count'] > 0 && $plan['append']) {
            $appendDate = $plan['append'];
            // #3914 review: a date with two lessons keeps the left lesson's own slot for its tail, not the weekday's first slot.
            $appendTimes = $twoLessonDate ? ['start' => '', 'end' => ''] : self::resolveContractSlotTimes($course, $appendDate);
            $appendStart = $appendTimes['start'] !== '' ? $appendTimes['start'] : $leaveSession->StartTime;
            $appendEnd = $appendTimes['end'] !== '' ? $appendTimes['end'] : $leaveSession->EndTime;
            $note = self::NOTE_AUTO_EXTENDED . ':ld=' . $normalizedLeaveDate . ':ls=' . (int) $leaveSession->id;
            $newSession = app(ClassSessionMaterializationService::class)->upsertSlot([
                'StudentClassID' => $courseId,
                'SessionDate' => $appendDate,
                'StartTime' => $appendStart,
                'EndTime' => $appendEnd,
                'Status' => 'scheduled',
                'Note' => $note,
                // #4019: a cancelled row in this slot must not come back as the tail; a live one is created beside it.
                '_revive_cancelled' => true,
            ])['session'];
            if (strtolower((string) ($newSession->Status ?? '')) !== 'scheduled') {
                throw new \InvalidArgumentException('請假補堂失敗：補堂時段已被其他堂次占用');
            }
            self::syncLearningRecordSessionDate($newSession);
        }
        $extendedEndDate = $plan['extended_end_date'];
        if ($extendedEndDate) {
            DB::table('StudentClass')->where('ID', $courseId)->update(['EndDate' => $extendedEndDate]);
        }
        return [self::fetchCourseSessionRows($courseId), $extendedEndDate, $newSession];
    }

    /**
     * Dry-run of leave cascade date moves (no DB writes).
     *
     * Used by leave UI impact preview so operators see which calendar weeks
     * will be vacated before confirming (in-app #204 silent-skip surprise).
     *
     * @param  list<array{id?:int|string|null,date:string,status?:string|null}>  $sessionRows
     * @param  array<int>  $weekdays  ISO weekdays 1=Mon … 7=Sun
     * @return array{
     *   moves: list<array{from:string,to:string,id:?int}>,
     *   vacated: list<string>,
     *   append: string,
     *   extended_end_date: string
     * }
     */
    public static function computeShiftPlan(
        array $sessionRows,
        string $leaveDate,
        array $weekdays,
        ?int $leaveSessionId = null
    ): array {
        $normalizedLeaveDate = Carbon::parse($leaveDate)->toDateString();
        $weekdays = array_values(array_unique(array_map('intval', $weekdays)));
        if ($weekdays === []) {
            $weekdays = [(int) Carbon::parse($normalizedLeaveDate)->dayOfWeekIso];
        }

        $shiftCandidates = [];
        foreach ($sessionRows as $row) {
            $id = isset($row['id']) ? (int) $row['id'] : 0;
            $date = Carbon::parse((string) $row['date'])->toDateString();
            $status = strtolower((string) ($row['status'] ?? ''));
            if ($leaveSessionId && $id === $leaveSessionId) {
                continue;
            }
            if ($date <= $normalizedLeaveDate) {
                continue;
            }
            if (in_array($status, ['completed', 'attended', 'cancelled', 'leave', 'leave_adjusted'], true)) {
                continue;
            }
            $shiftCandidates[] = ['id' => $id > 0 ? $id : null, 'date' => $date];
        }
        usort($shiftCandidates, function ($a, $b) {
            $cmp = strcmp($a['date'], $b['date']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
        });

        $shiftIdSet = [];
        foreach ($shiftCandidates as $c) {
            if ($c['id']) {
                $shiftIdSet[$c['id']] = true;
            }
        }

        $occupiedDates = [$normalizedLeaveDate => true];
        foreach ($sessionRows as $row) {
            $id = isset($row['id']) ? (int) $row['id'] : 0;
            if ($id > 0 && isset($shiftIdSet[$id])) {
                continue;
            }
            $date = Carbon::parse((string) $row['date'])->toDateString();
            $isShiftDate = false;
            if ($id <= 0) {
                foreach ($shiftCandidates as $c) {
                    if ($c['id'] === null && $c['date'] === $date) {
                        $isShiftDate = true;
                        break;
                    }
                }
            }
            if ($isShiftDate) {
                continue;
            }
            $occupiedDates[$date] = true;
        }

        // Shift latest-first (same order as shiftAndAppendAfterLeave / 1062 incident).
        $moves = [];
        for ($i = count($shiftCandidates) - 1; $i >= 0; $i--) {
            $from = $shiftCandidates[$i]['date'];
            $to = self::nextRecurringDate(Carbon::parse($from)->startOfDay(), $weekdays, $occupiedDates);
            $moves[] = [
                'from' => $from,
                'to' => $to,
                'id' => $shiftCandidates[$i]['id'],
            ];
            $occupiedDates[$to] = true;
        }
        // Present moves earliest-first for UI.
        $moves = array_reverse($moves);

        $latestDate = self::maxDateKey($occupiedDates) ?: $normalizedLeaveDate;
        $appendDate = self::nextRecurringDate(Carbon::parse($latestDate)->startOfDay(), $weekdays, $occupiedDates);
        $occupiedDates[$appendDate] = true;

        $fromDates = [];
        $toDates = [];
        foreach ($moves as $m) {
            $fromDates[$m['from']] = true;
            $toDates[$m['to']] = true;
        }
        $vacated = array_values(array_filter(
            array_keys($fromDates),
            fn ($d) => !isset($toDates[$d])
        ));
        sort($vacated, SORT_STRING);

        return [
            'moves' => $moves,
            'vacated' => $vacated,
            'append' => $appendDate,
            'extended_end_date' => self::maxDateKey($occupiedDates) ?: $appendDate,
        ];
    }

    /**
     * Shift remaining future scheduled sessions forward and append one new
     * session after the latest date, preserving the course weekday pattern.
     *
     * @return array{0:array,1:?string}
     */
    public static function shiftAndAppendAfterLeave(int $courseId, string $leaveDate, ClassSession $leaveSession): array
    {
        $course = StudentClass::where('ID', $courseId)->first();
        // Date/monthly courses are bounded by their billing interval. Never let
        // an explicit or accidental caller move a session across EndDate.
        if ($course && strtolower((string) ($course->ScheduleMode ?? 'count')) === 'date') {
            return [self::fetchCourseSessionRows($courseId), null];
        }
        if ($course && (string) ($course->scheduling_policy ?? 'auto_recurrence') === 'manual_occurrence') {
            $end = ClassSession::where('StudentClassID', $courseId)->max('SessionDate');
            return [self::fetchCourseSessionRows($courseId), $end ? Carbon::parse($end)->toDateString() : null];
        }
        $sessions = ClassSession::where('StudentClassID', $courseId)
            ->orderBy('SessionDate', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $normalizedLeaveDate = Carbon::parse($leaveDate)->toDateString();
        $weekdays = self::resolveCourseWeekdays(
            $course,
            Carbon::parse($leaveSession->SessionDate)->dayOfWeekIso
        );

        $sessionRows = $sessions->map(fn ($s) => [
            'id' => (int) $s->id,
            'date' => Carbon::parse($s->SessionDate)->toDateString(),
            'status' => (string) ($s->Status ?? ''),
        ])->all();

        $plan = self::computeShiftPlan(
            $sessionRows,
            $normalizedLeaveDate,
            $weekdays,
            (int) $leaveSession->id
        );

        $sessionsById = [];
        foreach ($sessions as $s) {
            $sessionsById[(int) $s->id] = $s;
        }

        // Apply latest-first to match unique-index-safe write order.
        // Invariant: after a date move, StartTime/EndTime must match the course
        // contract slot for the *target* weekday (multi-weekday courses often
        // have different clocks per day). Skipping this leaves Wed times on Sat.
        foreach (array_reverse($plan['moves']) as $move) {
            $id = (int) ($move['id'] ?? 0);
            if ($id <= 0 || !isset($sessionsById[$id])) {
                continue;
            }
            $s = $sessionsById[$id];
            $s->SessionDate = $move['to'];
            self::alignSessionTimesToContractWeekday($course, $s, (string) $move['to']);
            $s->save();
            self::syncLearningRecordSessionDate($s);
        }

        $templateSession = null;
        if (!empty($plan['moves'])) {
            $lastMoveId = (int) ($plan['moves'][count($plan['moves']) - 1]['id'] ?? 0);
            $templateSession = $sessionsById[$lastMoveId] ?? null;
        }
        $templateSession = $templateSession ?: $leaveSession;

        $appendDate = $plan['append'];
        $appendTimes = self::resolveContractSlotTimes($course, $appendDate);
        $appendStart = $appendTimes['start'] !== '' ? $appendTimes['start'] : $templateSession->StartTime;
        $appendEnd = $appendTimes['end'] !== '' ? $appendTimes['end'] : $templateSession->EndTime;
        $newSession = app(ClassSessionMaterializationService::class)->upsertSlot([
            'StudentClassID' => $courseId,
            'SessionDate'    => $appendDate,
            'StartTime'      => $appendStart,
            'EndTime'        => $appendEnd,
            'Status'         => 'scheduled',
            'Note'           => self::appendNote(
                self::NOTE_AUTO_EXTENDED . ':ld=' . $normalizedLeaveDate . ':ls=' . (int) $leaveSession->id,
                self::NOTE_POLICY_SHIFT
            ),
        ])['session'];
        self::syncLearningRecordSessionDate($newSession);

        $extendedEndDate = $plan['extended_end_date'];
        if ($extendedEndDate) {
            DB::table('StudentClass')
                ->where('ID', $courseId)
                ->update(['EndDate' => $extendedEndDate]);
        }

        $rows = self::fetchCourseSessionRows($courseId);
        return [$rows, $extendedEndDate];
    }

    /**
     * Undo one normal leave cascade.
     *
     * Constraints:
     * - only for the selected leave date / course
     * - blocks when downstream sessions already have attendance-like statuses
     * - removes one auto-extended tail session generated by leave cascade
     *
     * Must be called inside DB::transaction.
     *
     * @return array{0:array,1:?string,2:string,3:?string}
     */
    public static function undoLeaveCascade(int $courseId, string $leaveDate, ?int $sessionId = null): array
    {
        $course = StudentClass::where('ID', $courseId)->lockForUpdate()->first();
        if (!$course) {
            throw new \InvalidArgumentException('找不到課程，無法撤銷請假');
        }

        $normalizedLeaveDate = Carbon::parse($leaveDate)->toDateString();
        $sessions = ClassSession::where('StudentClassID', $courseId)
            ->orderBy('SessionDate', 'asc')
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();
        if ($sessions->isEmpty()) {
            throw new \InvalidArgumentException('課程尚無堂次可撤銷');
        }
        self::requireNamedLesson($sessions, $normalizedLeaveDate, $sessionId);

        $shiftTail = $sessionId === null ? null : CourseLeaveCascadeSuccessorShiftService::shiftTail($courseId, $sessionId);
        $leaveSession = $sessions->first(function ($session) use ($normalizedLeaveDate, $sessionId) {
            $sessionDate = Carbon::parse($session->SessionDate)->toDateString();
            $status = strtolower((string) ($session->Status ?? ''));
            return $sessionDate === $normalizedLeaveDate && $status === 'leave'
                && ($sessionId === null || (int) $session->id === $sessionId); // #3914: undo the clicked lesson
        });
        if (!$leaveSession && $shiftTail
            && StudentSignIn::query()->where('ClassSessionID', $sessionId)->whereNull('VoidedAt')
                ->whereIn('Status', ['leave', 'excused', 'leave_requested'])->exists()) {
            $leaveSession = $sessions->first(fn ($row) => (int) $row->id === $sessionId
                && Carbon::parse($row->SessionDate)->toDateString() === $normalizedLeaveDate
                && strtolower((string) $row->Status) === 'scheduled');
        }
        if (!$leaveSession) {
            throw new \InvalidArgumentException('找不到可撤銷的請假堂次');
        }

        $shiftTail = CourseLeaveCascadeSuccessorShiftService::shiftTail($courseId, (int) $leaveSession->id);
        CourseLeaveCascadeSuccessorShiftService::assertUndoAllowed($course, (int) $leaveSession->id);
        if ($shiftTail && (!Carbon::parse(Carbon::parse($shiftTail->SessionDate)->toDateString() . ' ' . $shiftTail->StartTime)->isFuture()
            || !self::isSafeToRemoveAutoAppend($shiftTail)
            || SessionDeductionLedger::query()->where('class_session_id', (int) $shiftTail->id)->exists())) {
            throw new \InvalidArgumentException('順延尾堂已經過期或已有使用紀錄，無法自動撤銷請假');
        }

        if (!$shiftTail && (string) ($course->scheduling_policy ?? 'auto_recurrence') === 'manual_occurrence') {
            self::restoreLeaveSession($leaveSession);
            $end = ClassSession::where('StudentClassID', $courseId)->max('SessionDate');
            return [self::fetchCourseSessionRows($courseId), $end ? Carbon::parse($end)->toDateString() : null, $normalizedLeaveDate, null];
        }

        $blockedStatusSet = ['attended', 'completed', 'late', 'present', 'absent', 'leave_adjusted'];
        $hasDownstreamLockedSessions = $sessions->contains(function ($session) use ($normalizedLeaveDate, $blockedStatusSet) {
            $sessionDate = Carbon::parse($session->SessionDate)->toDateString();
            if ($sessionDate <= $normalizedLeaveDate) {
                return false;
            }
            $status = strtolower((string) ($session->Status ?? ''));
            return in_array($status, $blockedStatusSet, true);
        });
        if ($hasDownstreamLockedSessions) {
            throw new \InvalidArgumentException('後續堂次已出現已上課/補請假等狀態，無法自動撤銷');
        }

        // A leave does not always create a tail for paused or date-based
        // courses without a purchased-session commitment.
        if (!$shiftTail && self::leaveCascadeDoesNotRequireTail($course)) {
            self::restoreLeaveSession($leaveSession);
            $end = $course->EndDate ? Carbon::parse($course->EndDate)->toDateString() : null;
            return [self::fetchCourseSessionRows($courseId), $end, $normalizedLeaveDate, null];
        }

        // #3914: this lesson's own tail first; another same-date lesson's tail is never taken for it.
        $appendedSession = $shiftTail ?? self::ownTail($sessions, (int) $leaveSession->id)
            ?? (self::lessonsOnDate($sessions, $normalizedLeaveDate) > 1 ? null : $sessions
            ->filter(function ($session) use ($normalizedLeaveDate) {
                $sessionDate = Carbon::parse($session->SessionDate)->toDateString();
                $status = strtolower((string) ($session->Status ?? ''));
                $note = strtolower((string) ($session->Note ?? ''));
                return $sessionDate >= $normalizedLeaveDate
                    && $status === 'scheduled'
                    && str_contains($note, self::NOTE_AUTO_EXTENDED);
            })
            ->sortByDesc('SessionDate')
            ->first());
        if (!$appendedSession) {
            // A legacy/manual repair may leave the leave row without its
            // auto-tail. Undoing the leave is still safe: restore only the
            // selected occurrence and its own leave artifacts, never shift or
            // delete another occurrence. The caller receives the warning so
            // the course can be reconciled separately instead of trapping the
            // director in the old "attended -> scheduled" workaround.
            self::restoreLeaveSession($leaveSession);
            $end = $course->EndDate ? Carbon::parse($course->EndDate)->toDateString() : null;
            return [self::fetchCourseSessionRows($courseId), $end, $normalizedLeaveDate, 'tail_missing'];
        }

        // KEEP undo: do not reverse-shift future dates. Legacy SHIFT rows keep
        // leave-policy-shift note and still need reverse-shift (handled below).
        $leaveNote = strtolower((string) ($leaveSession->Note ?? ''));
        $appendNote = strtolower((string) ($appendedSession->Note ?? ''));
        $isLegacyShift = !$shiftTail && (str_contains($leaveNote, self::NOTE_POLICY_SHIFT)
            || str_contains($appendNote, self::NOTE_POLICY_SHIFT));

        if ($isLegacyShift) {
            $weekdays = self::resolveCourseWeekdays(
                $course,
                (int) Carbon::parse($normalizedLeaveDate)->dayOfWeekIso
            );
            $movableSessions = $sessions
                ->filter(function ($session) use ($normalizedLeaveDate, $appendedSession, $leaveSession) {
                    if ((int) $session->id === (int) $appendedSession->id || (int) $session->id === (int) $leaveSession->id) {
                        return false;
                    }
                    $status = strtolower((string) ($session->Status ?? ''));
                    if ($status !== 'scheduled') {
                        return false;
                    }
                    return Carbon::parse($session->SessionDate)->toDateString() > $normalizedLeaveDate;
                })
                ->values();
            $occupiedDates = [];
            foreach ($sessions as $session) {
                if ((int) $session->id === (int) $appendedSession->id) {
                    continue;
                }
                if ($movableSessions->contains(fn ($s) => (int) $s->id === (int) $session->id)) {
                    continue;
                }
                $occupiedDates[Carbon::parse($session->SessionDate)->toDateString()] = true;
            }
            foreach ($movableSessions as $session) {
                $currentDate = Carbon::parse($session->SessionDate)->toDateString();
                $newDate = self::prevRecurringDate(
                    Carbon::parse($currentDate)->startOfDay(),
                    $weekdays,
                    $occupiedDates,
                    $normalizedLeaveDate
                );
                $session->SessionDate = $newDate;
                self::alignSessionTimesToContractWeekday($course, $session, $newDate);
                $session->save();
                self::syncLearningRecordSessionDate($session);
                $occupiedDates[$newDate] = true;
            }
        }

        self::restoreLeaveSession($leaveSession);
        if ($shiftTail) {
            StudentSignIn::query()->where('ClassSessionID', (int) $leaveSession->id)->whereNull('VoidedAt')
                ->whereIn('Status', ['leave', 'excused', 'leave_requested'])
                ->update(['VoidedAt' => now(), 'VoidReason' => '撤銷續約順延請假']);
        }
        $appendedSession->delete();

        $extendedEndDate = ClassSession::where('StudentClassID', $courseId)
            ->max('SessionDate');
        if ($extendedEndDate) {
            DB::table('StudentClass')
                ->where('ID', $courseId)
                ->update(['EndDate' => substr((string) $extendedEndDate, 0, 10)]);
        }

        $rows = self::fetchCourseSessionRows($courseId);
        return [$rows, $extendedEndDate ? substr((string) $extendedEndDate, 0, 10) : null, $normalizedLeaveDate, null];
    }

    // ── helpers ──────────────────────────────────────────────────────

    /**
     * Restore the target session and only the leave-cascade artifacts owned by
     * this service. Keeping this operation centralized prevents one undo path
     * from resurrecting unrelated manually-voided attendance/evaluation rows.
     */
    private static function restoreLeaveSession(ClassSession $leaveSession): void
    {
        $leaveSession->setAttribute('Status', 'scheduled');
        $leaveSession->setAttribute(
            'Note',
            CourseLeaveCascadeSuccessorShiftService::resolvedAnchorNote(
                self::appendNote((string) ($leaveSession->getAttribute('Note') ?? ''), self::NOTE_REVERT_TO_SCHEDULED))
        );
        $leaveSession->save();

        LearningRecord::where('ClassSessionID', (int) $leaveSession->id)
            ->where('VoidReason', self::VOID_REASON_LEAVE)
            ->update([
                'VoidedAt' => null,
                'VoidedByUserID' => null,
                'VoidReason' => null,
            ]);
        StudentSignIn::where('ClassSessionID', (int) $leaveSession->id)
            ->where('VoidReason', self::VOID_REASON_LEAVE)
            ->update([
                'VoidedAt' => null,
                'VoidedByUserID' => null,
                'VoidReason' => null,
            ]);
    }

    /**
     * Return true when the append writer would intentionally create no tail
     * for the current course/session state. This is separate from the legacy
     * missing-tail recovery below: the latter also restores only the selected
     * occurrence, but reports an explicit reconciliation warning.
     */
    private static function leaveCascadeDoesNotRequireTail(StudentClass $course): bool
    {
        if ((string) ($course->scheduling_policy ?? 'auto_recurrence') === 'manual_occurrence') {
            return true;
        }
        if ((int) ($course->Stop ?? 0) === 1) {
            return true;
        }

        $scheduleMode = strtolower((string) ($course->ScheduleMode ?? 'count'));
        if ($scheduleMode === 'date') {
            return true;
        }
        return false;
    }

    /** @return array<int> */
    public static function resolveCourseWeekdays(StudentClass $course, int $fallbackIsoDow): array
    {
        $weekdays = [];
        foreach (['week', 'week1', 'week2', 'week3', 'week4', 'week5', 'week6'] as $field) {
            $dow = (int) ($course->{$field} ?? 0);
            if ($dow >= 1 && $dow <= 7) {
                $weekdays[$dow] = $dow;
            }
        }
        if (empty($weekdays)) {
            $dow = max(1, min(7, $fallbackIsoDow));
            $weekdays[$dow] = $dow;
        }
        ksort($weekdays);
        return array_values($weekdays);
    }

    /**
     * @param  array<int>          $weekdays
     * @param  array<string,bool>  $occupiedDates
     */
    public static function nextRecurringDate(Carbon $afterDate, array $weekdays, array $occupiedDates): string
    {
        $cursor = $afterDate->copy()->addDay()->startOfDay();
        $guard = 0;
        while ($guard < 3660) {
            $guard++;
            $isoDow = (int) $cursor->dayOfWeekIso;
            $date = $cursor->toDateString();
            if (in_array($isoDow, $weekdays, true) && !isset($occupiedDates[$date])) {
                return $date;
            }
            $cursor->addDay();
        }
        throw new \InvalidArgumentException('請假遞延失敗：找不到可用的後續上課日期');
    }

    /**
     * @param  array<int>          $weekdays
     * @param  array<string,bool>  $occupiedDates
     */
    public static function prevRecurringDate(
        Carbon $beforeDate,
        array $weekdays,
        array $occupiedDates,
        string $minExclusiveDate
    ): string {
        $cursor = $beforeDate->copy()->subDay()->startOfDay();
        $guard = 0;
        while ($guard < 3660) {
            $guard++;
            $isoDow = (int) $cursor->dayOfWeekIso;
            $date = $cursor->toDateString();
            if ($date <= $minExclusiveDate) {
                break;
            }
            if (in_array($isoDow, $weekdays, true) && !isset($occupiedDates[$date])) {
                return $date;
            }
            $cursor->subDay();
        }
        throw new \InvalidArgumentException('撤銷請假失敗：找不到可回復的上課日期');
    }

    public static function syncLearningRecordSessionDate(ClassSession $session): void
    {
        LearningRecord::where('ClassSessionID', (int) $session->id)->update([
            'SessionDate' => $session->SessionDate ? substr((string) $session->SessionDate, 0, 10) : null,
            'StartTime'   => $session->StartTime ? substr((string) $session->StartTime, 0, 5) : null,
            'EndTime'     => $session->EndTime ? substr((string) $session->EndTime, 0, 5) : null,
        ]);
    }

    /**
     * Remap StartTime/EndTime to the course contract slot for the session's
     * target date weekday. Contract-exception / makeup rows keep custom times.
     */
    public static function alignSessionTimesToContractWeekday(
        ?StudentClass $course,
        ClassSession $session,
        string $targetDate
    ): void {
        if (!$course) {
            return;
        }
        if (
            Schema::hasColumn('ClassSession', 'IsContractException')
            && !empty($session->IsContractException)
        ) {
            return;
        }

        $times = self::resolveContractSlotTimes($course, $targetDate);
        if ($times['start'] === '' || $times['end'] === '') {
            return;
        }
        $session->StartTime = $times['start'];
        $session->EndTime = $times['end'];
    }

    /**
     * @return array{start:string,end:string}
     */
    public static function resolveContractSlotTimes(StudentClass $course, string $date): array
    {
        $times = app(SessionProjectionReadService::class)
            ->resolveSlotTimesForCourseDate($course, $date);
        $start = substr((string) $times['start'], 0, 5);
        $end = substr((string) $times['end'], 0, 5);
        if ($start === '' || $end === '') {
            return ['start' => '', 'end' => ''];
        }

        return [
            'start' => strlen($start) === 5 ? $start . ':00' : $start,
            'end' => strlen($end) === 5 ? $end . ':00' : $end,
        ];
    }

    /** @param  array<string,bool>  $dateMap */
    public static function maxDateKey(array $dateMap): ?string
    {
        if (empty($dateMap)) {
            return null;
        }
        $keys = array_keys($dateMap);
        sort($keys, SORT_STRING);
        return end($keys) ?: null;
    }

    public static function appendNote($existing, string $suffix): string
    {
        $base = trim((string) ($existing ?? ''));
        if ($base === '') {
            return $suffix;
        }
        if (str_contains($base, $suffix)) {
            return $base;
        }
        return $base . '; ' . $suffix;
    }

    public static function buildAutoExtendedNote(string $leaveDate, int $leaveSessionId): string
    {
        return self::NOTE_AUTO_EXTENDED
            . ':ld=' . Carbon::parse($leaveDate)->toDateString()
            . ':ls=' . $leaveSessionId;
    }

    /**
     * Legacy SHIFT fingerprint: next natural recurrence after leave is empty,
     * but a later non-cancelled billable session exists (silent vacated week).
     *
     * @param  \Illuminate\Support\Collection<int, ClassSession>  $sessions
     */
    public static function detectLegacyVacatedWeekPattern($sessions, string $leaveDate, StudentClass $course): bool
    {
        $normalizedLeaveDate = Carbon::parse($leaveDate)->toDateString();
        $weekdays = self::resolveCourseWeekdays(
            $course,
            (int) Carbon::parse($normalizedLeaveDate)->dayOfWeekIso
        );
        $naturalNext = self::nextRecurringDate(
            Carbon::parse($normalizedLeaveDate)->startOfDay(),
            $weekdays,
            [$normalizedLeaveDate => true]
        );
        $hasNatural = $sessions->contains(function ($s) use ($naturalNext) {
            $st = strtolower((string) ($s->getAttribute('Status') ?? ''));
            return Carbon::parse($s->SessionDate)->toDateString() === $naturalNext
                && $st !== 'cancelled';
        });
        if ($hasNatural) {
            return false;
        }
        return (bool) $sessions->first(function ($s) use ($naturalNext) {
            $st = strtolower((string) ($s->getAttribute('Status') ?? ''));
            $d = Carbon::parse($s->SessionDate)->toDateString();
            return $d > $naturalNext && !in_array($st, self::NON_BILLABLE_STATUSES, true);
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ClassSession>  $sessions
     */
    public static function findAppendedSessionForLeave($sessions, string $leaveDate, int $leaveSessionId): ?ClassSession
    {
        $normalizedLeaveDate = Carbon::parse($leaveDate)->toDateString();
        $own = self::ownTail($sessions, $leaveSessionId);
        if ($own || self::lessonsOnDate($sessions, $normalizedLeaveDate) > 1) {
            return $own; // #3914: with two lessons on the date, another lesson's tail is never this one's
        }
        $match = $sessions->first(function ($s) use ($normalizedLeaveDate, $leaveSessionId) {
            $note = (string) ($s->getAttribute('Note') ?? '');
            $st = strtolower((string) ($s->getAttribute('Status') ?? ''));
            if ($st !== 'scheduled' || !str_contains($note, self::NOTE_AUTO_EXTENDED)) {
                return false;
            }
            return str_contains($note, ':ld=' . $normalizedLeaveDate)
                || str_contains($note, ':ls=' . $leaveSessionId)
                || trim($note) === self::NOTE_AUTO_EXTENDED;
        });
        return $match instanceof ClassSession ? $match : null;
    }

    public static function isSafeToRemoveAutoAppend(ClassSession $session): bool
    {
        $st = strtolower((string) ($session->getAttribute('Status') ?? ''));
        $note = (string) ($session->getAttribute('Note') ?? '');
        if ($st !== 'scheduled' || !str_contains($note, self::NOTE_AUTO_EXTENDED)) {
            return false;
        }
        if (LearningRecord::query()
            ->where('ClassSessionID', (int) $session->id)
            ->whereNull('VoidedAt')
            ->exists()) {
            return false;
        }
        if (StudentSignIn::query()
            ->where('ClassSessionID', (int) $session->id)
            ->whereNull('VoidedAt')
            ->exists()) {
            return false;
        }
        return true;
    }

    public static function fetchCourseSessionRows(int $courseId): array
    {
        return DB::table('ClassSession as cs')
            ->leftJoin('LearningRecord as lr', 'lr.ClassSessionID', '=', 'cs.id')
            ->where('cs.StudentClassID', $courseId)
            ->select([
                'cs.id',
                'cs.StudentClassID',
                'cs.SessionDate',
                'cs.StartTime',
                'cs.EndTime',
                'cs.Status',
                'lr.id as learning_record_id',
                'lr.Status as learning_record_status',
            ])
            ->orderBy('cs.SessionDate', 'asc')
            ->orderBy('cs.StartTime', 'asc')
            ->orderBy('cs.id', 'asc')
            ->get()
            ->map(function ($row) {
                return [
                    'id'                     => (int) $row->id,
                    'StudentClassID'         => (int) $row->StudentClassID,
                    'SessionDate'            => $row->SessionDate ? substr((string) $row->SessionDate, 0, 10) : null,
                    'StartTime'              => $row->StartTime ? substr((string) $row->StartTime, 0, 5) : null,
                    'EndTime'                => $row->EndTime ? substr((string) $row->EndTime, 0, 5) : null,
                    'Status'                 => (string) ($row->Status ?? ''),
                    'learning_record_id'     => $row->learning_record_id !== null ? (int) $row->learning_record_id : null,
                    'learning_record_status' => $row->learning_record_status ?? 'missing',
                ];
            })
            ->values()
            ->all();
    }
}
