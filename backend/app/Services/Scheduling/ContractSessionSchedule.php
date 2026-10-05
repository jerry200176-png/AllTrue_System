<?php

namespace App\Services\Scheduling;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\ScheduleAuditLog;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Services\ClassSessionContractReflowService;
use App\Services\ClassSessionMaterializationService;
use App\Services\ManualSessionBookingService;
use App\Services\SessionDeductionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contract schedule -> ClassSession rows (extracted from StudentClassController, ADR-003 / #966).
 *
 * Behaviour-preserving move: effective-date computation, weekly/count session
 * builders and the count-mode extend/cancel-excess reconciliation. Pure helpers
 * are static; the DB-writing reconcilers are instance methods.
 */
class ContractSessionSchedule
{
    public function __construct(private ClassSessionContractReflowService $contractSessionReflowService)
    {
    }

    /**
     * Dates with a cancelled session. A cancelled row beside a live row in the same slot (date + start) is a
     * duplicate/placeholder (e.g. reschedule collision), not a cancelled lesson.
     *
     * @return array<string, bool>
     */
    public static function cancelledDateSet(iterable $sessionRows): array
    {
        $cancelledSlots = [];
        $liveSlots = [];
        foreach ($sessionRows as $row) {
            if (!$row->SessionDate) {
                continue;
            }
            $slot = Carbon::parse($row->SessionDate)->toDateString() . '|' . substr((string) ($row->StartTime ?? ''), 0, 5);
            if (strtolower((string) ($row->Status ?? '')) === 'cancelled') {
                $cancelledSlots[$slot] = true;
            } else {
                $liveSlots[$slot] = true;
            }
        }
        $set = [];
        foreach (array_keys(array_diff_key($cancelledSlots, $liveSlots)) as $slot) {
            $set[strstr($slot, '|', true)] = true;
        }

        return $set;
    }

    /**
     * Contract-wide cancelled dates per class (any date, one query), for count-mode recurrence walks.
     *
     * @param  list<int>  $classIds
     * @return array<int, array<string, bool>>
     */
    public static function cancelledDatesByClass(array $classIds): array
    {
        if ($classIds === []) {
            return [];
        }
        $rows = ClassSession::query()
            ->whereIn('StudentClassID', $classIds)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('ClassSession as c2')
                ->whereColumn('c2.StudentClassID', 'ClassSession.StudentClassID')
                ->whereColumn('c2.SessionDate', 'ClassSession.SessionDate')
                ->whereRaw('LOWER(c2.Status) = ?', ['cancelled']))
            ->get(['StudentClassID', 'SessionDate', 'StartTime', 'Status']);

        $out = [];
        foreach ($rows->groupBy('StudentClassID') as $classId => $classRows) {
            $out[(int) $classId] = self::cancelledDateSet($classRows);
        }

        return $out;
    }

    /**
     * 月結制詳情顯示：以固定星期/時段推算查詢月份的日期，再與既有 ClassSession/schedules 合併。
     * Legacy 月結課可能沒有 EndDate/monthly_sessions，但仍有 week/time 契約欄位。
     *
     * @param  array<string, bool>  $leaveSet
     * @param  array<string, bool>  $scheduledSet
     * @param  array<string, bool>  $existingSet
     * @return array<int, string>
     */
    public static function computeMonthlyEffectiveSessionDates(StudentClass $class, string $rangeStart, string $rangeEnd, array $leaveSet, array $scheduledSet, array $existingSet): array
    {
        $start = self::normalizeDateString($class->StartDate ?? null) ?: $rangeStart;
        if ($start < $rangeStart) {
            $start = $rangeStart;
        }

        $end = self::normalizeDateString($class->EndDate ?? null) ?: $rangeEnd;
        if ($end > $rangeEnd) {
            $end = $rangeEnd;
        }
        if ($end < $start) {
            $end = $start;
        }

        $weekdays = [];
        $candidates = [
            ['week', 'time'],
            ['week1', 'time1'],
            ['week2', 'time2'],
            ['week3', 'time3'],
            ['week4', 'time4'],
            ['week5', 'time5'],
            ['week6', 'time6'],
        ];
        foreach ($candidates as [$weekField, $timeField]) {
            $weekday = (int) ($class->{$weekField} ?? 0);
            $time = trim((string) ($class->{$timeField} ?? ''));
            if ($weekday >= 1 && $weekday <= 7 && $time !== '') {
                $weekdays[$weekday] = true;
            }
        }

        $set = $existingSet;
        $cursor = Carbon::parse($start . ' 12:00:00');
        $last = Carbon::parse($end . ' 12:00:00');
        while ($cursor->lte($last)) {
            $ymd = $cursor->toDateString();
            $dow = (int) $cursor->dayOfWeekIso;
            if (isset($weekdays[$dow]) && !isset($leaveSet[$ymd])) {
                $set[$ymd] = true;
            }
            if (isset($scheduledSet[$ymd])) {
                $set[$ymd] = true;
            }
            $cursor->addDay();
        }

        foreach (array_keys($set) as $date) {
            if ($date < $rangeStart || $date > $rangeEnd) {
                unset($set[$date]);
            }
        }

        $list = array_keys($set);
        sort($list);
        return $list;
    }

    /**
     * 堂數制：從第一堂日開始，依排課星期與請假/調課/加課，算出恰好 N 堂的有效日期（請假會讓結束日往後推）。
     */
    public static function computeEffectiveSessionDates(string $startDate, int $n, array $daysOfWeek, array $leaveSet, array $scheduledSet, array $cancelledSet = []): array
    {
        $list = [];
        $d = Carbon::parse($startDate . ' 12:00:00');
        $end = $d->copy()->addYears(2);
        while ($d <= $end && count($list) < $n) {
            $ymd = $d->toDateString();
            $dow = $d->dayOfWeekIso;
            $isRegular = in_array($dow, $daysOfWeek, true);
            $isCancelled = isset($cancelledSet[$ymd]);
            $isLeave = isset($leaveSet[$ymd]) || $isCancelled;
            $isScheduledExtra = isset($scheduledSet[$ymd]);

            if ($isRegular && !$isLeave) {
                $list[] = $ymd;
            } elseif ($isScheduledExtra && !$isRegular && !$isCancelled) {
                // A same-day leave marker beside a schedule-only make-up keeps the make-up (R13/R114);
                // only a cancelled ClassSession on that date removes it.
                $list[] = $ymd;
            }
            $d->addDay();
        }
        return array_slice($list, 0, $n);
    }

    /**
     * When SessionCount is reduced, cancel scheduled sessions beyond the new limit.
     * Only cancels sessions whose Status is 'scheduled'; attended/late/absent sessions are untouched.
     *
     * NOTE: public for cross-controller invocation (e.g. CoursePackageController::update
     * synchronising SessionCount across shared-package members). Do not integrate through
     * StudentClassController::update() to avoid triggering Charge preserved_delta path.
     */
    public function cancelExcessScheduledSessions(int $classId, int $newCount): void
    {
        $allActive = ClassSession::where('StudentClassID', $classId)
            ->whereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted', 'excused'])
            ->orderBy('SessionDate')
            ->orderBy('StartTime')
            ->orderBy('id')
            ->get();

        $this->cancelExcessScheduledSessionsFromRows($allActive, $newCount);
    }

    /**
     * Cancel only unlocked scheduled rows outside the retained count. Callers
     * that already hold ClassSession locks use this variant so a correction and
     * concurrent attendance update cannot interleave.
     */
    public function cancelExcessScheduledSessionsFromRows($allActive, int $newCount): void
    {
        $allActive = self::purchasedQuotaSessionRows($allActive);

        if ($allActive->count() <= $newCount) {
            return;
        }

        $excess = $allActive->slice($newCount);
        foreach ($excess as $session) {
            if ($session->Status === 'scheduled') {
                $session->Status = 'cancelled';
                $session->save();
            }
        }
    }

    /**
     * Return future scheduled rows that would fall after a reduced count.
     * This is a read-only preflight; it deliberately does not cancel anything.
     *
     * @return array<int, array{session_id:int, session_date:string, start_time:string, end_time:string, status:string}>
     */
    public function scheduledSessionsBeyondCount(int $classId, int $newCount): array
    {
        $allActive = ClassSession::query()
            ->where('StudentClassID', $classId)
            ->where(function ($query) {
                $query->whereNull('Status')
                    ->orWhereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted', 'excused']);
            })
            ->orderBy('SessionDate')
            ->orderBy('StartTime')
            ->orderBy('id')
            ->get(['id', 'SessionDate', 'StartTime', 'EndTime', 'Status']);

        return self::scheduledSessionsBeyondCountFromRows($allActive, $newCount);
    }

    /** @return array<int, array{session_id:int, session_date:string, start_time:string, end_time:string, status:string}> */
    public static function scheduledSessionsBeyondCountFromRows($allActive, int $newCount): array
    {
        $today = Carbon::today()->toDateString();

        return self::purchasedQuotaSessionRows($allActive)->slice($newCount)
            ->filter(static function (ClassSession $session) use ($today): bool {
                return strtolower((string) $session->getAttribute('Status')) === 'scheduled'
                    && substr((string) $session->getAttribute('SessionDate'), 0, 10) >= $today;
            })
            ->map(static fn (ClassSession $session): array => [
                'session_id' => (int) $session->getKey(),
                'session_date' => substr((string) $session->getAttribute('SessionDate'), 0, 10),
                'start_time' => substr((string) $session->getAttribute('StartTime'), 0, 5),
                'end_time' => substr((string) $session->getAttribute('EndTime'), 0, 5),
                'status' => (string) $session->getAttribute('Status'),
            ])
            ->values()
            ->all();
    }

    /**
     * Keep the correction preview, its locked confirmation, and the write path
     * on the same purchased-session sequence. Historical cancellation and leave
     * rows remain in the token snapshot for stale-state detection, but never
     * consume a retained contract slot or shift which future reservation is
     * selected for cancellation.
     */
    public static function purchasedQuotaSessionRows($sessions)
    {
        return $sessions->filter(static function (ClassSession $session): bool {
            return !in_array(strtolower((string) $session->getAttribute('Status')), [
                'cancelled', 'leave', 'leave_adjusted', 'excused',
            ], true);
        })->values();
    }

    /**
     * 對齊堂數制契約序列：優先補中間缺口，再取消多出的尾端 scheduled 堂次。
     *
     * NOTE: public for cross-controller invocation (see cancelExcessScheduledSessions).
     * Must never delete/rebuild locked history; only create missing contract rows
     * and cancel unlocked scheduled rows outside the first N contract slots.
     */
    /**
     * @param  \Illuminate\Support\Collection<int, ClassSession>|null  $preloadedExistingSessions  When the
     *         caller already batch-fetched this class's existing ClassSession rows (e.g. TD-018-style
     *         batch preload across many classes in one query), pass them here to skip the per-class
     *         query. Pass null (default) to have this method query them itself, as before.
     */
    public function extendSessionsIfNeeded(StudentClass $studentClass, int $newCount, ?\Illuminate\Support\Collection $preloadedExistingSessions = null): void
    {
        if ((string) ($studentClass->scheduling_policy ?? 'auto_recurrence') === ManualSessionBookingService::POLICY) {
            return;
        }
        $classId = (int) $studentClass->ID;
        $nonQuotaStatuses = ['cancelled', 'leave', 'leave_adjusted', 'excused'];

        // 計算現有「實際堂次數」：排除 cancelled 與 leave/excused（請假不佔用購買額度）
        // 與 cancelExcessScheduledSessions 的計算口徑保持一致
        $slots = self::resolveScheduleSlotsForRebuild($studentClass);
        if (empty($slots)) {
            return;
        }

        $globalDur = max(30, (int) ($studentClass->SessionDuration ?? 120));
        $startFrom = self::normalizeDateString($studentClass->StartDate ?? null)
            ?: Carbon::today()->toDateString();
        $expectedSessions = self::buildSessionsForCount($classId, $startFrom, $newCount, $slots, $globalDur);
        if (empty($expectedSessions)) {
            return;
        }

        $expectedKeys = [];
        foreach ($expectedSessions as $session) {
            $date = self::normalizeDateString($session['SessionDate'] ?? null);
            $start = substr((string) ($session['StartTime'] ?? ''), 0, 5);
            if ($date && $start !== '') {
                $expectedKeys[$date . '|' . $start] = true;
            }
        }

        $existingSessions = $preloadedExistingSessions ?? ClassSession::where('StudentClassID', $classId)
            ->orderBy('SessionDate')
            ->orderBy('StartTime')
            ->orderBy('id')
            ->get();
        $existingQuotaKeys = [];
        $occupiedKeys = [];
        $currentCount = 0;
        $hasScheduledOutsideContract = false;
        $hasLockedQuotaSession = false;
        $hasLockedQuotaOutsideContract = false;
        foreach ($existingSessions as $session) {
            $date = self::normalizeDateString($session->SessionDate ?? null);
            $start = substr((string) ($session->StartTime ?? ''), 0, 5);
            if (!$date || $start === '') {
                continue;
            }
            $key = $date . '|' . $start;
            $status = strtolower((string) ($session->Status ?? ''));
            // Cancelled sessions must still occupy the calendar key; otherwise we
            // treat the slot as empty and refill from the contract sequence —
            // recreating the same date/time (classic "取消了又補回" on count-mode courses).
            $occupiedKeys[$key] = true;
            if (!in_array($status, $nonQuotaStatuses, true)) {
                $existingQuotaKeys[$key] = true;
                $currentCount++;
                if ($status !== 'scheduled') {
                    $hasLockedQuotaSession = true;
                }
            }
            if ($status === 'scheduled'
                && !isset($expectedKeys[$key])
                && empty($session->IsContractException)
            ) {
                $hasScheduledOutsideContract = true;
            } elseif (!in_array($status, $nonQuotaStatuses, true)
                && !isset($expectedKeys[$key])
            ) {
                $hasLockedQuotaOutsideContract = true;
            }
        }

        if ($currentCount >= $newCount && ($hasLockedQuotaSession || $hasLockedQuotaOutsideContract)) {
            SessionDeductionService::syncCounters($studentClass);
            return;
        }

        if ($currentCount >= $newCount && !$hasScheduledOutsideContract) {
            SessionDeductionService::syncCounters($studentClass);
            return;
        }

        $newSessions = [];
        $quotaShortfall = max(0, $newCount - $currentCount);
        foreach ($expectedSessions as $session) {
            $date = self::normalizeDateString($session['SessionDate'] ?? null);
            $start = substr((string) ($session['StartTime'] ?? ''), 0, 5);
            if (!$date || $start === '') {
                continue;
            }
            $key = $date . '|' . $start;
            if (isset($existingQuotaKeys[$key]) || isset($occupiedKeys[$key])) {
                continue;
            }
            $newSessions[] = $session;
            if ($quotaShortfall > 0 && count($newSessions) >= $quotaShortfall) {
                break;
            }
        }

        if ($currentCount < $newCount && empty($newSessions)) {
            // No contract gap was found; append after the last row as the legacy extension path.
            // The first item in buildSessionsForCount is intentionally allowed to be the
            // supplied start date (that is required for a course's首堂日).  That behaviour
            // is unsafe here: appendFrom is normally the day after an existing session,
            // so a Saturday course could otherwise materialize a Sunday/Monday tail.
            $lastSession = ClassSession::where('StudentClassID', $classId)
                ->orderByDesc('SessionDate')
                ->orderByDesc('StartTime')
                ->first();
            $appendFromDate = $lastSession
                ? Carbon::parse($lastSession->SessionDate)->addDay()->startOfDay()
                : Carbon::parse($startFrom)->startOfDay();
            $validWeekdays = array_map(
                fn (array $slot): int => self::isoWeekday((int) $slot['weekday']),
                $slots
            );
            $appendGuard = 0;
            while (!in_array((int) $appendFromDate->dayOfWeekIso, $validWeekdays, true)
                && $appendGuard++ < 14
            ) {
                $appendFromDate->addDay();
            }
            if ($appendGuard >= 14) {
                return;
            }
            $newSessions = self::buildSessionsForCount(
                $classId,
                $appendFromDate->toDateString(),
                $newCount - $currentCount,
                $slots,
                $globalDur
            );
        }

        $now = Carbon::now();
        $teacherId = (int) ($studentClass->TeacherID ?? 0);
        $subjectName = DB::table('Subject')->where('id', $studentClass->SubjectID)->value('Subject_Name')
            ?? DB::table('BaseData')->where('Name', '課程')->where('id', $studentClass->SubjectID)->value('Val')
            ?? '評量';

        foreach ($newSessions as $session) {
            $sessionDate = $session['SessionDate'] ?? null;
            $endTime = $session['EndTime'] ?? '18:00:00';
            if (!$sessionDate) {
                continue;
            }
            $isEnded = self::sessionEndedByEndTime($sessionDate, $endTime, $now);
            $session['Status'] = $isEnded ? 'completed' : 'scheduled';
            if ($isEnded && empty($session['Note'])) {
                $session['Note'] = '系統補建堂次（增加購買堂數）';
            }

            $classSession = app(ClassSessionMaterializationService::class)->upsertSlot($session)['session'];

            if ($isEnded) {
                LearningRecord::create([
                    'StudentClassID' => $classId,
                    'ClassSessionID' => (int) $classSession->id,
                    'TeacherID' => $teacherId,
                    'Content' => '',
                    'Subject' => $subjectName,
                    'SessionDate' => $classSession->SessionDate,
                    'StartTime' => $classSession->StartTime,
                    'EndTime' => $classSession->EndTime,
                    'Status' => 'pending',
                ]);
            }
        }

        $activeCount = ClassSession::where('StudentClassID', $classId)
            ->whereNotIn('Status', $nonQuotaStatuses)
            ->count();
        if ($activeCount > $newCount) {
            $excess = $activeCount - $newCount;
            $extraScheduled = ClassSession::where('StudentClassID', $classId)
                ->where('Status', 'scheduled')
                ->orderBy('SessionDate')
                ->orderBy('StartTime')
                ->orderBy('id')
                ->get()
                ->filter(function ($session) use ($expectedKeys) {
                    if (!empty($session->IsContractException)) {
                        return false;
                    }
                    $date = self::normalizeDateString($session->SessionDate ?? null);
                    $start = substr((string) ($session->StartTime ?? ''), 0, 5);
                    return $date && $start !== '' && !isset($expectedKeys[$date . '|' . $start]);
                })
                ->values();

            foreach ($extraScheduled as $session) {
                if ($excess <= 0) {
                    break;
                }
                $session->Status = 'cancelled';
                $session->save();
                $excess--;
            }
        }

        SessionDeductionService::syncCounters($studentClass);
    }

    /**
     * @param  array<int, array<string, mixed>>  $providedSlots
     * @return array<int, array{weekday:int,time:string,duration_minutes?:int}>
     */
    public static function resolveScheduleSlotsForRebuild(StudentClass $studentClass, array $providedSlots = []): array
    {
        $slots = [];

        if (!empty($providedSlots)) {
            foreach ($providedSlots as $slot) {
                $weekday = (int) ($slot['weekday'] ?? 0);
                if ($weekday < 1 || $weekday > 7) {
                    continue;
                }
                $time = self::normalizeSessionTime($slot['time'] ?? null, '16:00');
                $entry = [
                    'weekday' => $weekday,
                    'time' => substr($time, 0, 5),
                ];
                if (!empty($slot['duration_minutes']) && (int) $slot['duration_minutes'] >= 30) {
                    $entry['duration_minutes'] = (int) $slot['duration_minutes'];
                }
                $slots[] = $entry;
            }
        }

        if (empty($slots)) {
            $globalDur = (int) ($studentClass->SessionDuration ?? 0);
            $candidates = [
                ['week', 'time', null],
                ['week1', 'time1', 'duration1'],
                ['week2', 'time2', 'duration2'],
                ['week3', 'time3', 'duration3'],
                ['week4', 'time4', 'duration4'],
                ['week5', 'time5', 'duration5'],
                ['week6', 'time6', 'duration6'],
            ];
            foreach ($candidates as [$weekField, $timeField, $durField]) {
                $weekday = (int) ($studentClass->{$weekField} ?? 0);
                if ($weekday < 1 || $weekday > 7) {
                    continue;
                }
                $time = self::normalizeSessionTime($studentClass->{$timeField} ?? null, $studentClass->time ?? '16:00');
                $entry = [
                    'weekday' => $weekday,
                    'time' => substr($time, 0, 5),
                ];
                $perDayDur = $durField !== null ? (int) ($studentClass->{$durField} ?? 0) : 0;
                if ($perDayDur >= 30) {
                    $entry['duration_minutes'] = $perDayDur;
                } elseif ($globalDur >= 30) {
                    $entry['duration_minutes'] = $globalDur;
                }
                $slots[] = $entry;
            }
            $slots = self::dedupeIdenticalConsecutiveScheduleSlots($slots);
        }

        usort($slots, function ($a, $b) {
            $c = ($a['weekday'] <=> $b['weekday']);

            return $c !== 0 ? $c : strcmp((string) ($a['time'] ?? ''), (string) ($b['time'] ?? ''));
        });

        return $slots;
    }

    /**
     * @param  array<int, array{weekday:int,time:string,duration_minutes?:int}>  $slots
     * @return array<int, array{weekday:int,time:string,duration_minutes?:int}>
     */
    public static function dedupeIdenticalConsecutiveScheduleSlots(array $slots): array
    {
        if (count($slots) < 2) {
            return $slots;
        }
        $out = [$slots[0]];
        for ($i = 1; $i < count($slots); $i++) {
            $prev = $out[count($out) - 1];
            $cur = $slots[$i];
            if ((int) ($prev['weekday'] ?? 0) === (int) ($cur['weekday'] ?? 0)
                && (string) ($prev['time'] ?? '') === (string) ($cur['time'] ?? '')
                && (int) ($prev['duration_minutes'] ?? 0) === (int) ($cur['duration_minutes'] ?? 0)
            ) {
                continue;
            }
            $out[] = $cur;
        }

        return $out;
    }

    public static function normalizeDateString($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Normalize a weekday value to ISO-8601 (1=Mon … 7=Sun).
     * Accepts both ISO 1-7 and legacy JS 0-6 (0=Sunday → 7).
     */
    public static function isoWeekday($weekday): int
    {
        $weekday = (int) $weekday;

        return $weekday === 0 ? 7 : $weekday;
    }

    public static function normalizeSessionTime($value, string $fallback = '16:00:00'): string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            $raw = $fallback;
        }
        try {
            if (preg_match('/^\d{1,2}:\d{2}$/', $raw)) {
                return Carbon::createFromFormat('H:i', $raw)->format('H:i:s');
            }
            if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $raw)) {
                return Carbon::createFromFormat('H:i:s', $raw)->format('H:i:s');
            }
            return Carbon::parse($raw)->format('H:i:s');
        } catch (\Throwable $e) {
            try {
                return Carbon::parse($fallback)->format('H:i:s');
            } catch (\Throwable $ignore) {
                return '16:00:00';
            }
        }
    }

    public static function sessionEndedByEndTime(string $sessionDate, string $endTime, ?Carbon $now = null): bool
    {
        $now = $now ?: Carbon::now();
        $sessionEndAt = Carbon::parse($sessionDate . ' ' . $endTime);
        return $sessionEndAt->lte($now);
    }

    public static function buildSessionsFromWeeklySchedule(
        int $studentClassId,
        string $startDate,
        string $endDate,
        array $slots,
        int $durationMinutes,
        bool $includeStartDate = false
    ): array {
        $sessions = [];
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $matchingSlots = array_values(array_filter($slots, function ($slot) use ($date) {
                // Slots arrive in two conventions: ISO 1-7 (DB week columns, day_time_slots)
                // and legacy JS 0-6 (ScheduleSlots param). Both agree on Mon-Sat (1-6);
                // Sunday is 7 (ISO) or 0 (JS). Comparing raw dayOfWeek (0-6) silently
                // dropped every ISO-Sunday slot (GitHub #1096: 0-amount monthly invoices).
                return (int) $date->dayOfWeekIso === self::isoWeekday($slot['weekday']);
            }));

            // Monthly courses have an explicit opening date that is the first lesson,
            // even when recurrence starts on a different fixed weekday. Keep the
            // default false so date-based imports/rebuilds retain their old contract.
            if ($includeStartDate && $date->isSameDay($start) && empty($matchingSlots) && !empty($slots)) {
                $matchingSlots = [$slots[0]];
            }

            foreach ($matchingSlots as $slot) {
                    $startTime = Carbon::parse($date->toDateString() . ' ' . $slot['time']);
                    $slotDur = !empty($slot['duration_minutes']) ? (int) $slot['duration_minutes'] : $durationMinutes;
                    $endTime = $startTime->copy()->addMinutes($slotDur);

                    $sessions[] = [
                        'StudentClassID' => $studentClassId,
                        'SessionDate' => $startTime->toDateString(),
                        'StartTime' => $startTime->format('H:i:s'),
                        'EndTime' => $endTime->format('H:i:s'),
                        'Status' => 'scheduled',
                        'Note' => '',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
            }
        }

        return $sessions;
    }

    /**
     * 堂數制：排定共 sessionCount 堂。
     * 第 1 堂固定為「首堂日」startDate（不論星期幾），使用該日匹配的所有時段；
     * 第 2～N 堂從 startDate 的隔天起，依序取「星期符合 slots」的日期。
     * 支援同日多時段（如週六 13:00 + 17:00 各算一堂）。
     */
    public static function buildSessionsForCount(
        int $studentClassId,
        string $startDate,
        int $sessionCount,
        array $slots,
        int $durationMinutes
    ): array {
        $sessions = [];
        if ($sessionCount < 1 || empty($slots)) {
            return $sessions;
        }

        $slotsByWeekday = [];
        foreach ($slots as $s) {
            $wd = (int) ($s['weekday'] ?? 0);
            if ($wd < 1 || $wd > 7) {
                continue;
            }
            $slotsByWeekday[$wd][] = $s;
        }
        foreach ($slotsByWeekday as &$group) {
            usort($group, fn ($a, $b) => strcmp((string) ($a['time'] ?? ''), (string) ($b['time'] ?? '')));
        }
        unset($group);

        $slotWeekdays = array_keys($slotsByWeekday);
        $firstSlot = $slots[0];
        $firstTime = $firstSlot['time'] ?? '16:00';

        $appendSessionsForDate = function (Carbon $dateObj) use (
            $studentClassId, $durationMinutes, $slotsByWeekday,
            $firstTime, $sessionCount, &$sessions
        ) {
            $isoDow = (int) $dateObj->dayOfWeekIso;
            $daySlots = $slotsByWeekday[$isoDow] ?? [['time' => $firstTime]];
            foreach ($daySlots as $slot) {
                if (count($sessions) >= $sessionCount) {
                    return;
                }
                $time = $slot['time'] ?? $firstTime;
                $dur = (!empty($slot['duration_minutes']) && (int) $slot['duration_minutes'] >= 30)
                    ? (int) $slot['duration_minutes']
                    : $durationMinutes;
                $start = Carbon::parse($dateObj->toDateString() . ' ' . $time);
                $end = $start->copy()->addMinutes($dur);
                $sessions[] = [
                    'StudentClassID' => $studentClassId,
                    'SessionDate' => $start->toDateString(),
                    'StartTime' => $start->format('H:i:s'),
                    'EndTime' => $end->format('H:i:s'),
                    'Status' => 'scheduled',
                    'Note' => '',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        };

        $firstDate = Carbon::parse($startDate)->startOfDay();
        $appendSessionsForDate($firstDate);

        $date = Carbon::parse($startDate)->addDay()->startOfDay();
        $maxDate = Carbon::parse($startDate)->addYears(2);
        while (count($sessions) < $sessionCount && $date->lte($maxDate)) {
            $isoDow = (int) $date->dayOfWeekIso;
            if (isset($slotsByWeekday[$isoDow])) {
                $appendSessionsForDate($date);
            }
            $date->addDay();
        }

        return $sessions;
    }

    /**
     * 月結課程以 EndDate + 固定星期/時段為契約；課程管理詳情則讀 ClassSession。
     * 續約或編輯後補齊未來缺少的實體堂次，讓 UI 能立即顯示預排課程。
     *
     * @param  array<int, array<string, mixed>>  $providedSlots
     * @return array<string, mixed>
     */
    public function ensureMonthlyFutureScheduledSessions(StudentClass $studentClass, array $providedSlots = []): array
    {
        if ((string) ($studentClass->ScheduleMode ?? 'count') !== 'date') {
            return ['created_sessions' => 0, 'reason' => 'not_monthly'];
        }
        if ((int) ($studentClass->Stop ?? 0) === 1) {
            return ['created_sessions' => 0, 'reason' => 'inactive_course'];
        }

        $endDate = self::normalizeDateString($studentClass->EndDate ?? null);
        if (!$endDate) {
            return ['created_sessions' => 0, 'reason' => 'end_date_missing'];
        }

        $today = Carbon::today()->toDateString();
        $startDate = self::normalizeDateString($studentClass->StartDate ?? null) ?: $today;
        if ($startDate < $today) {
            $startDate = $today;
        }
        if ($endDate < $startDate) {
            return ['created_sessions' => 0, 'reason' => 'date_range_elapsed'];
        }

        $slots = self::resolveScheduleSlotsForRebuild($studentClass, $providedSlots);
        if (empty($slots)) {
            return ['created_sessions' => 0, 'reason' => 'schedule_slots_missing'];
        }

        $durationMinutes = max(30, (int) ($studentClass->SessionDuration ?? 120));
        $proposedSessions = self::buildSessionsFromWeeklySchedule(
            (int) $studentClass->ID,
            $startDate,
            $endDate,
            $slots,
            $durationMinutes
        );
        if (empty($proposedSessions)) {
            return ['created_sessions' => 0, 'reason' => 'no_matching_dates'];
        }

        $existingKeys = [];
        $existingRows = ClassSession::where('StudentClassID', (int) $studentClass->ID)
            ->whereDate('SessionDate', '>=', $startDate)
            ->whereDate('SessionDate', '<=', $endDate)
            ->get(['SessionDate', 'StartTime']);
        foreach ($existingRows as $row) {
            $date = self::normalizeDateString($row->SessionDate ?? null);
            $start = substr((string) ($row->StartTime ?? ''), 0, 5);
            if ($date && $start !== '') {
                $existingKeys[$date . '|' . $start] = true;
            }
        }

        $created = 0;
        $now = Carbon::now();
        foreach ($proposedSessions as $session) {
            $sessionDate = self::normalizeDateString($session['SessionDate'] ?? null);
            $start = substr((string) ($session['StartTime'] ?? ''), 0, 5);
            $endTime = self::normalizeSessionTime($session['EndTime'] ?? null, '18:00:00');
            if (!$sessionDate || $start === '') {
                continue;
            }
            if (self::sessionEndedByEndTime($sessionDate, $endTime, $now)) {
                continue;
            }
            $key = $sessionDate . '|' . $start;
            if (isset($existingKeys[$key])) {
                continue;
            }

            $upsert = app(ClassSessionMaterializationService::class)->upsertSlot($session);
            if ($upsert['created']) {
                $existingKeys[$key] = true;
                $created++;
            }
        }

        return [
            'created_sessions' => $created,
            'reason' => $created > 0 ? 'monthly_future_sessions_created' : 'already_complete',
        ];
    }

    /**
     * After an update, optionally align week/time DB fields with **future**
     * scheduled ClassSession rows (same cadence as index drift detection).
     *
     * Does **not** fall back to completed/attended history: one-off substitute
     * slots (e.g. 週二代課) must not overwrite the contract when the user removes
     * that weekday and there are no upcoming scheduled sessions left.
     */
    public function reconcileWeekTimeFieldsFromSessions(StudentClass $studentClass): void
    {
        $classId = (int) $studentClass->ID;
        $today = Carbon::today()->toDateString();
        $activeSessionsQuery = ClassSession::where('StudentClassID', $classId)
            ->where('Status', 'scheduled')
            ->whereDate('SessionDate', '>=', $today)
            ->orderBy('SessionDate')
            ->orderBy('StartTime');

        // One-off 調課／補課 must not rewrite series week/time (SaaS: this occurrence only).
        if (Schema::hasColumn('ClassSession', 'IsContractException')) {
            $activeSessionsQuery->where(function ($q) {
                $q->whereNull('IsContractException')->orWhere('IsContractException', 0);
            });
        }

        $activeSessions = $activeSessionsQuery->get(['SessionDate', 'StartTime', 'EndTime']);

        if ($activeSessions->isEmpty()) {
            return;
        }

        $slotsByWeekday = [];
        foreach ($activeSessions as $cs) {
            $date = self::normalizeDateString($cs->SessionDate ?? null);
            if (!$date) {
                continue;
            }
            $isoDow = (int) Carbon::parse($date)->dayOfWeekIso;
            $start = substr((string) ($cs->StartTime ?? ''), 0, 5);
            if ($start === '') {
                continue;
            }
            $endRaw = (string) ($cs->EndTime ?? '');
            $durMin = 0;
            if ($endRaw && $start) {
                $startM = ((int) substr($start, 0, 2)) * 60 + (int) substr($start, 3, 2);
                $endM = ((int) substr($endRaw, 0, 2)) * 60 + (int) substr($endRaw, 3, 2);
                $durMin = max(0, $endM - $startM);
            }
            $key = $isoDow . '|' . $start;
            if (!isset($slotsByWeekday[$key])) {
                $slotsByWeekday[$key] = [
                    'weekday' => $isoDow,
                    'start' => $start,
                    'dur' => $durMin > 0 ? $durMin : null,
                ];
            }
        }

        $uniqueSlots = array_values($slotsByWeekday);
        usort($uniqueSlots, fn ($a, $b) => $a['weekday'] <=> $b['weekday'] ?: strcmp($a['start'], $b['start']));

        if (empty($uniqueSlots)) {
            return;
        }

        $updates = [];
        $globalDur = (int) ($studentClass->SessionDuration ?? 0);
        $updates['week'] = $uniqueSlots[0]['weekday'];
        $updates['time'] = $uniqueSlots[0]['start'] . ':00';

        for ($i = 1; $i <= 6; $i++) {
            if (isset($uniqueSlots[$i])) {
                $updates['week' . $i] = $uniqueSlots[$i]['weekday'];
                $updates['time' . $i] = $uniqueSlots[$i]['start'] . ':00';
                $dur = $uniqueSlots[$i]['dur'];
                $updates['duration' . $i] = ($dur && $dur !== $globalDur) ? $dur : null;
            } else {
                $updates['week' . $i] = null;
                $updates['time' . $i] = null;
                $updates['duration' . $i] = null;
            }
        }

        $changed = false;
        foreach ($updates as $field => $value) {
            $current = $studentClass->{$field} ?? null;
            if ($field === 'time' || preg_match('/^time\d$/', $field)) {
                $currentNorm = $current ? substr((string) $current, 0, 5) : null;
                $newNorm = $value ? substr((string) $value, 0, 5) : null;
                if ($currentNorm !== $newNorm) {
                    $changed = true;
                    break;
                }
            } elseif ((string) ($current ?? '') !== (string) ($value ?? '')) {
                $changed = true;
                break;
            }
        }

        if ($changed) {
            $studentClass->fill($updates);
            $studentClass->save();
        }
    }

    /**
     * Whether the mapped payload contains any schedule-related field changes
     * (week/time slots or duration).  Used to decide if reconcile should be
     * skipped after an update that could not touch ClassSession rows.
     */
    public static function scheduleFieldsPresentInMapped(array $mapped): bool
    {
        static $fields = [
            'week', 'week1', 'week2', 'week3', 'week4', 'week5', 'week6',
            'time', 'time1', 'time2', 'time3', 'time4', 'time5', 'time6',
            'duration1', 'duration2', 'duration3', 'duration4', 'duration5', 'duration6',
            'SessionDuration',
        ];
        foreach ($fields as $field) {
            if (array_key_exists($field, $mapped)) {
                return true;
            }
        }
        return false;
    }

    public function countUnalignedFutureContractSessions(StudentClass $studentClass): int
    {
        $slots = self::resolveScheduleSlotsForRebuild($studentClass);
        $contractKeys = [];
        $defaultDuration = max(30, (int) ($studentClass->SessionDuration ?? 120));
        foreach ($slots as $slot) {
            $day = (int) data_get($slot, 'weekday', 0);
            $start = substr((string) data_get($slot, 'time', ''), 0, 5);
            if ($day < 1 || $day > 7 || $start === '') {
                continue;
            }
            $duration = max(30, (int) (data_get($slot, 'duration_minutes') ?: $defaultDuration));
            $end = Carbon::createFromFormat('H:i', $start)->addMinutes($duration)->format('H:i');
            $contractKeys["{$day}|{$start}|{$end}"] = true;
        }

        $query = ClassSession::query()->where('StudentClassID', (int) $studentClass->getKey())
            ->where('Status', 'scheduled')
            ->whereDate('SessionDate', '>=', Carbon::today()->toDateString());
        if (Schema::hasColumn('ClassSession', 'IsContractException')) {
            $query->where(function ($q) {
                $q->whereNull('IsContractException')->orWhere('IsContractException', 0);
            });
        }
        return $query->get(['SessionDate', 'StartTime', 'EndTime'])->filter(function ($session) use ($contractKeys) {
            $date = self::normalizeDateString($session->SessionDate ?? null);
            if (!$date) {
                return false;
            }
            $day = (int) Carbon::parse($date)->dayOfWeekIso;
            $start = substr((string) ($session->StartTime ?? ''), 0, 5);
            $end = substr((string) ($session->EndTime ?? ''), 0, 5);
            return !isset($contractKeys["{$day}|{$start}|{$end}"]);
        })->count();
    }

    /**
     * Rebuild upcoming class sessions when first class date is edited and no immutable history exists.
     *
     * @param  array<string, mixed>  $mapped
     * @param  array<int, array<string, mixed>>  $scheduleSlots
     * @return array<string, mixed>
     */
    public function maybeRebuildSessionsAfterUpdate(
        StudentClass $studentClass,
        ?string $previousStartDate,
        array $mapped,
        array $scheduleSlots = [],
        bool $forceRebuildIfMismatch = false,
        array $previousScheduleSlots = []
    ): array {
        $scheduleFields = [
            'week', 'week1', 'week2', 'week3', 'week4', 'week5', 'week6',
            'time', 'time1', 'time2', 'time3', 'time4', 'time5', 'time6',
            'duration1', 'duration2', 'duration3', 'duration4', 'duration5', 'duration6',
            'SessionDuration',
        ];
        $scheduleUpdated = false;
        foreach ($scheduleFields as $field) {
            if (array_key_exists($field, $mapped)) {
                $scheduleUpdated = true;
                break;
            }
        }

        if (!array_key_exists('StartDate', $mapped)) {
            if (!$scheduleUpdated) {
                return ['rebuilt' => false, 'reason' => 'start_date_not_updated'];
            }

            $slots = self::resolveScheduleSlotsForRebuild($studentClass, $scheduleSlots);
            if (empty($slots)) {
                return ['rebuilt' => false, 'reason' => 'schedule_slots_missing'];
            }

            $durationMinutes = max(30, (int) ($studentClass->SessionDuration ?? 120));
            $classId = (int) $studentClass->ID;

            // If immutable history exists, do a safe partial sync (times only).
            if (self::hasImmutableSessionHistory($classId) || self::hasAttendanceMarkedSessions($classId)) {
                $updatedCount = $this->syncFutureScheduledSessionTimes(
                    $classId,
                    $slots,
                    $durationMinutes,
                    $previousScheduleSlots
                );

                return [
                    'rebuilt' => false,
                    'reason' => 'history_exists',
                    'updated_future_sessions' => $updatedCount,
                ];
            }

            // A shared-package schedule edit must not turn a missing
            // first_class_date into a destructive historical rebuild. Sync
            // only mutable future rows, preserving past rows and contract
            // exceptions even when immutable history is not present yet.
            if ($studentClass->isPartOfPackage()) {
                $updatedCount = $this->syncFutureScheduledSessionTimes(
                    $classId,
                    $slots,
                    $durationMinutes,
                    $previousScheduleSlots
                );

                return [
                    'rebuilt' => false,
                    'reason' => 'future_schedule_synced',
                    'updated_future_sessions' => $updatedCount,
                ];
            }

            $startDate = self::normalizeDateString($studentClass->StartDate ?? null) ?: Carbon::today()->toDateString();
            $scheduleMode = (string) ($studentClass->ScheduleMode ?? 'count');
            $sessionCount = max(0, (int) ($studentClass->SessionCount ?? 0));
            $sessions = [];

            if ($scheduleMode === 'date') {
                $endDate = self::normalizeDateString($studentClass->EndDate ?? null);
                if (!$endDate) {
                    return ['rebuilt' => false, 'reason' => 'end_date_missing'];
                }
                if ($endDate < $startDate) {
                    $endDate = $startDate;
                    $studentClass->EndDate = $endDate;
                    $studentClass->save();
                }
                $sessions = self::buildSessionsFromWeeklySchedule(
                    $classId,
                    $startDate,
                    $endDate,
                    $slots,
                    $durationMinutes
                );
                if ($sessionCount > 0 && count($sessions) > $sessionCount) {
                    $sessions = array_slice($sessions, 0, $sessionCount);
                }
            } else {
                if ($sessionCount <= 0) {
                    return ['rebuilt' => false, 'reason' => 'session_count_missing'];
                }
                $sessions = self::buildSessionsForCount(
                    $classId,
                    $startDate,
                    $sessionCount,
                    $slots,
                    $durationMinutes
                );
            }

            $sessionIds = ClassSession::where('StudentClassID', $classId)->pluck('id')->all();
            if (!empty($sessionIds)) {
                LearningRecord::whereIn('ClassSessionID', $sessionIds)->delete();
            }
            LearningRecord::where('StudentClassID', $classId)
                ->whereNull('ClassSessionID')
                ->delete();
            ClassSession::where('StudentClassID', $classId)->delete();

            $createdSessions = 0;
            $createdPendingRecords = 0;
            $now = Carbon::now();
            $teacherId = (int) ($studentClass->TeacherID ?? 0);
            $subjectName = DB::table('Subject')->where('id', $studentClass->SubjectID)->value('Subject_Name')
                ?? DB::table('BaseData')->where('Name', '課程')->where('id', $studentClass->SubjectID)->value('Val')
                ?? '評量';
            foreach ($sessions as $session) {
                $sessionDate = self::normalizeDateString($session['SessionDate'] ?? null);
                $endTime = self::normalizeSessionTime($session['EndTime'] ?? null, '18:00:00');
                if (!$sessionDate) {
                    continue;
                }
                $isEnded = self::sessionEndedByEndTime($sessionDate, $endTime, $now);
                $session['Status'] = $isEnded ? 'completed' : 'scheduled';
                if ($isEnded && empty($session['Note'])) {
                    $session['Note'] = '系統重建堂次（固定星期調整）';
                }

                $upsert = app(ClassSessionMaterializationService::class)->upsertSlot($session);
                $classSession = $upsert['session'];
                if ($upsert['created']) {
                    $createdSessions++;
                }

                if ($isEnded) {
                    LearningRecord::create([
                        'StudentClassID' => $classId,
                        'ClassSessionID' => (int) $classSession->id,
                        'TeacherID' => $teacherId,
                        'Content' => '',
                        'Subject' => $subjectName,
                        'SessionDate' => $classSession->SessionDate,
                        'StartTime' => $classSession->StartTime,
                        'EndTime' => $classSession->EndTime,
                        'Status' => 'pending',
                    ]);
                    $createdPendingRecords++;
                }
            }

            if ($sessionCount > 0) {
                SessionDeductionService::syncCounters($studentClass);
            }

            return [
                'rebuilt' => true,
                'reason' => 'schedule_changed',
                'created_sessions' => $createdSessions,
                'created_pending_records' => $createdPendingRecords,
            ];
        }

        $newStartDate = self::normalizeDateString($studentClass->StartDate ?? null);
        if (!$newStartDate) {
            return ['rebuilt' => false, 'reason' => 'start_date_unchanged'];
        }
        $startDateChanged = $newStartDate !== $previousStartDate;
        if (!$startDateChanged) {
            $startDateMismatch = $forceRebuildIfMismatch
                && self::hasSessionStartDateMismatch((int) $studentClass->ID, $newStartDate);
            // Editing the recurring slot must sync mutable occurrences even when
            // an older course's first materialized lesson does not equal its
            // StartDate. That mismatch concerns the start-date rebuild only;
            // letting it bypass this branch left the new contract beside old
            // future times (and made the UI issue a second, non-atomic PUT).
            if ($scheduleUpdated && (!$startDateMismatch || self::hasImmutableSessionHistory((int) $studentClass->getKey()))) {
                $slots = self::resolveScheduleSlotsForRebuild($studentClass, $scheduleSlots);
                if (!empty($slots)) {
                    $durationMinutes = max(30, (int) ($studentClass->SessionDuration ?? 120));
                    $updatedCount = $this->syncFutureScheduledSessionTimes(
                        (int) $studentClass->ID,
                        $slots,
                        $durationMinutes,
                        $previousScheduleSlots
                    );
                    return [
                        'rebuilt' => false,
                        'reason' => 'history_exists',
                        'updated_future_sessions' => $updatedCount,
                    ];
                }
            }
            if (!$startDateMismatch) {
                return ['rebuilt' => false, 'reason' => 'start_date_unchanged'];
            }
        }

        if (self::hasImmutableSessionHistory((int) $studentClass->ID)) {
            // 開課日有變更：嘗試安全部分重建（只動未鎖定的未來堂次，保留已點名/已核准）
            if ($startDateChanged) {
                $slots = self::resolveScheduleSlotsForRebuild($studentClass, $scheduleSlots);
                if (!empty($slots)) {
                    $durationMinutes = max(30, (int) ($studentClass->SessionDuration ?? 120));
                    $updatedCount = $this->syncFutureScheduledSessionTimes(
                        (int) $studentClass->ID,
                        $slots,
                        $durationMinutes,
                        $previousScheduleSlots
                    );
                    return [
                        'rebuilt'                => false,
                        'reason'                 => 'partial_rebuild',
                        'updated_future_sessions' => $updatedCount,
                        'new_start_date'         => $newStartDate,
                    ];
                }
            }
            return ['rebuilt' => false, 'reason' => 'history_exists'];
        }

        $slots = self::resolveScheduleSlotsForRebuild($studentClass, $scheduleSlots);
        if (empty($slots)) {
            return ['rebuilt' => false, 'reason' => 'schedule_slots_missing'];
        }

        $sessionCount = max(0, (int) ($studentClass->SessionCount ?? 0));
        $durationMinutes = max(30, (int) ($studentClass->SessionDuration ?? 120));
        $scheduleMode = (string) ($studentClass->ScheduleMode ?? 'count');

        if ($scheduleMode === 'count' && $sessionCount <= 0) {
            return ['rebuilt' => false, 'reason' => 'session_count_missing'];
        }

        $sessions = [];
        if ($scheduleMode === 'date') {
            $endDate = self::normalizeDateString($studentClass->EndDate ?? null);
            if (!$endDate) {
                return ['rebuilt' => false, 'reason' => 'end_date_missing'];
            }
            if ($endDate < $newStartDate) {
                $endDate = $newStartDate;
                $studentClass->EndDate = $endDate;
                $studentClass->save();
            }
            $sessions = self::buildSessionsFromWeeklySchedule(
                (int) $studentClass->ID,
                $newStartDate,
                $endDate,
                $slots,
                $durationMinutes
            );
            if ($sessionCount > 0 && count($sessions) > $sessionCount) {
                $sessions = array_slice($sessions, 0, $sessionCount);
            }
        } else {
            $sessions = self::buildSessionsForCount(
                (int) $studentClass->ID,
                $newStartDate,
                $sessionCount,
                $slots,
                $durationMinutes
            );
        }

        // R99 follow-up (in-app #219): mass ::delete() query builder calls bypass Eloquent
        // model events entirely, so ClassSessionObserver::deleted() never fires and the
        // existing schedule_audit_logs trail is silently skipped — this is exactly why an
        // earlier production repair on this same rebuild path left no recoverable trace of
        // the deleted session or its evaluation record. Snapshot LearningRecord content here
        // (it has no observer of its own) and delete ClassSession rows one at a time so the
        // established audit-log infrastructure actually captures what's being destroyed.
        $operatorId = (int) (optional(request()->attributes->get('auth_user'))->id ?: 0) ?: null;
        $branchId = (int) (optional($studentClass->student)->CampusID ?: 0) ?: null;
        $sessionIds = ClassSession::where('StudentClassID', (int) $studentClass->ID)->pluck('id')->all();
        if (!empty($sessionIds)) {
            $recordsToDelete = LearningRecord::whereIn('ClassSessionID', $sessionIds)->get();
            foreach ($recordsToDelete as $record) {
                ScheduleAuditLog::create([
                    'session_id'  => $record->ClassSessionID,
                    'action_type' => 'delete',
                    'description' => "開課日/排課調整重建：刪除評量記錄 #{$record->id}（StudentClassID {$studentClass->ID}，learning_record_id={$record->id}）",
                    'operator_id' => $operatorId,
                    'branch_id'   => $branchId,
                    'old_data'    => $record->toArray(),
                    'new_data'    => null,
                ]);
            }
            LearningRecord::whereIn('ClassSessionID', $sessionIds)->delete();
        }
        LearningRecord::where('StudentClassID', (int) $studentClass->ID)
            ->whereNull('ClassSessionID')
            ->delete();
        ClassSession::where('StudentClassID', (int) $studentClass->ID)->get()->each->delete();

        $createdSessions = 0;
        $createdPendingRecords = 0;
        $now = Carbon::now();
        $teacherId = (int) ($studentClass->TeacherID ?? 0);
        $subjectName = DB::table('Subject')->where('id', $studentClass->SubjectID)->value('Subject_Name')
            ?? DB::table('BaseData')->where('Name', '課程')->where('id', $studentClass->SubjectID)->value('Val')
            ?? '評量';

        foreach ($sessions as $session) {
            $sessionDate = self::normalizeDateString($session['SessionDate'] ?? null);
            $endTime = self::normalizeSessionTime($session['EndTime'] ?? null, '18:00:00');
            if (!$sessionDate) {
                continue;
            }

            $isEnded = self::sessionEndedByEndTime($sessionDate, $endTime, $now);
            $session['Status'] = $isEnded ? 'completed' : 'scheduled';
            if ($isEnded && empty($session['Note'])) {
                $session['Note'] = '系統重建堂次（開課日調整）';
            }

            $upsert = app(ClassSessionMaterializationService::class)->upsertSlot($session);
            $classSession = $upsert['session'];
            if ($upsert['created']) {
                $createdSessions++;
            }

            if ($isEnded) {
                LearningRecord::create([
                    'StudentClassID' => (int) $studentClass->ID,
                    'ClassSessionID' => (int) $classSession->id,
                    'TeacherID' => $teacherId,
                    'Content' => '',
                    'Subject' => $subjectName,
                    'SessionDate' => $classSession->SessionDate,
                    'StartTime' => $classSession->StartTime,
                    'EndTime' => $classSession->EndTime,
                    'Status' => 'pending',
                ]);
                $createdPendingRecords++;
            }
        }

        if ($sessionCount > 0) {
            SessionDeductionService::syncCounters($studentClass);
        }

        return [
            'rebuilt' => true,
            'reason' => $startDateChanged ? 'start_date_changed' : 'start_date_aligned',
            'new_start_date' => $newStartDate,
            'created_sessions' => $createdSessions,
            'created_pending_records' => $createdPendingRecords,
        ];
    }

    /**
     * @param  array<int, array{weekday:int,time:string,duration_minutes?:int}>  $slots
     * @return array<int, list<array{time:string,dur:int}>>
     */
    public static function buildSlotsByWeekdayMap(array $slots, int $durationMinutes): array
    {
        $slotsByWeekday = [];
        foreach ($slots as $slot) {
            $weekday = (int) ($slot['weekday'] ?? 0);
            $time = (string) ($slot['time'] ?? '');
            if ($weekday < 1 || $weekday > 7 || $time === '') {
                continue;
            }
            $dur = (!empty($slot['duration_minutes']) && (int) $slot['duration_minutes'] >= 30)
                ? (int) $slot['duration_minutes']
                : $durationMinutes;
            $slotsByWeekday[$weekday][] = ['time' => substr($time, 0, 5), 'dur' => $dur];
        }
        foreach ($slotsByWeekday as &$list) {
            usort($list, fn ($a, $b) => strcmp($a['time'], $b['time']));
        }
        unset($list);

        return $slotsByWeekday;
    }

    /**
     * Nearest calendar day around $ymd whose ISO weekday exists in the contract map.
     * Prefer closer days first; on equal distance prefer earlier day to avoid skipping
     * the immediate week when changing weekday (e.g. Sun -> Sat should pick previous day).
     * Never return a date earlier than today.
     */
    public static function snapDateToContractWeekday(string $ymd, array $slotsByWeekday, bool $notBeforeAnchor = false): string
    {
        if ($ymd === '' || empty($slotsByWeekday)) {
            return $ymd;
        }
        $anchor = Carbon::parse($ymd)->startOfDay();
        $today = Carbon::today()->startOfDay();

        if (isset($slotsByWeekday[(int) $anchor->dayOfWeekIso]) && $anchor->greaterThanOrEqualTo($today)) {
            return $anchor->toDateString();
        }

        if ($notBeforeAnchor) {
            for ($offset = 1; $offset <= 7; $offset++) {
                $next = $anchor->copy()->addDays($offset);
                if ($next->greaterThanOrEqualTo($today) && isset($slotsByWeekday[(int) $next->dayOfWeekIso])) {
                    return $next->toDateString();
                }
            }
        }

        for ($offset = 1; $offset <= 7; $offset++) {
            $prev = $anchor->copy()->subDays($offset);
            $next = $anchor->copy()->addDays($offset);

            if ($prev->greaterThanOrEqualTo($today) && isset($slotsByWeekday[(int) $prev->dayOfWeekIso])) {
                return $prev->toDateString();
            }
            if ($next->greaterThanOrEqualTo($today) && isset($slotsByWeekday[(int) $next->dayOfWeekIso])) {
                return $next->toDateString();
            }
        }

        return $ymd;
    }

    /**
     * When fixed weekdays change (e.g. 週六 → 週日), future ClassSession rows may still sit on
     * the old weekday; time-only sync cannot move them. Reassign dates/times in order using
     * the same cadence as buildSessionsForCount.
     *
     * @param  \Illuminate\Support\Collection<int, ClassSession>  $unlockedSorted
     * @param  array<int, array{weekday:int,time:string,duration_minutes?:int}>  $slots
     * @param  string|null  $contractStartDate  The course start date, when known.
     */
    public function remapFutureScheduledSessionsToContract(
        $unlockedSorted,
        array $slots,
        int $durationMinutes,
        array $skipOccupiedTargetKeys = [],
        ?string $contractStartDate = null
    ): int
    {
        $k = $unlockedSorted->count();
        if ($k <= 0) {
            return 0;
        }
        $firstSessionDate = self::normalizeDateString($unlockedSorted->first()->SessionDate ?? null);
        $anchor = $firstSessionDate;
        $startDateIsAnchor = false;
        if ($contractStartDate !== null && $contractStartDate !== '') {
            $anchor = max($firstSessionDate ?: $contractStartDate, $contractStartDate);
            $startDateIsAnchor = $firstSessionDate === null || $contractStartDate > $firstSessionDate;
        }
        if ($anchor === null || $anchor === '') {
            return 0;
        }
        $slotsByWeekday = self::buildSlotsByWeekdayMap($slots, $durationMinutes);
        if (empty($slotsByWeekday)) {
            return 0;
        }
        $snapped = self::snapDateToContractWeekday($anchor, $slotsByWeekday, $startDateIsAnchor);
        // A pure removal may compress an unlocked removed-day row onto a
        // retained locked row (e.g. Wed+Thu -> Wed). That is not a new
        // booking conflict. Generate enough cadence candidates to skip the
        // retained occurrence and place the row on the next valid occurrence.
        $candidateCount = $k + count($skipOccupiedTargetKeys);
        $candidateSessions = self::buildSessionsForCount(0, $snapped, $candidateCount, $slots, $durationMinutes);
        $proposed = [];
        foreach ($candidateSessions as $candidate) {
            $date = self::normalizeDateString($candidate['SessionDate'] ?? null);
            $start = self::normalizeSessionTime($candidate['StartTime'] ?? null, '16:00:00');
            if (!$date || isset($skipOccupiedTargetKeys["{$date}|" . substr($start, 0, 5)])) {
                continue;
            }
            $proposed[] = $candidate;
            if (count($proposed) >= $k) {
                break;
            }
        }

        // Collect only the rows whose slot actually changes.
        $reflowIds = [];
        foreach ($unlockedSorted as $s) {
            $reflowIds[(int) $s->id] = true;
        }

        $moves = [];
        foreach ($unlockedSorted as $i => $session) {
            if (!isset($proposed[$i])) {
                break;
            }
            $p = $proposed[$i];
            $newDate = self::normalizeDateString($p['SessionDate'] ?? null);
            $newStart = self::normalizeSessionTime($p['StartTime'] ?? null, '16:00:00');
            $newEnd = self::normalizeSessionTime($p['EndTime'] ?? null, '18:00:00');
            if ($newDate === null || $newDate === '') {
                continue;
            }
            $oldDate = self::normalizeDateString($session->SessionDate ?? null);
            if (
                $oldDate === $newDate
                && (string) $session->StartTime === $newStart
                && (string) $session->EndTime === $newEnd
            ) {
                continue; // already on the contract slot
            }
            $moves[] = [
                'session'       => $session,
                'oldDate'       => $oldDate,
                'oldStartShort' => $session->StartTime ? substr((string) $session->StartTime, 0, 5) : null,
                'newDate'       => $newDate,
                'newStart'      => $newStart,
                'newEnd'        => $newEnd,
            ];
        }

        if (empty($moves)) {
            return 0;
        }

        $courseId = (int) $unlockedSorted->first()->StudentClassID;
        // #1163: a bulk reflow remaps unlocked[i] -> proposed[i]; a mixed/swap
        // permutation cannot move in place (moving one row onto a not-yet-moved
        // sibling's slot 1062s under uq_class_session_slot). Guard external
        // occupants up front, then move in two phases (park to sentinel slots,
        // then place) inside a transaction so nothing is stranded on failure.
        //
        // External-occupant pre-check (before any write): a target held by a
        // non-reflow live session (e.g. a locked/attended row) cannot be reflowed
        // onto — surface a clean 422 instead of a raw 1062.
        return $this->contractSessionReflowService->move($courseId, $reflowIds, $moves);
    }

    /**
     * Sync only future scheduled sessions' times by weekday mapping.
     * Keeps historical/locked sessions untouched.
     * Supports same-day multi-slot (e.g. Saturday 13:00 + 17:00): pairs
     * sessions and slots positionally (both sorted by time) per date.
     *
     * If the contract weekdays no longer include a future session's weekday (e.g. removed 週六),
     * remaps SessionDate (and times) in chronological order to match buildSessionsForCount cadence.
     *
     * @param  array<int, array{weekday:int,time:string,duration_minutes?:int}>  $slots
     */
    public function syncFutureScheduledSessionTimes(
        int $studentClassId,
        array $slots,
        int $durationMinutes,
        array $previousScheduleSlots = []
    ): int
    {
        if ($studentClassId <= 0 || empty($slots)) {
            return 0;
        }

        $slotsByWeekday = self::buildSlotsByWeekdayMap($slots, $durationMinutes);
        if (empty($slotsByWeekday)) {
            return 0;
        }

        return DB::transaction(function () use (
            $studentClassId,
            $slots,
            $durationMinutes,
            $slotsByWeekday
        ) {
        $lockedBySessionId = self::lockedClassSessionIds($studentClassId);

        $today = Carbon::today()->toDateString();
        $sessions = ClassSession::where('StudentClassID', $studentClassId)
            ->where('Status', 'scheduled')
            ->whereDate('SessionDate', '>=', $today)
            ->orderBy('SessionDate')
            ->orderBy('StartTime')
            ->get();

        // Same-day plan per date, shared with ScheduleGuardService so the course-edit guard predicts exactly this.
        $rowsByDate = [];
        $sessionsById = [];
        foreach ($sessions as $session) {
            $date = self::normalizeDateString($session->SessionDate ?? null);
            if ($date) {
                $sessionsById[(int) $session->id] = $session;
                $rowsByDate[$date][] = [
                    'id' => (int) $session->id,
                    'start' => (string) $session->StartTime,
                    'end' => (string) $session->EndTime,
                    'exception' => !empty($session->IsContractException),
                ];
            }
        }
        $plans = [];
        foreach ($rowsByDate as $date => $rows) {
            $daySlots = array_map(function ($slot) {
                $start = self::normalizeSessionTime($slot['time'], '16:00:00');
                $end = Carbon::createFromFormat('H:i:s', $start)->addMinutes(max(30, $slot['dur']))->format('H:i:s');
                return ['start' => $start, 'end' => $end];
            }, $slotsByWeekday[(int) Carbon::parse($date)->dayOfWeekIso] ?? []);
            $plans[$date] = self::planSameDayRemap($rows, $daySlots, $lockedBySessionId);
            // Safe adoption / regularization: an exception now exactly on a contract slot joins the regular contract.
            foreach (array_keys($plans[$date]['adopted']) as $id) {
                $sessionsById[$id]->IsContractException = 0;
                $sessionsById[$id]->save();
            }
        }

        $unlocked = $sessions->filter(function ($session) use ($lockedBySessionId) {
            if (isset($lockedBySessionId[(int) $session->id])) {
                return false;
            }
            if (!empty($session->IsContractException)) {
                return false;
            }
            return true;
        })->values();

        $needsRemap = false;
        $sessionWeekdays = [];
        $unlockedCountByDate = [];
        foreach ($unlocked as $session) {
            $date = self::normalizeDateString($session->SessionDate ?? null);
            if (!$date) {
                continue;
            }
            $isoDow = (int) Carbon::parse($date)->dayOfWeekIso;
            $sessionWeekdays[$isoDow] = true;
            $unlockedCountByDate[$date] = ($unlockedCountByDate[$date] ?? 0) + 1;
            if (!isset($slotsByWeekday[$isoDow])) {
                $needsRemap = true;
                break;
            }
        }

        if (!$needsRemap && $unlocked->isNotEmpty()) {
            foreach ($unlockedCountByDate as $date => $countOnDate) {
                $isoDow = (int) Carbon::parse($date)->dayOfWeekIso;
                $contractSlotsForDay = $slotsByWeekday[$isoDow] ?? [];
                if (!empty($contractSlotsForDay) && count($contractSlotsForDay) > $countOnDate) {
                    $needsRemap = true;
                    break;
                }
            }
        }

        if (!$needsRemap && $unlocked->isNotEmpty()) {
            foreach (array_keys($slotsByWeekday) as $contractDay) {
                if (!isset($sessionWeekdays[$contractDay])) {
                    $needsRemap = true;
                    break;
                }
            }
        }

        if ($needsRemap && $unlocked->isNotEmpty()) {
            $unlockedIds = $unlocked->mapWithKeys(fn ($s) => [(int) $s->id => true])->all();
            $lockedTargetKeys = $sessions->filter(function ($session) use ($unlockedIds) {
                return !isset($unlockedIds[(int) $session->id]);
            })->mapWithKeys(function ($session) {
                $date = self::normalizeDateString($session->SessionDate ?? null);
                $start = $session->StartTime ? substr((string) $session->StartTime, 0, 5) : '';
                return $date && $start ? ["{$date}|{$start}" => true] : [];
            })->all();

            $contractStartDate = self::normalizeDateString(
                DB::table('StudentClass')->where('ID', $studentClassId)->value('StartDate')
            );

            return $this->remapFutureScheduledSessionsToContract(
                $unlocked,
                $slots,
                $durationMinutes,
                $lockedTargetKeys,
                $contractStartDate
            );
        }

        // Permutation-safe move list: same-day time shifts would hit uq_class_session_slot 1062 if updated
        // in place onto a sibling's not-yet-vacated StartTime (Sentry PHP-LARAVEL-25 / #1384).
        $moves = [];
        $reflowIds = [];
        foreach ($plans as $date => $plan) {
            foreach ($plan['moves'] as $sessionId => $slot) {
                $session = $sessionsById[$sessionId];
                $moves[] = [
                    'session'       => $session,
                    'oldDate'       => self::normalizeDateString($session->SessionDate ?? null),
                    'oldStartShort' => $session->StartTime ? substr((string) $session->StartTime, 0, 5) : null,
                    'newDate'       => $date,
                    'newStart'      => $slot['start'],
                    'newEnd'        => $slot['end'],
                ];
                $reflowIds[$sessionId] = true;
            }
        }

        if (empty($moves)) {
            return 0;
        }

        $courseId = (int) ($moves[0]['session']->StudentClassID ?? 0);
        try {
            return $this->contractSessionReflowService->move($courseId, $reflowIds, $moves);
        } catch (\App\Exceptions\SlotOccupiedException $e) {
            // Soft sync must not 500 the course update: skip colliding moves
            // one-by-one so compatible rows still realign.
            $updated = 0;
            foreach ($moves as $move) {
                $sid = (int) $move['session']->id;
                try {
                    $updated += $this->contractSessionReflowService->move(
                        $courseId,
                        [$sid => true],
                        [$move]
                    );
                } catch (\App\Exceptions\SlotOccupiedException $ignored) {
                    continue;
                }
            }
            return $updated;
        }
        });
    }

    public static function hasSessionStartDateMismatch(int $studentClassId, string $startDate): bool
    {
        if ($studentClassId <= 0 || $startDate === '') {
            return false;
        }
        $firstActive = ClassSession::where('StudentClassID', $studentClassId)
            ->where('Status', '!=', 'cancelled')
            ->orderBy('SessionDate', 'asc')
            ->orderBy('StartTime', 'asc')
            ->first();
        if (!$firstActive) {
            return false;
        }
        $firstDate = self::normalizeDateString($firstActive->SessionDate ?? null);
        return $firstDate !== null && $firstDate !== $startDate;
    }

    /**
     * Check if a session's (date, startTime, duration) falls within the contract slots.
     */
    public static function hasImmutableSessionHistory(int $studentClassId): bool
    {
        if ($studentClassId <= 0) {
            return false;
        }

        // 已作廢的 StudentSignIn 不算歷史記錄，排除後再判斷
        if (StudentSignIn::where('StudentClassID', $studentClassId)->whereNull('VoidedAt')->exists()) {
            return true;
        }

        if (LearningRecord::where('StudentClassID', $studentClassId)->where('Status', 'approved')->whereNull('VoidedAt')->exists()) {
            return true;
        }
        return false;
    }

    /** Attendance-marked sessions are history: a slot-only edit must never delete-and-rebuild them. */
    public static function hasAttendanceMarkedSessions(int $studentClassId): bool
    {
        return DB::table('ClassSession')
            ->where('StudentClassID', $studentClassId)
            ->whereIn('Status', ['attended', 'late', 'leave', 'excused', 'absent'])
            ->exists();
    }

    /**
     * Sessions of a course that a schedule edit must leave in place: an approved LearningRecord or any
     * StudentSignIn points at them. Shared with StudentClassController::syncFutureScheduledSessionTimes().
     *
     * @return array<int, true> class_session_id => true
     */
    public static function lockedClassSessionIds(int $studentClassId): array
    {
        $locked = [];
        $ids = LearningRecord::query()->where('StudentClassID', $studentClassId)->where('Status', 'approved')->whereNotNull('ClassSessionID')->pluck('ClassSessionID')
            ->merge(StudentSignIn::query()->where('StudentClassID', $studentClassId)->whereNotNull('ClassSessionID')->pluck('ClassSessionID'));
        foreach ($ids as $id) {
            if ((int) $id > 0) {
                $locked[(int) $id] = true;
            }
        }

        return $locked;
    }

    /**
     * The same-day pairing of StudentClassController::syncFutureScheduledSessionTimes(), as a pure function so the
     * sync and the course-edit guard cannot drift. Times are compared on their H:i prefix (H:i and H:i:s both work).
     * - An exception row exactly on a slot is adopted (becomes regular); other exception rows are left alone.
     * - A locked regular row stays; any slot starting at its start time is consumed (unique course/date/start key).
     * - Unlocked regular rows sorted by start pair in order with the remaining slots (sorted by start); a duplicate
     *   target start is skipped; rows beyond the slot count keep their time.
     *
     * @param  array<int, array{id:int, start:string, end:string, exception:bool}>  $rowsOnDate  'scheduled' rows of one course on one date
     * @param  array<int, array<string, mixed>>  $daySlots  contract slots of that weekday, each with 'start' and 'end'
     * @param  array<int, true>  $lockedIds
     * @return array{adopted: array<int, true>, moves: array<int, array<string, mixed>>} moves: id => target slot (as passed); every other row stays
     */
    public static function planSameDayRemap(array $rowsOnDate, array $daySlots, array $lockedIds): array
    {
        $hm = fn ($t) => substr((string) $t, 0, 5);
        $at = fn ($r, $s) => $hm($r['start']) === $hm($s['start']) && $hm($r['end']) === $hm($s['end']);
        usort($daySlots, fn ($a, $b) => strcmp($hm($a['start']), $hm($b['start'])));

        $adopted = [];
        $locked = [];
        $free = [];
        foreach ($rowsOnDate as $r) {
            if ($r['exception']) {
                if (!array_filter($daySlots, fn ($s) => $at($r, $s))) {
                    continue;
                }
                $adopted[(int) $r['id']] = true;
            }
            if (isset($lockedIds[(int) $r['id']])) {
                $locked[] = $r;
            } else {
                $free[] = $r;
            }
        }
        usort($free, fn ($a, $b) => strcmp($hm($a['start']), $hm($b['start'])));
        // A locked row holds its (course, date, start) key (uq_class_session_slot): any slot starting there is consumed.
        $slots = array_values(array_filter($daySlots, fn ($s) => !array_filter($locked, fn ($l) => $hm($l['start']) === $hm($s['start']))));

        $moves = [];
        $claimed = [];
        foreach (array_slice($free, 0, count($slots)) as $idx => $r) {
            $slot = $slots[$idx];
            if (isset($claimed[$hm($slot['start'])])) {
                continue;
            }
            $claimed[$hm($slot['start'])] = true;
            if (!$at($r, $slot)) {
                $moves[(int) $r['id']] = $slot;
            }
        }

        return ['adopted' => $adopted, 'moves' => $moves];
    }
}
