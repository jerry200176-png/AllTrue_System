<?php

namespace App\Services;

use App\Exceptions\SlotOccupiedException;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leave / course-pause cascade authority.
 *
 * Founder Decision 2026-07-26:
 * - Default single-session leave = KEEP_FUTURE_DATES_APPEND_TAIL
 *   (mark leave, keep all future SessionDate/times, append tail only).
 * - SHIFT_FUTURE_DATES_APPEND_TAIL is an explicit pause/shift capability only;
 *   never the default for ordinary leave.
 */
class CourseLeaveCascadeService
{
    public const NOTE_LEAVE = 'leave';
    public const NOTE_AUTO_EXTENDED = 'auto-extended-after-leave';
    public const NOTE_REVERT_TO_SCHEDULED = 'revert-to-scheduled';
    public const NOTE_POLICY_SHIFT = 'leave-policy-shift';

    /** Ordinary count-based leave: keep future dates, append missing billable tails. */
    public const POLICY_KEEP_FUTURE_DATES_APPEND_TAIL = 'KEEP_FUTURE_DATES_APPEND_TAIL';

    /** Explicit course pause / whole-course shift — not ordinary leave. */
    public const POLICY_SHIFT_FUTURE_DATES_APPEND_TAIL = 'SHIFT_FUTURE_DATES_APPEND_TAIL';

    public const DEFAULT_LEAVE_POLICY = self::POLICY_KEEP_FUTURE_DATES_APPEND_TAIL;

    /** Statuses that do not consume purchased session quota / billable ordinal. */
    public const NON_BILLABLE_STATUSES = ['cancelled', 'leave', 'leave_adjusted', 'excused'];

    /**
     * Ordinary leave: mark target leave, keep future dates, append tail if needed.
     *
     * Must be called inside a DB::transaction.
     *
     * @return array{0:array,1:?string,2:string}  [session_rows, extended_end_date, leave_session_date]
     * @throws \InvalidArgumentException
     */
    public static function applyLeaveCascade(int $courseId, string $leaveDate): array
    {
        return self::applyLeaveWithPolicy(
            $courseId,
            $leaveDate,
            self::POLICY_KEEP_FUTURE_DATES_APPEND_TAIL
        );
    }

    /**
     * Explicit pause/shift: move subsequent scheduled sessions +1 recurrence and append.
     * Not wired to ordinary leave UI — reserved for pause/suspend domain.
     *
     * Must be called inside a DB::transaction.
     *
     * @return array{0:array,1:?string,2:string}
     */
    public static function applyExplicitCoursePauseShift(int $courseId, string $leaveDate): array
    {
        return self::applyLeaveWithPolicy(
            $courseId,
            $leaveDate,
            self::POLICY_SHIFT_FUTURE_DATES_APPEND_TAIL
        );
    }

