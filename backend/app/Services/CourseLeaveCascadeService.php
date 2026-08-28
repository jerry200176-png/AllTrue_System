<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\LearningRecord;
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
     * shift subsequent scheduled sessions forward, and append one new session
     * to keep the total count intact.
     *
     * Must be called inside a DB::transaction.
     *
     * @return array{0:array,1:?string,2:string}  [session_rows, extended_end_date, leave_session_date]
     * @throws \InvalidArgumentException
     */
    public static function applyLeaveCascade(int $courseId, string $leaveDate): array
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

        // Find the target session: accept scheduled/leave (but not cancelled/leave_adjusted)
        $leaveSession = $sessions->first(function ($session) use ($normalizedLeaveDate) {
            $status = strtolower((string) ($session->Status ?? ''));
            return Carbon::parse($session->SessionDate)->toDateString() === $normalizedLeaveDate
                && !in_array($status, ['cancelled', 'leave_adjusted'], true);
        });
        if (!$leaveSession) {
            throw new \InvalidArgumentException('找不到可請假的堂次');
        }

        $leaveStatus = strtolower((string) ($leaveSession->Status ?? ''));

        if (in_array($leaveStatus, ['completed', 'attended'], true)) {
            throw new \InvalidArgumentException('已完成堂次不可請假（如需補請假請使用 retro-leave）');
        }

        // KEEP policy idempotency: leave + provenance append for THIS leave.
        if ($leaveStatus === 'leave') {
            $leaveSessionId = (int) $leaveSession->id;
            $hasAppend = $sessions->contains(function ($s) use ($normalizedLeaveDate, $leaveSessionId) {
                $note = (string) ($s->Note ?? '');
                $st = strtolower((string) ($s->Status ?? ''));
                if ($st !== 'scheduled' || !str_contains($note, self::NOTE_AUTO_EXTENDED)) {
                    return false;
                }
                return str_contains($note, ':ld=' . $normalizedLeaveDate)
                    || str_contains($note, ':ls=' . $leaveSessionId);
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

        return [$rows, $extendedEndDate, $leaveSessionDate];
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
     *   append: string,
     *   extended_end_date: string
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
        $sessionRows = [];
        foreach ($sessions as $s) {
            $sessionRows[] = [
                'id' => (int) $s->id,
                'date' => Carbon::parse($s->SessionDate)->toDateString(),
                'status' => (string) ($s->Status ?? ''),
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
        $plan = self::computeAppendOnlyPlan($sessionRows, $leaveSessionDate, $weekdays, (int) $leaveSession->id, $purchased);
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
     * @param  list<array{id?:int|string|null,date:string,status?:string|null}>  $sessionRows
     * @param  array<int>  $weekdays
     * @return array{moves: list, vacated: list, append: ?string, extended_end_date: ?string, append_count: int}
     */
    public static function computeAppendOnlyPlan(
        array $sessionRows,
        string $leaveDate,
        array $weekdays,
        ?int $leaveSessionId = null,
        int $purchasedSessions = 0
    ): array {
        $normalizedLeaveDate = Carbon::parse($leaveDate)->toDateString();
        $weekdays = array_values(array_unique(array_map('intval', $weekdays)));
        if ($weekdays === []) {
            $weekdays = [(int) Carbon::parse($normalizedLeaveDate)->dayOfWeekIso];
        }
        $occupiedDates = [];
        $billable = 0;
        foreach ($sessionRows as $row) {
            $id = isset($row['id']) ? (int) $row['id'] : 0;
            $date = Carbon::parse((string) $row['date'])->toDateString();
            $status = strtolower((string) ($row['status'] ?? ''));
            $occupiedDates[$date] = true;
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
            $append = self::nextRecurringDate(Carbon::parse($latest)->startOfDay(), $weekdays, $occupiedDates);
            $occupiedDates[$append] = true;
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
        $scheduleMode = strtolower((string) ($course->ScheduleMode ?? 'count'));
        if ($scheduleMode === 'date' && $purchased <= 0) {
            $rows = self::fetchCourseSessionRows($courseId);
            $end = ClassSession::query()->where('StudentClassID', $courseId)->max('SessionDate');
            return [$rows, $end ? substr((string) $end, 0, 10) : null, null];
        }
        $sessionRows = $sessions->map(fn ($s) => [
            'id' => (int) $s->id,
            'date' => Carbon::parse($s->SessionDate)->toDateString(),
            'status' => (string) ($s->Status ?? ''),
        ])->all();
        $plan = self::computeAppendOnlyPlan(
            $sessionRows,
            $normalizedLeaveDate,
            $weekdays,
            (int) $leaveSession->id,
            $purchased
        );
        $newSession = null;
        if ((int) $plan['append_count'] > 0 && $plan['append']) {
            $appendDate = $plan['append'];
            $appendTimes = self::resolveContractSlotTimes($course, $appendDate);
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
            ])['session'];
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
     * @return array{0:array,1:?string,2:string,3?:string}
     */
    public static function undoLeaveCascade(int $courseId, string $leaveDate): array
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

        $leaveSession = $sessions->first(function ($session) use ($normalizedLeaveDate) {
            $sessionDate = Carbon::parse($session->SessionDate)->toDateString();
            $status = strtolower((string) ($session->Status ?? ''));
            return $sessionDate === $normalizedLeaveDate && $status === 'leave';
        });
        if (!$leaveSession) {
            throw new \InvalidArgumentException('找不到可撤銷的請假堂次');
        }

        if ((string) ($course->scheduling_policy ?? 'auto_recurrence') === 'manual_occurrence') {
            self::restoreLeaveSession($leaveSession);
            $end = ClassSession::where('StudentClassID', $courseId)->max('SessionDate');
            return [self::fetchCourseSessionRows($courseId), $end ? Carbon::parse($end)->toDateString() : null, $normalizedLeaveDate];
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

        // A leave does not always create a tail: paused courses, date-based
        // courses without a purchased-session commitment, and already
        // over-provisioned count courses intentionally keep their current
        // schedule. Undo must therefore restore the target session directly
        // instead of treating the missing tail as corruption.
        if (self::leaveCascadeDoesNotRequireTail($course, $sessions, $leaveSession, $normalizedLeaveDate)) {
            self::restoreLeaveSession($leaveSession);
            $end = $course->EndDate ? Carbon::parse($course->EndDate)->toDateString() : null;
            return [self::fetchCourseSessionRows($courseId), $end, $normalizedLeaveDate];
        }

        $appendedSession = $sessions
            ->filter(function ($session) use ($normalizedLeaveDate) {
                $sessionDate = Carbon::parse($session->SessionDate)->toDateString();
                $status = strtolower((string) ($session->Status ?? ''));
                $note = strtolower((string) ($session->Note ?? ''));
                return $sessionDate >= $normalizedLeaveDate
                    && $status === 'scheduled'
                    && str_contains($note, self::NOTE_AUTO_EXTENDED);
            })
            ->sortByDesc('SessionDate')
            ->first();
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
        $isLegacyShift = str_contains($leaveNote, self::NOTE_POLICY_SHIFT)
            || str_contains($appendNote, self::NOTE_POLICY_SHIFT);

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

        $appendedSession->delete();

        $extendedEndDate = ClassSession::where('StudentClassID', $courseId)
            ->max('SessionDate');
        if ($extendedEndDate) {
            DB::table('StudentClass')
                ->where('ID', $courseId)
                ->update(['EndDate' => substr((string) $extendedEndDate, 0, 10)]);
        }

        $rows = self::fetchCourseSessionRows($courseId);
        return [$rows, $extendedEndDate ? substr((string) $extendedEndDate, 0, 10) : null, $normalizedLeaveDate];
    }

    // ── helpers ──────────────────────────────────────────────────────

    /**
     * Restore the target session and only the leave-cascade artifacts owned by
     * this service. Keeping this operation centralized prevents one undo path
     * from resurrecting unrelated manually-voided attendance/evaluation rows.
     */
    private static function restoreLeaveSession(ClassSession $leaveSession): void
    {
        $leaveSession->Status = 'scheduled';
        $leaveSession->Note = self::appendNote($leaveSession->Note, self::NOTE_REVERT_TO_SCHEDULED);
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
    private static function leaveCascadeDoesNotRequireTail(
        StudentClass $course,
        $sessions,
        ClassSession $leaveSession,
        string $leaveDate
    ): bool {
        if ((string) ($course->scheduling_policy ?? 'auto_recurrence') === 'manual_occurrence') {
            return true;
        }
        if ((int) ($course->Stop ?? 0) === 1) {
            return true;
        }

        $purchased = max(0, (int) ($course->SessionCount ?? 0));
        $scheduleMode = strtolower((string) ($course->ScheduleMode ?? 'count'));
        if ($scheduleMode === 'date' && $purchased <= 0) {
            return true;
        }

        $weekdays = self::resolveCourseWeekdays(
            $course,
            (int) Carbon::parse($leaveSession->SessionDate)->dayOfWeekIso
        );
        $sessionRows = $sessions->map(fn ($session) => [
            'id' => (int) $session->id,
            'date' => Carbon::parse($session->SessionDate)->toDateString(),
            'status' => (string) ($session->Status ?? ''),
        ])->all();

        return (int) self::computeAppendOnlyPlan(
            $sessionRows,
            $leaveDate,
            $weekdays,
            (int) $leaveSession->id,
            $purchased
        )['append_count'] === 0;
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