    /**
     * @return array{0:array,1:?string,2:string}
     */
    public static function applyLeaveWithPolicy(int $courseId, string $leaveDate, string $policy): array
    {
        if (!in_array($policy, [
            self::POLICY_KEEP_FUTURE_DATES_APPEND_TAIL,
            self::POLICY_SHIFT_FUTURE_DATES_APPEND_TAIL,
        ], true)) {
            throw new \InvalidArgumentException('不支援的請假／順延政策');
        }

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

        if ($leaveStatus === 'leave') {
            if (self::findAppendedSessionForLeave($sessions, $normalizedLeaveDate, (int) $leaveSession->id)) {
                throw new \InvalidArgumentException('該堂已完成請假登記與尾堂補上');
            }
            // Half-written legacy: leave marked but no append yet — repair by continuing.
        }

        $hasApprovedRecord = LearningRecord::where('ClassSessionID', $leaveSession->id)
            ->active()
            ->where('Status', 'approved')
            ->exists();
        if ($hasApprovedRecord) {
            throw new \InvalidArgumentException('該堂已有核准評量，無法改為請假');
        }

        $leaveSessionDate = Carbon::parse($leaveSession->SessionDate)->toDateString();

        LearningRecord::where('ClassSessionID', (int) $leaveSession->id)
            ->active()
            ->update([
                'VoidedAt'       => now(),
                'VoidedByUserID' => null,
                'VoidReason'     => '一般請假',
            ]);
        StudentSignIn::where('ClassSessionID', (int) $leaveSession->id)
            ->active()
            ->update([
                'VoidedAt'       => now(),
                'VoidedByUserID' => null,
                'VoidReason'     => '一般請假',
            ]);

        if ($leaveStatus !== 'leave') {
            $leaveSession->Status = 'leave';
            $leaveSession->Note = self::appendNote($leaveSession->Note, self::NOTE_LEAVE);
            if ($policy === self::POLICY_SHIFT_FUTURE_DATES_APPEND_TAIL) {
                $leaveSession->Note = self::appendNote($leaveSession->Note, self::NOTE_POLICY_SHIFT);
            }
            $leaveSession->save();
        }

        if ($policy === self::POLICY_SHIFT_FUTURE_DATES_APPEND_TAIL) {
            [$rows, $extendedEndDate] = self::shiftAndAppendAfterLeave($courseId, $leaveSessionDate, $leaveSession);
        } else {
            [$rows, $extendedEndDate] = self::appendTailAfterLeave($courseId, $leaveSessionDate, $leaveSession);
        }

        return [$rows, $extendedEndDate, $leaveSessionDate];
    }

    /**
     * Dry-run leave plan for UI impact preview.
     *
     * @return array{
     *   policy: string,
     *   leave_session_date: string,
     *   weekdays: list<int>,
     *   moves: list<array{from:string,to:string,id:?int}>,
     *   vacated: list<string>,
     *   append: ?string,
     *   extended_end_date: ?string,
     *   future_dates_unchanged: bool,
     *   next_billable_session: ?array{date:string,ordinal:int,id:?int}
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
            $plan = self::computeShiftPlan(
                $sessionRows,
                $leaveSessionDate,
                $weekdays,
                (int) $leaveSession->id
            );
            $nextBillable = self::resolveNextBillableAfterLeave($sessionRows, $leaveSessionDate, true, $plan['moves']);
            return [
                'policy' => $policy,
                'leave_session_date' => $leaveSessionDate,
                'weekdays' => $weekdays,
                'moves' => $plan['moves'],
                'vacated' => $plan['vacated'],
                'append' => $plan['append'],
                'extended_end_date' => $plan['extended_end_date'],
                'future_dates_unchanged' => false,
                'next_billable_session' => $nextBillable,
            ];
        }

        $plan = self::computeAppendOnlyPlan(
            $sessionRows,
            $leaveSessionDate,
            $weekdays,
            (int) $leaveSession->id,
            self::resolvePurchasedSessionCount($course)
        );
        $nextBillable = self::resolveNextBillableAfterLeave($sessionRows, $leaveSessionDate, false, []);

        return [
            'policy' => self::POLICY_KEEP_FUTURE_DATES_APPEND_TAIL,
            'leave_session_date' => $leaveSessionDate,
            'weekdays' => $weekdays,
            'moves' => [],
            'vacated' => [],
            'append' => $plan['append'],
            'extended_end_date' => $plan['extended_end_date'],
            'future_dates_unchanged' => true,
            'next_billable_session' => $nextBillable,
        ];
    }

    /**
     * Append-only date plan (ordinary leave). Never vacates or moves dates.
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
        // Leave target becomes non-billable.
        $occupiedDates[$normalizedLeaveDate] = true;

        // One ordinary leave → at most one tail append. Do not bulk-heal
        // pre-existing under-materialization (that belongs to forward-generate).
        $append = null;
        $appendCount = 0;
        $needsAppend = $purchasedSessions <= 0
            ? true
            : ($billable < $purchasedSessions);
        if ($needsAppend) {
            $appendCount = 1;
            $latest = self::maxDateKey($occupiedDates) ?: $normalizedLeaveDate;
            $append = self::nextRecurringDate(
                Carbon::parse($latest)->startOfDay(),
                $weekdays,
                $occupiedDates
            );
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
     * Explicit pause/shift plan (legacy vacated-week semantics).
     *
     * @param  list<array{id?:int|string|null,date:string,status?:string|null}>  $sessionRows
     * @param  array<int>  $weekdays
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
     * Keep future dates; append missing billable sessions at the tail.
     *
     * @return array{0:array,1:?string}
     */
    public static function appendTailAfterLeave(int $courseId, string $leaveDate, ClassSession $leaveSession): array
    {
        $course = StudentClass::where('ID', $courseId)->first();
        $sessions = ClassSession::where('StudentClassID', $courseId)
            ->orderBy('SessionDate', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $normalizedLeaveDate = Carbon::parse($leaveDate)->toDateString();
        $weekdays = self::resolveCourseWeekdays(
            $course,
            Carbon::parse($leaveSession->SessionDate)->dayOfWeekIso
        );

        $purchased = self::resolvePurchasedSessionCount($course);
        $scheduleMode = strtolower((string) ($course->ScheduleMode ?? 'count'));

        // Date-based courses: mark leave only — do not invent count tails.
        if ($scheduleMode === 'date' && $purchased <= 0) {
            $rows = self::fetchCourseSessionRows($courseId);
            $end = ClassSession::where('StudentClassID', $courseId)->max('SessionDate');
            return [$rows, $end ? substr((string) $end, 0, 10) : null];
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
            $purchased > 0 ? $purchased : self::countBillableSessions($sessionRows, (int) $leaveSession->id) + 1
        );

        $materializer = app(ClassSessionMaterializationService::class);
        $appendCount = (int) ($plan['append_count'] ?? 0);
        $occupied = [];
        foreach ($sessions as $s) {
            $occupied[Carbon::parse($s->SessionDate)->toDateString()] = true;
        }
        $occupied[$normalizedLeaveDate] = true;

        $latest = self::maxDateKey($occupied) ?: $normalizedLeaveDate;
        $lastCreated = null;
        for ($i = 0; $i < $appendCount; $i++) {
            $appendDate = self::nextRecurringDate(Carbon::parse($latest)->startOfDay(), $weekdays, $occupied);
            $appendTimes = self::resolveContractSlotTimes($course, $appendDate);
            $appendStart = $appendTimes['start'] !== '' ? $appendTimes['start'] : $leaveSession->StartTime;
            $appendEnd = $appendTimes['end'] !== '' ? $appendTimes['end'] : $leaveSession->EndTime;

            try {
                $materializer->assertSlotAvailable($courseId, $appendDate, $appendStart);
            } catch (SlotOccupiedException $e) {
                throw new \InvalidArgumentException(
                    '尾堂時段衝突，需人工審核後再補堂：' . $e->getMessage(),
                    0,
                    $e
                );
            }

            $result = $materializer->upsertSlot([
                'StudentClassID' => $courseId,
                'SessionDate'    => $appendDate,
                'StartTime'      => $appendStart,
                'EndTime'        => $appendEnd,
                'Status'         => 'scheduled',
                'Note'           => self::buildAutoExtendedNote($normalizedLeaveDate, (int) $leaveSession->id),
            ]);
            if (!$result['created']) {
                $existing = $result['session'];
                $existingStatus = strtolower((string) ($existing->Status ?? ''));
                if ($existingStatus === 'cancelled') {
                    $existing->Status = 'scheduled';
                    $existing->Note = self::buildAutoExtendedNote($normalizedLeaveDate, (int) $leaveSession->id);
                    $existing->save();
                    $lastCreated = $existing;
                } else {
                    throw new \InvalidArgumentException(
                        '尾堂時段已被占用，需人工審核（date=' . $appendDate . '）'
                    );
                }
            } else {
                $lastCreated = $result['session'];
            }
            if ($lastCreated) {
                self::syncLearningRecordSessionDate($lastCreated);
            }
            $occupied[$appendDate] = true;
            $latest = $appendDate;
        }

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
     * Explicit pause/shift write path (legacy). Used only by applyExplicitCoursePauseShift
     * and retro-leave until retro is migrated to KEEP policy.
     *
     * @return array{0:array,1:?string}
     */
    public static function shiftAndAppendAfterLeave(int $courseId, string $leaveDate, ClassSession $leaveSession): array
    {
        $course = StudentClass::where('ID', $courseId)->first();
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
                self::buildAutoExtendedNote($normalizedLeaveDate, (int) $leaveSession->id),
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
     * Undo one leave. KEEP policy: restore leave + remove safe provenance append.
     * Legacy SHIFT leaves (vacated week + shift note / pattern): reverse-shift then remove append.
     *
     * @return array{0:array,1:?string,2:string}
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

        $appendedSession = self::findAppendedSessionForLeave(
            $sessions,
            $normalizedLeaveDate,
            (int) $leaveSession->id
        );
        if (!$appendedSession) {
            throw new \InvalidArgumentException('找不到可回復的請假尾堂（需人工審核）');
        }

        if (!self::isSafeToRemoveAutoAppend($appendedSession)) {
            throw new \InvalidArgumentException('尾堂已被使用或修改，需人工審核後才能撤銷');
        }

        $leaveNote = strtolower((string) ($leaveSession->Note ?? ''));
        $appendNote = strtolower((string) ($appendedSession->Note ?? ''));
        $isLegacyShift = str_contains($leaveNote, self::NOTE_POLICY_SHIFT)
            || str_contains($appendNote, self::NOTE_POLICY_SHIFT)
            || self::detectLegacyVacatedWeekPattern($sessions, $normalizedLeaveDate, $course);

        if ($isLegacyShift) {
            return self::undoLegacyShiftLeave(
                $course,
                $sessions,
                $leaveSession,
                $appendedSession,
                $normalizedLeaveDate
            );
        }

        $leaveSession->Status = 'scheduled';
        $leaveSession->Note = self::appendNote($leaveSession->Note, self::NOTE_REVERT_TO_SCHEDULED);
        $leaveSession->save();

        LearningRecord::where('ClassSessionID', (int) $leaveSession->id)
            ->where('VoidReason', '一般請假')
            ->update([
                'VoidedAt' => null,
                'VoidedByUserID' => null,
                'VoidReason' => null,
            ]);
        StudentSignIn::where('ClassSessionID', (int) $leaveSession->id)
            ->where('VoidReason', '一般請假')
            ->update([
                'VoidedAt' => null,
                'VoidedByUserID' => null,
                'VoidReason' => null,
            ]);

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

    /**
     * @param  Collection<int, ClassSession>  $sessions
     * @return array{0:array,1:?string,2:string}
     */
    private static function undoLegacyShiftLeave(
        StudentClass $course,
        Collection $sessions,
        ClassSession $leaveSession,
        ClassSession $appendedSession,
        string $normalizedLeaveDate
    ): array {
        $courseId = (int) $course->ID;
        $weekdays = self::resolveCourseWeekdays(
            $course,
            (int) Carbon::parse($normalizedLeaveDate)->dayOfWeekIso
        );

        $movableSessions = $sessions
            ->filter(function ($session) use ($normalizedLeaveDate, $appendedSession, $leaveSession) {
                if ((int) $session->id === (int) $appendedSession->id) {
                    return false;
                }
                if ((int) $session->id === (int) $leaveSession->id) {
                    return false;
                }
                $status = strtolower((string) ($session->Status ?? ''));
                if ($status !== 'scheduled') {
                    return false;
                }
                $sessionDate = Carbon::parse($session->SessionDate)->toDateString();
                return $sessionDate > $normalizedLeaveDate;
            })
            ->values();

        $occupiedDates = [];
        foreach ($sessions as $session) {
            $sessionId = (int) $session->id;
            if ($sessionId === (int) $appendedSession->id) {
                continue;
            }
            $isMovable = $movableSessions->contains(fn ($s) => (int) $s->id === $sessionId);
            if ($isMovable) {
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

        $leaveSession->Status = 'scheduled';
        $leaveSession->Note = self::appendNote($leaveSession->Note, self::NOTE_REVERT_TO_SCHEDULED);
        $leaveSession->save();

        LearningRecord::where('ClassSessionID', (int) $leaveSession->id)
            ->where('VoidReason', '一般請假')
            ->update([
                'VoidedAt' => null,
                'VoidedByUserID' => null,
                'VoidReason' => null,
            ]);
        StudentSignIn::where('ClassSessionID', (int) $leaveSession->id)
            ->where('VoidReason', '一般請假')
            ->update([
                'VoidedAt' => null,
                'VoidedByUserID' => null,
                'VoidReason' => null,
            ]);

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

    public static function buildAutoExtendedNote(string $leaveDate, int $leaveSessionId): string
    {
        return self::NOTE_AUTO_EXTENDED . ':ld=' . $leaveDate . ':ls=' . $leaveSessionId;
    }

    /**
     * @param  Collection<int, ClassSession>|iterable<ClassSession>  $sessions
     */
    public static function findAppendedSessionForLeave($sessions, string $leaveDate, int $leaveSessionId): ?ClassSession
    {
        $normalizedLeaveDate = Carbon::parse($leaveDate)->toDateString();
        $matched = null;
        $fallback = null;
        foreach ($sessions as $session) {
            $status = strtolower((string) ($session->Status ?? ''));
            $note = (string) ($session->Note ?? '');
            if ($status !== 'scheduled' || !str_contains($note, self::NOTE_AUTO_EXTENDED)) {
                continue;
            }
            $sessionDate = Carbon::parse($session->SessionDate)->toDateString();
            if ($sessionDate < $normalizedLeaveDate) {
                continue;
            }
            if (str_contains($note, ':ls=' . $leaveSessionId)
                || str_contains($note, ':ld=' . $normalizedLeaveDate)) {
                if ($matched === null
                    || Carbon::parse($session->SessionDate)->gt(Carbon::parse($matched->SessionDate))) {
                    $matched = $session;
                }
                continue;
            }
            if ($fallback === null
                || Carbon::parse($session->SessionDate)->gt(Carbon::parse($fallback->SessionDate))) {
                $fallback = $session;
            }
        }
        return $matched ?: $fallback;
    }

    public static function isSafeToRemoveAutoAppend(ClassSession $session): bool
    {
        $status = strtolower((string) ($session->Status ?? ''));
        if ($status !== 'scheduled') {
            return false;
        }
        if (!str_contains((string) ($session->Note ?? ''), self::NOTE_AUTO_EXTENDED)) {
            return false;
        }
        $hasActiveAttendance = StudentSignIn::where('ClassSessionID', (int) $session->id)
            ->whereNull('VoidedAt')
            ->exists();
        if ($hasActiveAttendance) {
            return false;
        }
        $hasApprovedOrPendingLr = LearningRecord::where('ClassSessionID', (int) $session->id)
            ->active()
            ->whereIn('Status', ['approved', 'pending', 'changes_requested', 'submitted'])
            ->exists();
        if ($hasApprovedOrPendingLr) {
            return false;
        }
        return true;
    }

    /**
     * @param  Collection<int, ClassSession>  $sessions
     */
    public static function detectLegacyVacatedWeekPattern(
        Collection $sessions,
        string $leaveDate,
        StudentClass $course
    ): bool {
        $weekdays = self::resolveCourseWeekdays(
            $course,
            (int) Carbon::parse($leaveDate)->dayOfWeekIso
        );
        $naturalNext = self::nextRecurringDate(
            Carbon::parse($leaveDate)->startOfDay(),
            $weekdays,
            [$leaveDate => true]
        );
        $hasNaturalNext = $sessions->contains(function ($s) use ($naturalNext) {
            $st = strtolower((string) ($s->Status ?? ''));
            return Carbon::parse($s->SessionDate)->toDateString() === $naturalNext
                && !in_array($st, ['cancelled'], true);
        });
        if ($hasNaturalNext) {
            return false;
        }
        $later = $sessions->first(function ($s) use ($leaveDate, $naturalNext) {
            $st = strtolower((string) ($s->Status ?? ''));
            $d = Carbon::parse($s->SessionDate)->toDateString();
            return $d > $naturalNext
                && $d > $leaveDate
                && !in_array($st, ['cancelled', 'leave', 'leave_adjusted'], true);
        });
        return $later !== null;
    }

    public static function resolvePurchasedSessionCount(?StudentClass $course): int
    {
        if (!$course) {
            return 0;
        }
        return max(0, (int) ($course->SessionCount ?? 0));
    }

    /**
     * @param  list<array{id?:int|null,date:string,status?:string|null}>  $sessionRows
     */
    public static function countBillableSessions(array $sessionRows, ?int $excludeId = null): int
    {
        $n = 0;
        foreach ($sessionRows as $row) {
            $id = isset($row['id']) ? (int) $row['id'] : 0;
            if ($excludeId && $id === $excludeId) {
                continue;
            }
            $status = strtolower((string) ($row['status'] ?? ''));
            if (!in_array($status, self::NON_BILLABLE_STATUSES, true)) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * @param  list<array{id?:int|null,date:string,status?:string|null}>  $sessionRows
     * @param  list<array{from:string,to:string,id:?int}>  $moves
     * @return array{date:string,ordinal:int,id:?int}|null
     */
    public static function resolveNextBillableAfterLeave(
        array $sessionRows,
        string $leaveDate,
        bool $applyMoves,
        array $moves
    ): ?array {
        $dateById = [];
        foreach ($sessionRows as $row) {
            $id = isset($row['id']) ? (int) $row['id'] : 0;
            if ($id > 0) {
                $dateById[$id] = Carbon::parse((string) $row['date'])->toDateString();
            }
        }
        if ($applyMoves) {
            foreach ($moves as $m) {
                $id = (int) ($m['id'] ?? 0);
                if ($id > 0) {
                    $dateById[$id] = $m['to'];
                }
            }
        }

        $candidates = [];
        foreach ($sessionRows as $row) {
            $id = isset($row['id']) ? (int) $row['id'] : 0;
            $status = strtolower((string) ($row['status'] ?? ''));
            $date = $id > 0 && isset($dateById[$id])
                ? $dateById[$id]
                : Carbon::parse((string) $row['date'])->toDateString();
            if ($date === $leaveDate) {
                continue;
            }
            if (in_array($status, self::NON_BILLABLE_STATUSES, true)) {
                continue;
            }
            // After leave, this row becomes billable if it was scheduled/attended/etc.
            if ($date <= $leaveDate && !in_array($status, ['scheduled', 'attended', 'completed', 'late', 'present', 'absent'], true)) {
                // keep historical billable before leave for ordinal context
            }
            $candidates[] = ['id' => $id > 0 ? $id : null, 'date' => $date, 'status' => $status];
        }

        usort($candidates, fn ($a, $b) => strcmp($a['date'], $b['date']) ?: (($a['id'] ?? 0) <=> ($b['id'] ?? 0)));

        $ordinal = 0;
        $next = null;
        foreach ($candidates as $c) {
            if (in_array($c['status'], self::NON_BILLABLE_STATUSES, true)) {
                continue;
            }
            // Treat leave-date rows as already excluded above.
            $ordinal++;
            if ($c['date'] > $leaveDate && $next === null) {
                $next = ['date' => $c['date'], 'ordinal' => $ordinal, 'id' => $c['id']];
            }
        }
        return $next;
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
