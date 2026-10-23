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

class SessionDeductionService
{
    private const BASE_USAGE_SOURCES = ['attendance', 'retro_leave', 'status_adjust', 'duplicate_session'];
    /**
     * @param list<string> $families
     * @return list<string>
     */
    public static function usageSources(array $families = self::BASE_USAGE_SOURCES): array
    {
        return array_merge($families, array_map(fn ($s) => $s . '_makeup', $families), array_map(fn ($s) => $s . '_regular', $families));
    }
    /**
     * A date-mode SessionCount describes planned occurrences, not prepaid credit.
     * Keep usage evidence checks for both modes; only count contracts have a
     * purchased-minus-used remaining balance.
     *
     * @param array<string, mixed> $diagnostic
     * @return array{expected_remaining:?int, review_required:bool}
     */
    public static function usageBalanceAssessment(
        StudentClass $course,
        array $diagnostic,
        ?int $storedUsed = null,
        ?int $storedRemaining = null
    ): array
    {
        $isCountMode = (string) ($course->ScheduleMode ?? 'count') === 'count';
        $expectedRemaining = $isCountMode
            ? max(0, (int) ($course->SessionCount ?? 0) - (int) $diagnostic['expected_used'])
            : null;

        return [
            'expected_remaining' => $expectedRemaining,
            'review_required' => (int) $diagnostic['cancelled_usage_artifacts'] > 0
                || (bool) ($diagnostic['ambiguous_minute_evidence'] ?? false)
                || ($storedUsed ?? (int) ($course->UsedSessions ?? 0)) !== (int) $diagnostic['expected_used']
                || ($isCountMode && ($storedRemaining ?? (int) ($course->RemainingSessions ?? 0)) !== $expectedRemaining),
        ];
    }

    /**
     * Observable "已用堂數" per course: max of (扣點出缺勤、已完成堂次狀態、無綁定堂次之已核准評量筆數)。
     * 已核准但綁定 ClassSession 的評量不計入：堂次仍 scheduled 時須以點名／核課為準，避免與出缺勤待點名矛盾。
     * The three evidence queries and their distinct keys live in observedUsageSources(), shared with
     * batchObservedUsedSessionEvidence(), so a count and its lesson list cannot drift apart.
     *
     * @param  array<int|string>  $studentClassIds
     * @return array<int,int> student_class_id => used count (not capped by SessionCount)
     */
    public static function batchObservedUsedSessions(array $studentClassIds): array
    {
        $ids = self::normalizeClassIds($studentClassIds);
        if ($ids === []) {
            return [];
        }

        $counts = [];
        foreach (self::observedUsageSources($ids) as $source => [$query, $keyExpr]) {
            $counts[$source] = $query
                ->groupBy($source === 'deducted' ? 'StudentSingIn.StudentClassID' : 'StudentClassID')
                ->selectRaw(($source === 'deducted' ? 'StudentSingIn.StudentClassID' : 'StudentClassID') . ", COUNT(DISTINCT {$keyExpr}) as c")
                ->pluck('c', 'StudentClassID');
        }

        $out = [];
        foreach ($ids as $id) {
            $a = max(0, (int) ($counts['deducted'][$id] ?? 0));
            $b = max(0, (int) ($counts['completed'][$id] ?? 0));
            $d = max(0, (int) ($counts['orphan'][$id] ?? 0));
            $out[$id] = max($a, $b, $d);
        }

        return $out;
    }

    /**
     * The lessons behind batchObservedUsedSessions(): for each course, the rows of the
     * evidence source that wins the max (same queries, same distinct keys), so
     * count($evidence[$id]) === batchObservedUsedSessions()[$id] by construction.
     * Read-only (in-app #418 parent portal lesson dates).
     *
     * @param  array<int|string>  $studentClassIds
     * @return array<int, list<array{source:string, date:?string, start:?string, end:?string, status:?string}>>
     */
    public static function batchObservedUsedSessionEvidence(array $studentClassIds): array
    {
        $ids = self::normalizeClassIds($studentClassIds);
        if ($ids === []) {
            return [];
        }

        $columns = [
            'deducted' => [
                'StudentSingIn.StudentClassID', 'StudentSingIn.SignInDT', 'StudentSingIn.Status as row_status',
                'sign_in_cs.SessionDate as row_date', 'sign_in_cs.StartTime as row_start', 'sign_in_cs.EndTime as row_end',
            ],
            'completed' => ['StudentClassID', 'Status as row_status', 'SessionDate as row_date', 'StartTime as row_start', 'EndTime as row_end'],
            'orphan' => ['StudentClassID', 'SessionDate as row_date', 'StartTime as row_start', 'EndTime as row_end'],
        ];
        $sets = [];
        foreach (self::observedUsageSources($ids) as $source => [$query, $keyExpr]) {
            $query->select($columns[$source])->selectRaw("{$keyExpr} as evidence_key");
            if ($source === 'deducted') {
                // Several sign-ins can share a session; the latest (MAX id) is authoritative
                // (BackfillSessionStatus), and the first row per key wins below.
                $query->orderByDesc('StudentSingIn.id');
            }
            // toBase(): plain rows (scopes still applied) — only the selected columns are read.
            foreach ($query->toBase()->get() as $row) {
                $vals = (array) $row;
                $classId = (int) ($vals['StudentClassID'] ?? 0);
                $key = (string) ($vals['evidence_key'] ?? '');
                $signInDt = isset($vals['SignInDT']) ? (string) $vals['SignInDT'] : null;
                $date = $vals['row_date'] ?? $signInDt;
                $sets[$source][$classId][$key] ??= [
                    'source' => $source,
                    'date' => $date !== null ? substr((string) $date, 0, 10) : null,
                    'start' => isset($vals['row_start'])
                        ? (string) $vals['row_start']
                        : ($signInDt !== null ? substr($signInDt, 11, 8) : null),
                    'end' => isset($vals['row_end']) ? (string) $vals['row_end'] : null,
                    'status' => isset($vals['row_status']) ? (string) $vals['row_status'] : null,
                ];
            }
        }

        $out = [];
        foreach ($ids as $id) {
            $best = [];
            foreach (['deducted', 'completed', 'orphan'] as $source) {
                $candidate = $sets[$source][$id] ?? [];
                if (count($candidate) > count($best)) {
                    $best = $candidate;
                }
            }
            if ($best !== []) {
                $out[$id] = array_values($best);
            }
        }

        return $out;
    }

    /** @return list<int> */
    private static function normalizeClassIds(array $studentClassIds): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $studentClassIds), fn ($id) => $id > 0)));
    }

    /**
     * Single definition of the observed-usage evidence: source => [query, distinct-key SQL].
     *
     * @param  list<int>  $ids
     * @return array<string, array{0: mixed, 1: string}> query builder (Eloquent; typed mixed because larastan cannot resolve the active() scope), distinct-key SQL
     */
    private static function observedUsageSources(array $ids): array
    {
        $today = Carbon::today()->toDateString();

        $deducted = StudentSignIn::query()
            ->active()
            ->leftJoin('ClassSession as sign_in_cs', 'sign_in_cs.id', '=', 'StudentSingIn.ClassSessionID')
            ->whereIn('StudentSingIn.StudentClassID', $ids)
            ->where('SessionDeducted', true)
            ->where(function ($query) use ($today) {
                $query->whereNull('sign_in_cs.id')
                    ->orWhere(function ($linked) use ($today) {
                        $linked->whereDate('sign_in_cs.SessionDate', '<=', $today)
                            ->whereIn('sign_in_cs.Status', [
                                'completed', 'attended', 'late', 'cancelled',
                                'scheduled', 'rescheduled', 'absent',
                            ]);
                    });
            });

        $completedSessions = ClassSession::query()
            ->whereIn('StudentClassID', $ids)
            ->whereIn('Status', ['completed', 'attended', 'late'])
            ->whereDate('SessionDate', '<=', $today);

        // Orphan LRs (no ClassSessionID): count by date+StartTime to avoid
        // undercounting multi-slot days.
        $orphanDateTimeExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "substr(SessionDate, 1, 10) || '|' || COALESCE(substr(StartTime, 1, 5), '')"
            : "CONCAT(DATE(SessionDate), '|', COALESCE(LEFT(StartTime, 5), ''))";
        $approvedLrOrphan = LearningRecord::query()
            ->whereIn('StudentClassID', $ids)
            ->where('Status', 'approved')
            ->where(function ($q) {
                $q->whereNull('ClassSessionID')->orWhere('ClassSessionID', '<=', 0);
            })
            ->whereNotNull('SessionDate');

        return [
            'deducted' => [$deducted, 'COALESCE(NULLIF(StudentSingIn.ClassSessionID, 0), StudentSingIn.id)'],
            'completed' => [$completedSessions, 'ClassSession.id'],
            'orphan' => [$approvedLrOrphan, $orphanDateTimeExpr],
        ];
    }

    /**
     * Read-only expected UsedSessions values using the same sources, caps, and
     * fractional-minute rounding as recomputeCounters().
     *
     * @param  array<int|string>  $studentClassIds
     * @return array<int,int> student_class_id => expected persisted UsedSessions
     */
    public static function batchExpectedUsedSessions(array $studentClassIds): array
    {
        return array_map(
            static fn (array $diagnostic): int => $diagnostic['expected_used'],
            self::batchExpectedUsedSessionDiagnostics($studentClassIds)
        );
    }

    /**
     * Read-only explanation of the canonical UsedSessions calculation.
     *
     * This keeps reconciliation diagnostics on the same query path as
     * batchExpectedUsedSessions() instead of duplicating counter semantics in
     * the nightly command.
     *
     * @param  array<int|string>  $studentClassIds
     * @return array<int,array{
     *   ambiguous_minute_evidence:bool,
     *   expected_used:int,
     *   observed_used:int,
     *   class_session_used:int,
     *   cancelled_usage_artifacts:int,
     *   ledger_used:int,
     *   ledger_net_minutes:int,
     *   has_partial:bool,
     *   session_count:int,
     *   is_session_mode:bool,
     *   uncapped_used:int
     * }>
     */
    public static function batchExpectedUsedSessionDiagnostics(array $studentClassIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $studentClassIds), fn ($id) => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $today = Carbon::today()->toDateString();
        $courses = StudentClass::query()
            ->whereIn('ID', $ids)
            ->get(['ID', 'StudentID', 'ScheduleMode', 'SessionCount', 'SessionDuration', 'deduction_basis', 'standard_lesson_minutes', 'UsedSessions'])
            ->keyBy('ID');
        $observed = self::batchObservedUsedSessions($ids);

        $classSessionUsed = ClassSession::query()
            ->whereIn('StudentClassID', $ids)
            ->whereIn('Status', ['completed', 'attended', 'late'])
            ->groupBy('StudentClassID')
            ->selectRaw('StudentClassID, COUNT(*) as c')
            ->pluck('c', 'StudentClassID');

        // A cancelled ClassSession should not retain active attendance,
        // approved learning evidence, or a positive session ledger net. Keep
        // this diagnostic separate from the normal scheduled+sign-in path,
        // where the effective attendance can legitimately precede a status
        // transition.
        $cancelledUsageArtifacts = ClassSession::query()
            ->from('ClassSession as cancelled_cs')
            ->whereIn('cancelled_cs.StudentClassID', $ids)
            ->whereRaw("LOWER(cancelled_cs.Status) = 'cancelled'")
            ->whereDate('cancelled_cs.SessionDate', '<=', $today)
            ->where(function ($q) {
                $q->whereExists(function ($sub) {
                    $sub->selectRaw('1')
                        ->from('StudentSingIn as cancelled_si')
                        ->whereColumn('cancelled_si.ClassSessionID', 'cancelled_cs.id')
                        ->whereNull('cancelled_si.VoidedAt')
                        ->where('cancelled_si.SessionDeducted', true);
                })->orWhereExists(function ($sub) {
                    $sub->selectRaw('1')
                        ->from('LearningRecord as cancelled_lr')
                        ->whereColumn('cancelled_lr.ClassSessionID', 'cancelled_cs.id')
                        ->whereNull('cancelled_lr.VoidedAt');
                })->orWhereRaw("(
                    SELECT COALESCE(SUM(CASE WHEN l.event_type = 'deduct' THEN 1 ELSE -1 END), 0)
                    FROM session_deduction_ledger l
                    WHERE l.student_class_id = cancelled_cs.StudentClassID
                      AND l.class_session_id = cancelled_cs.id
                ) > 0");
            })
            ->groupBy('cancelled_cs.StudentClassID')
            ->selectRaw('cancelled_cs.StudentClassID, COUNT(*) as c')
            ->pluck('c', 'cancelled_cs.StudentClassID');

        $perSessionSql = 'CASE WHEN sc.SessionDuration >= 1 THEN sc.SessionDuration ELSE '
            . StudentClass::DEFAULT_SESSION_MINUTES . ' END';
        $ledger = SessionDeductionLedger::query()
            ->from('session_deduction_ledger as ledger')
            ->join('StudentClass as sc', 'sc.ID', '=', 'ledger.student_class_id')
            ->leftJoin('ClassSession as ledger_cs', 'ledger_cs.id', '=', 'ledger.class_session_id')
            ->whereIn('ledger.student_class_id', $ids)
            ->whereIn('ledger.source', self::usageSources())
            ->where(function ($query) use ($today) {
                $query->whereNull('ledger_cs.id')
                    ->orWhere(function ($linked) use ($today) {
                        $linked->whereDate('ledger_cs.SessionDate', '<=', $today)
                            ->where(function ($status) {
                                $status->whereIn('ledger_cs.Status', ['completed', 'attended', 'late', 'cancelled'])
                                    ->orWhereIn('ledger_cs.Status', ['scheduled', 'rescheduled', 'absent']);
                            });
                    });
            })
            ->groupBy('ledger.student_class_id')
            ->selectRaw('ledger.student_class_id')
            ->selectRaw(
                "SUM(CASE WHEN ledger.event_type = 'deduct' THEN 1 ELSE 0 END) "
                . "- SUM(CASE WHEN ledger.event_type = 'reverse' THEN 1 ELSE 0 END) as net_events"
            )
            ->selectRaw(
                "MAX(CASE WHEN ledger.minutes IS NOT NULL AND ledger.minutes != {$perSessionSql} "
                . 'THEN 1 ELSE 0 END) as has_partial'
            )
            ->selectRaw(
                "SUM(CASE WHEN ledger.event_type = 'deduct' "
                . "THEN COALESCE(ledger.minutes, {$perSessionSql}) "
                . "ELSE -COALESCE(ledger.minutes, {$perSessionSql}) END) as net_minutes"
            )
            ->get()
            ->keyBy('student_class_id');

        $out = [];
        foreach ($ids as $id) {
            $course = $courses->get($id);
            if (!$course) {
                continue;
            }

            $ledgerRow = $ledger->get($id);
            $ledgerUsed = max(0, (int) ($ledgerRow->net_events ?? 0));
            $observedUsed = max(0, (int) ($observed[$id] ?? 0));
            $classSessionUsedCount = max(0, (int) ($classSessionUsed[$id] ?? 0));
            $cancelledUsageArtifactCount = max(0, (int) ($cancelledUsageArtifacts[$id] ?? 0));
            $usedByAttendance = max($observedUsed, $ledgerUsed);
            $sessionCount = max(0, (int) ($course->SessionCount ?? 0));
            $isSessionMode = (string) ($course->ScheduleMode ?? 'count') === 'count';
            $hasPartial = (int) ($ledgerRow->has_partial ?? 0) === 1;
            $netMinutes = (int) ($ledgerRow->net_minutes ?? 0);
            $ambiguousMinuteEvidence = false;
            if ($hasPartial && $course->isActualDurationBasis() && !self::isActualDurationActive($course)) {
                // #3834: same rule as recomputeCounters() for a gated-off opted-in course.
                [$hasPartial, $netMinutes, $ambiguousMinuteEvidence] = self::gatedOffMinuteTotals((int) $id, max(1, $course->perSessionMinutes()), $today);
            }
            $expectedUsed = $usedByAttendance;

            if ($isSessionMode && $sessionCount > 0 && $hasPartial) {
                $perSession = max(1, $course->perSessionMinutes());
                $purchasedMinutes = $sessionCount * $perSession;
                $usedMinutes = max(0, min($purchasedMinutes, $netMinutes));
                $remainingMinutes = $purchasedMinutes - $usedMinutes;
                $remainingSessions = max(
                    0,
                    min($sessionCount, self::roundHalfUp($remainingMinutes, $perSession))
                );
                $expectedUsed = $sessionCount - $remainingSessions;
            } elseif ($isSessionMode && $sessionCount > 0) {
                $expectedUsed = min($sessionCount, $usedByAttendance);
            }

            $expectedUsed = $ambiguousMinuteEvidence ? (int) $course->UsedSessions : $expectedUsed;
            $out[$id] = [
                'ambiguous_minute_evidence' => $ambiguousMinuteEvidence,
                'expected_used' => $expectedUsed,
                'observed_used' => $observedUsed,
                'class_session_used' => $classSessionUsedCount,
                'cancelled_usage_artifacts' => $cancelledUsageArtifactCount,
                'ledger_used' => $ledgerUsed,
                'ledger_net_minutes' => $netMinutes,
                'has_partial' => $hasPartial,
                'session_count' => $sessionCount,
                'is_session_mode' => $isSessionMode,
                'uncapped_used' => $usedByAttendance,
            ];
        }

        return $out;
    }

    // ─── ledger-native entry points ───────────────────────────────

    /**
     * Record a deduction event in the ledger for a given session.
     * Idempotent: skips if a deduct already exists for the same class_session_id.
     */
    public static function deductForSession(
        int $studentClassId,
        ?int $classSessionId,
        string $source = 'attendance',
        ?int $createdBy = null,
        ?string $note = null,
        ?int $minutes = null
    ): bool {
        // Serialize per-course ledger writes (ledger index is non-unique).
        // Idempotency is net-based: deduct allowed when net<=0; reverse when net>0
        // so undo+re-attend can create a new deduct after a matching reverse.
        return (bool) DB::transaction(function () use ($studentClassId, $classSessionId, $source, $createdBy, $note, $minutes) {
            $course = StudentClass::query()->where('ID', $studentClassId)->lockForUpdate()->first();

            if ($classSessionId && $classSessionId > 0
                && self::sessionLedgerNet($studentClassId, $classSessionId) > 0) {
                return false;
            }

            if ($course instanceof StudentClass && $course->isActualDurationBasis() && !self::isActualDurationActive($course)
                && self::gatedOffMinuteTotals($studentClassId, max(1, $course->perSessionMinutes()), now()->toDateString())[2]) {
                throw \Illuminate\Validation\ValidationException::withMessages(['student_class_id' => '扣堂紀錄需先人工核對，暫時無法新增扣堂。']);
            }
            $kind = $minutes !== null && $minutes > 0 && $classSessionId
                ? self::minuteEventKindAtDeduction($studentClassId, $classSessionId)
                : null;
            $ledgerSource = self::minuteEventSource($source, $kind);
            SessionDeductionLedger::create([
                'student_class_id' => $studentClassId,
                'class_session_id' => $classSessionId ?: null,
                'event_type'       => 'deduct',
                'source'           => $ledgerSource,
                // #613 A1：null＝整堂（引擎以 perSessionMinutes 換算），>0＝部分時數。
                'minutes'          => ($minutes !== null && $minutes > 0) ? $minutes : null,
                'created_by'       => $createdBy,
                'note'             => $note,
            ]);

            PackageDeductionService::syncFromStudentClassDeduction(
                $studentClassId, $classSessionId, 'deduct', $source, $createdBy, $note
            );

            return true;
        });
    }

    /**
     * Record a reverse (undo-deduction) event in the ledger.
     * Idempotent: skips when net deduct count for the class_session_id is already 0.
     */
    public static function reverseForSession(
        int $studentClassId,
        ?int $classSessionId,
        string $source = 'retro_leave',
        ?int $createdBy = null,
        ?string $note = null,
        ?int $minutes = null
    ): bool {
        return (bool) DB::transaction(function () use ($studentClassId, $classSessionId, $source, $createdBy, $note, $minutes) {
            StudentClass::query()->where('ID', $studentClassId)->lockForUpdate()->first();

            if ($classSessionId && $classSessionId > 0
                && self::sessionLedgerNet($studentClassId, $classSessionId) <= 0) {
                return false;
            }

            // #613 A1：還原必須沖回「對應 deduct 當初記錄的分鐘」，否則部分扣堂的淨值會漂移。
            $matched = $classSessionId && $classSessionId > 0
                ? SessionDeductionLedger::query()
                    ->where('student_class_id', $studentClassId)
                    ->where('class_session_id', $classSessionId)
                    ->where('event_type', 'deduct')
                    ->orderByDesc('id')
                    ->first(['minutes', 'source'])
                : null;
            if ($minutes === null && $matched?->minutes !== null) {
                $minutes = (int) $matched->minutes;
            }
            $ledgerSource = self::minuteEventSource($source, self::minuteEventKindFromSource($matched?->source));

            SessionDeductionLedger::create([
                'student_class_id' => $studentClassId,
                'class_session_id' => $classSessionId ?: null,
                'event_type'       => 'reverse',
                'source'           => $ledgerSource,
                'minutes'          => ($minutes !== null && $minutes > 0) ? $minutes : null,
                'created_by'       => $createdBy,
                'note'             => $note,
            ]);

            PackageDeductionService::syncFromStudentClassDeduction(
                $studentClassId, $classSessionId, 'reverse', $source, $createdBy, $note
            );

            return true;
        });
    }

    /** Net ledger events for one session: deduct(+1) − reverse(−1). */
    private static function sessionLedgerNet(int $studentClassId, int $classSessionId): int
    {
        return (int) (SessionDeductionLedger::query()
            ->where('student_class_id', $studentClassId)
            ->where('class_session_id', $classSessionId)
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN event_type='deduct' THEN 1 "
                . "WHEN event_type='reverse' THEN -1 ELSE 0 END), 0) as net"
            )
            ->value('net') ?? 0);
    }

    /**
     * Recompute UsedSessions / RemainingSessions from the ledger and persist.
     */
    public static function recomputeCounters(int $studentClassId): void
    {
        DB::transaction(function () use ($studentClassId) {
            $sc = StudentClass::query()->where('ID', $studentClassId)->lockForUpdate()->first();
            if (!$sc) {
                return;
            }
            $today = Carbon::today()->toDateString();
            $attendanceUsed = StudentSignIn::query()
                ->leftJoin('ClassSession as sign_in_cs', 'sign_in_cs.id', '=', 'StudentSingIn.ClassSessionID')
                ->where('StudentSingIn.StudentClassID', $studentClassId)
                ->active()
                ->where('SessionDeducted', true)
                ->where(function ($query) use ($today) {
                    $query->whereNull('sign_in_cs.id')
                        ->orWhereDate('sign_in_cs.SessionDate', '<=', $today);
                })
                ->selectRaw('COUNT(DISTINCT COALESCE(NULLIF(StudentSingIn.ClassSessionID, 0), StudentSingIn.id)) as aggregate_count')
                ->value('aggregate_count');
            $attendanceUsed = max(0, (int) ($attendanceUsed ?? 0));

            $classSessionUsed = ClassSession::query()
                ->where('StudentClassID', $studentClassId)
                ->whereIn('Status', ['completed', 'attended', 'late'])
                ->whereDate('SessionDate', '<=', $today)
                ->count();
            $classSessionUsed = max(0, (int) $classSessionUsed);

            $orphanExpr = DB::connection()->getDriverName() === 'sqlite'
                ? "substr(SessionDate, 1, 10) || '|' || COALESCE(substr(StartTime, 1, 5), '')"
                : "CONCAT(DATE(SessionDate), '|', COALESCE(LEFT(StartTime, 5), ''))";
            $lrOrphan = LearningRecord::query()
                ->where('StudentClassID', $studentClassId)
                ->where('Status', 'approved')
                ->where(function ($q) {
                    $q->whereNull('ClassSessionID')->orWhere('ClassSessionID', '<=', 0);
                })
                ->whereNotNull('SessionDate')
                ->selectRaw("COUNT(DISTINCT {$orphanExpr}) as c")
                ->value('c');
            $lrOrphan = max(0, (int) ($lrOrphan ?? 0));

            // Keep ledger as a secondary safeguard for attendance-driven updates
            // (e.g. status transitions that may temporarily not have sign-in rows).
            // Bound approved LRs (ClassSessionID set) do not add used count: 堂數以點名／堂次狀態為準。
            $ledgerUsed = SessionDeductionLedger::query()
                ->leftJoin('ClassSession as ledger_cs', 'ledger_cs.id', '=', 'session_deduction_ledger.class_session_id')
                ->where('student_class_id', $studentClassId)
                ->whereIn('source', self::usageSources())
                ->where(function ($query) use ($today) {
                    $query->whereNull('ledger_cs.id')
                        ->orWhereDate('ledger_cs.SessionDate', '<=', $today);
                })
                ->selectRaw("SUM(CASE WHEN event_type = 'deduct' THEN 1 ELSE 0 END) - SUM(CASE WHEN event_type = 'reverse' THEN 1 ELSE 0 END) as net")
                ->value('net');
            $ledgerUsed = max(0, (int) ($ledgerUsed ?? 0));
            $usedByAttendance = max($attendanceUsed, $ledgerUsed, $classSessionUsed, $lrOrphan);

            $isSessionMode = (string) ($sc->ScheduleMode ?? 'count') === 'count';
            $sessionCount  = max(0, (int) ($sc->SessionCount ?? 0));
            $perSession    = self::billingStandardMinutes($sc);

            if ($isSessionMode && $sessionCount > 0) {
                $purchasedMinutes = $sessionCount * $perSession;

                // #613 A1：是否存在「部分時數」事件（minutes 已記錄且 ≠ 整堂）。
                // 否 → 完全沿用既有 count-based 邏輯（行為 byte-identical），僅補寫衍生分鐘欄。
                // 是 → 分鐘為權威，RemainingSessions 改為 ROUND_HALF_UP 衍生顯示值。
                // #3834 — an opted-in course whose minute billing is gated off (flag off,
                // campus removed from the pilot, or student moved off-pilot) behaves as
                // fixed_session: ordinary attendances (including minute rows recorded while
                // it was live) count as whole lessons; makeups keep their prorated minutes.
                $gatedOffActualDuration = $sc->isActualDurationBasis() && !self::isActualDurationActive($sc);
                [$gatedPartial, $gatedNet, $ambiguousMinuteEvidence] = $gatedOffActualDuration
                    ? self::gatedOffMinuteTotals($studentClassId, $perSession, $today)
                    : [false, 0, false];
                if ($ambiguousMinuteEvidence) {
                    return;
                }
                $hasPartial = $gatedOffActualDuration ? $gatedPartial : SessionDeductionLedger::query()
                    ->leftJoin('ClassSession as ledger_cs', 'ledger_cs.id', '=', 'session_deduction_ledger.class_session_id')
                    ->where('student_class_id', $studentClassId)
                    ->whereIn('source', self::usageSources())
                    ->where(function ($query) use ($today) {
                        $query->whereNull('ledger_cs.id')
                            ->orWhereDate('ledger_cs.SessionDate', '<=', $today);
                    })
                    ->whereNotNull('minutes')
                    ->where('minutes', '!=', $perSession)
                    ->exists();

                if ($hasPartial) {
                    $netMinutes = $gatedOffActualDuration ? $gatedNet : (int) (SessionDeductionLedger::query()
                        ->leftJoin('ClassSession as ledger_cs', 'ledger_cs.id', '=', 'session_deduction_ledger.class_session_id')
                        ->where('student_class_id', $studentClassId)
                        ->whereIn('source', self::usageSources())
                        ->where(function ($query) use ($today) {
                            $query->whereNull('ledger_cs.id')
                                ->orWhereDate('ledger_cs.SessionDate', '<=', $today);
                        })
                        ->selectRaw(
                            "SUM(CASE WHEN event_type = 'deduct' THEN COALESCE(minutes, ?) "
                            . "ELSE -COALESCE(minutes, ?) END) as net",
                            [$perSession, $perSession]
                        )
                        ->value('net') ?? 0);

                    $usedMinutes       = max(0, min($purchasedMinutes, $netMinutes));
                    $remainingMinutes  = max(0, $purchasedMinutes - $usedMinutes);
                    $remainingSessions = max(0, min($sessionCount, self::roundHalfUp($remainingMinutes, $perSession)));

                    $sc->RemainingMinutes  = $remainingMinutes;
                    $sc->PurchasedMinutes  = $purchasedMinutes;
                    $sc->RemainingSessions = $remainingSessions;
                    $sc->UsedSessions      = $sessionCount - $remainingSessions;
                } else {
                    $usedSessions = min($sessionCount, $usedByAttendance);
                    $sc->UsedSessions      = $usedSessions;
                    $sc->RemainingSessions  = max(0, $sessionCount - $usedSessions);
                    // 衍生分鐘欄（display/權威化準備），不影響上面整數結果。
                    $sc->PurchasedMinutes  = $purchasedMinutes;
                    $sc->RemainingMinutes  = max(0, $sessionCount - $usedSessions) * $perSession;
                }
                // Do not auto-set Stop when remaining hits 0; pause is manual (StudentClassController pause/resume).
                // NOTE: Paid/PayDate must NOT be touched here. Session counting is independent of
                // payment status. Paid is only written via three authorised paths:
                // 1. POST /api/v1/class-sessions/batch (EnrollmentService::store)
                // 2. PUT /api/v1/student-classes/:id (StudentClassController::mapFrontendPayload)
                // 3. POST /api/v1/invoices/:id/payments
            } else {
                $sc->UsedSessions      = $usedByAttendance;
                $sc->RemainingSessions  = 0;
                $sc->setAttribute('Stop', 0);
                $sc->RemainingMinutes  = 0;
                $sc->PurchasedMinutes  = null;
            }

            $sc->save();
        });
    }

    /**
     * #3834: [has_partial, net_minutes] of a gated-off opted-in course under fixed_session rules.
     *
     * @return array{0: bool, 1: int, 2: bool}
     */
    private static function gatedOffMinuteTotals(int $studentClassId, int $perSession, string $today): array
    {
        // Tagged makeup identity survives lifecycle changes; untagged history needs live evidence.
        $makeups = Schedule::query()->where('student_course_id', $studentClassId)->where('type', 'extra')->whereNotIn('status', [Schedule::STATUS_SUPERSEDED, 'cancelled'])
            ->get(['schedule_date', 'start_time'])
            ->mapWithKeys(fn ($s) => [substr((string) $s->schedule_date, 0, 10) . '|' . substr((string) $s->start_time, 0, 5) => true]);
        $rows = SessionDeductionLedger::query()
            ->leftJoin('ClassSession as ledger_cs', 'ledger_cs.id', '=', 'session_deduction_ledger.class_session_id')
            ->where('student_class_id', $studentClassId)
            ->whereIn('source', self::usageSources())
            ->where(fn ($q) => $q->whereNull('ledger_cs.id')->orWhereDate('ledger_cs.SessionDate', '<=', $today))
            ->get(['session_deduction_ledger.id', 'session_deduction_ledger.class_session_id', 'session_deduction_ledger.event_type', 'session_deduction_ledger.minutes', 'session_deduction_ledger.source', 'ledger_cs.SessionDate as cs_date', 'ledger_cs.StartTime as cs_start']);

        $hasPartial = false;
        $ambiguousNets = [];
        $net = 0;
        foreach ($rows as $row) {
            $kind = self::minuteEventKindFromSource($row->source);
            $isLegacyMakeup = $kind === null && $row->cs_date !== null
                && isset($makeups[substr((string) $row->cs_date, 0, 10) . '|' . substr((string) $row->cs_start, 0, 5)]);
            if ($row->minutes !== null && (int) $row->minutes !== $perSession && $kind === null && !$isLegacyMakeup) {
                $key = (int) $row->class_session_id > 0 ? $row->class_session_id . '|' . $row->minutes : 'orphan|' . $row->id;
                $ambiguousNets[$key] = ($ambiguousNets[$key] ?? 0) + ($row->event_type === 'deduct' ? 1 : -1);
            }
            $minutes = ($kind === 'M' || $isLegacyMakeup) && $row->minutes !== null ? (int) $row->minutes : $perSession;
            $hasPartial = $hasPartial || $minutes !== $perSession;
            $net += $row->event_type === 'deduct' ? $minutes : -$minutes;
        }

        return [$hasPartial, $net, array_filter($ambiguousNets) !== []];
    }

    private static function minuteEventKindAtDeduction(int $courseId, int $sessionId): ?string
    {
        $row = DB::table('ClassSession as cs')->where('cs.id', $sessionId)->where('cs.StudentClassID', $courseId)
            ->selectRaw("EXISTS(SELECT 1 FROM schedules s WHERE s.student_course_id = cs.StudentClassID AND s.type = 'extra' AND s.status NOT IN (?, ?) AND DATE(s.schedule_date) = DATE(cs.SessionDate) AND SUBSTR(s.start_time,1,5) = SUBSTR(cs.StartTime,1,5)) AS makeup", [Schedule::STATUS_SUPERSEDED, 'cancelled'])->first();
        return $row === null ? null : ((bool) $row->makeup ? 'M' : 'F');
    }
    private static function minuteEventSource(string $source, ?string $kind): string
    {
        return $kind !== null && in_array($source, self::BASE_USAGE_SOURCES, true)
            ? $source . ($kind === 'M' ? '_makeup' : '_regular')
            : $source;
    }
    private static function minuteEventKindFromSource(?string $source): ?string
    {
        return in_array($source, self::usageSources(), true) && !in_array($source, self::BASE_USAGE_SOURCES, true)
            ? (str_ends_with($source, '_makeup') ? 'M' : 'F') : null;
    }

    // ─── backward-compatible wrappers (used by existing callers) ──

    /**
     * Replacement for the old syncCounters: now recomputes from ledger,
     * but also seeds the ledger from existing flag-based data first.
     */
    public static function syncCounters(StudentClass $studentClass): void
    {
        self::recomputeCounters($studentClass->ID);
    }

    /**
     * Deduct one session on attendance. Writes to ledger, marks sign-in,
     * and recomputes counters. Same signature as the old method.
     */
    public static function deductOnAttendance(StudentClass $sc, ?StudentSignIn $signIn = null, ?int $classSessionId = null): bool
    {
        try {
            $resolvedClassSessionId = $classSessionId ?: (int) ($signIn->ClassSessionID ?? 0);
            if ($signIn && $signIn->SessionDeducted) {
                self::syncCounters($sc);
                return false;
            }

            // #613 A1：補課只覆蓋部分時數時，扣除該補課實際分鐘（否則 null＝整堂）。
            // RFC 非標準時長：actual_duration 課程則每堂都依實際時長扣分鐘。
            $partialMinutes = self::resolvePartialDeductionMinutes($sc, $resolvedClassSessionId);

            $deducted = false;
            DB::transaction(function () use ($sc, $signIn, $resolvedClassSessionId, $partialMinutes, &$deducted) {
                $wrote = self::deductForSession(
                    $sc->ID,
                    $resolvedClassSessionId > 0 ? $resolvedClassSessionId : null,
                    'attendance',
                    null,
                    null,
                    $partialMinutes
                );

                if ($signIn) {
                    $signIn->SessionDeducted = true;
                    $signIn->save();
                }

                $deducted = $wrote;
            });

            self::recomputeCounters($sc->ID);
            return $deducted;
        } catch (\Throwable $e) {
            if ($e instanceof \Illuminate\Validation\ValidationException) { throw $e; }
            \Illuminate\Support\Facades\Log::error('session_deduct_on_attendance_failed', [
                'student_class_id' => $sc->ID,
                'sign_in_id'       => $signIn?->id,
                'class_session_id' => $classSessionId ?: ($signIn->ClassSessionID ?? null),
                'error'            => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * How many minutes this attendance consumes, or null for "one whole session".
     *
     * Two independent paths, in priority order:
     *
     * 1. Actual-duration courses (RFC non-standard duration D2) — the course has
     *    explicitly opted in AND the environment flag is on. EVERY session consumes
     *    its real clock duration, not just makeups.
     * 2. Everything else — the pre-existing #613 behaviour, unchanged: only a makeup
     *    (schedules.type='extra') whose length differs from the contract is prorated;
     *    ordinary sessions always consume one whole lesson.
     *
     * Path 2 is byte-identical to before, which is what keeps every existing course
     * behaving exactly as it does today.
     */
    private static function resolvePartialDeductionMinutes(StudentClass $sc, int $classSessionId): ?int
    {
        if ($classSessionId <= 0) {
            return null;
        }

        if (self::isActualDurationActive($sc)) {
            return self::resolveActualDurationMinutes($sc, $classSessionId);
        }

        return self::resolvePartialMakeupMinutes($sc, $classSessionId);
    }

    /**
     * Is minute-proportional billing live for this course? Requires BOTH the
     * environment flag, the course's own opt-in AND the course's campus being in the
     * pilot allowlist (#3834). Fail-safe: when any of the three is false, an opted-in
     * course still behaves as fixed_session.
     */
    public static function isActualDurationActive(StudentClass $sc): bool
    {
        return (bool) config('perfflags.actual_duration_deduction_enabled', false)
            && $sc->isActualDurationBasis()
            && self::isActualDurationCampusAllowedForCourse($sc);
    }

    /**
     * #3834 — is this course's campus (its student's CampusID; StudentClass has no
     * campus column) in the pilot allowlist? Read without global scopes so the
     * operational-tenant filter can never turn a real campus into "unknown".
     */
    public static function isActualDurationCampusAllowedForCourse(StudentClass $sc): bool
    {
        // One lookup per model instance: a single attendance asks several times.
        self::$courseCampusCache ??= new \WeakMap();
        if (!isset(self::$courseCampusCache[$sc])) {
            self::$courseCampusCache[$sc] = $sc->relationLoaded('student') && $sc->student !== null
                ? (int) $sc->student->CampusID
                : (int) (\App\Models\Student::query()->withoutGlobalScopes()->whereKey($sc->StudentID)->value('CampusID') ?? 0);
        }

        return self::isActualDurationCampusAllowed(self::$courseCampusCache[$sc]);
    }

    /** @var \WeakMap<StudentClass, int>|null */
    private static ?\WeakMap $courseCampusCache = null;

    /**
     * #3834 — the deduction_basis a NEW contract copied from $source may carry
     * (renewal, tutoring copy). Outside the pilot campuses a copy never becomes
     * actual_duration, so no new actual-duration contract appears off-pilot.
     */
    public static function pilotSafeCopiedBasis(StudentClass $source): string
    {
        return $source->isActualDurationBasis() && (!(bool) config('perfflags.actual_duration_deduction_enabled', false) || !self::isActualDurationCampusAllowedForCourse($source))
            ? \App\Services\Scheduling\DeductionBasis::FIXED_SESSION
            : $source->deduction_basis;
    }

    /** @return array{deduction_basis:string,standard_lesson_minutes:?int} */
    public static function pilotSafeCopiedContract(StudentClass $source): array
    {
        $basis = self::pilotSafeCopiedBasis($source);
        return ['deduction_basis' => $basis, 'standard_lesson_minutes' => $basis === \App\Services\Scheduling\DeductionBasis::ACTUAL_DURATION ? $source->standard_lesson_minutes : null];
    }

    /**
     * #3834 pilot scope — may actual-duration billing run for a course at this campus?
     * Reads `perfflags.actual_duration_campus_ids` at call time: "*" = every campus,
     * otherwise a comma-separated CampusID list (empty = none). Unknown campus (0)
     * is never allowed, not even under "*": a failed campus lookup falls back to
     * whole-session deduction.
     */
    public static function isActualDurationCampusAllowed(int $campusId): bool
    {
        $raw = config('perfflags.actual_duration_campus_ids', '');
        $raw = is_array($raw) ? implode(',', $raw) : trim((string) $raw);
        if ($campusId <= 0) {
            return false;
        }
        if ($raw === '*') {
            return true;
        }

        $allowed = array_map('intval', array_filter(array_map('trim', explode(',', $raw)), fn (string $v): bool => $v !== ''));

        return in_array($campusId, $allowed, true);
    }

    /**
     * Minutes in ONE standard billing unit for this course — the divisor that turns
     * minutes into lesson-equivalents, and the multiplier that turns purchased units
     * into purchased minutes.
     *
     * For an active actual-duration course that is its own `standard_lesson_minutes`.
     * For everything else it stays `perSessionMinutes()` (SessionDuration, or the
     * legacy 60 fallback), so existing courses keep the exact divisor they have today.
     *
     * Falls back to perSessionMinutes() if an opted-in course somehow has no persisted
     * standard: the deduction path already refuses to prorate in that state, so the
     * two stay consistent rather than dividing by a different number than was charged.
     */
    public static function billingStandardMinutes(StudentClass $sc): int
    {
        if (self::isActualDurationActive($sc)) {
            $standard = $sc->resolvedStandardLessonMinutes();
            if ($standard !== null) {
                return max(1, $standard);
            }
        }

        return max(1, $sc->perSessionMinutes());
    }

    /**
     * Actual clock duration of this session, or null to fall back to a whole session.
     *
     * Returns null (whole session) when the standard is missing — fail closed rather
     * than inventing a divisor. Ordinary sessions exactly one standard
     * unit retain null encoding; makeups retain explicit minutes and identity for
     * the fixed-session recount if the pilot gate is later disabled.
     */
    private static function resolveActualDurationMinutes(StudentClass $sc, int $classSessionId): ?int
    {
        $standard = $sc->resolvedStandardLessonMinutes();
        if ($standard === null) {
            return null;
        }

        $cs = ClassSession::query()->find($classSessionId);
        if (!$cs || empty($cs->StartTime) || empty($cs->EndTime)) {
            return null;
        }

        $mins = self::durationMinutes($cs->StartTime, $cs->EndTime);
        if ($mins <= 0 || ($mins === $standard && self::minuteEventKindAtDeduction((int) $sc->ID, $classSessionId) !== 'M')) {
            return null;
        }

        return $mins;
    }

    /**
     * #613 A1 + 補課加長：補課（schedules.type='extra'）時長 ≠ 契約每堂分鐘時，
     * 回傳實際分鐘（可短於或長於 perSession）。非補課、剛好完整時長、或時間不足
     * → null（＝整堂）。正常課堂一律整堂。禁止 clamp 回 perSession。
     */
    private static function resolvePartialMakeupMinutes(StudentClass $sc, int $classSessionId): ?int
    {
        $cs = ClassSession::query()->find($classSessionId);
        if (!$cs || empty($cs->StartTime) || empty($cs->EndTime) || empty($cs->SessionDate)) {
            return null;
        }

        $csStart  = substr((string) $cs->StartTime, 0, 5);
        $isMakeup = Schedule::query()
            ->where('student_course_id', (int) $sc->getKey())
            ->whereDate('schedule_date', $cs->SessionDate)
            ->where('type', 'extra')
            ->where('status', '!=', Schedule::STATUS_SUPERSEDED)
            ->get(['start_time'])
            ->contains(fn ($r) => substr((string) $r->start_time, 0, 5) === $csStart);
        if (!$isMakeup) {
            return null;
        }

        $perSession = max(1, $sc->perSessionMinutes());
        $mins = self::durationMinutes($cs->StartTime, $cs->EndTime);
        if ($mins <= 0) {
            return null;
        }
        if ($mins === $perSession) {
            return null; // 剛好完整時長 → 整堂（byte-identical）
        }

        return $mins; // 短於或長於契約時長的補課 → 實際分鐘
    }

    /** StartTime/EndTime（HH:MM[:SS]）換算分鐘，處理跨午夜（沿用 StudentClassController 慣例）。 */
    private static function durationMinutes($start, $end): int
    {
        try {
            $s = Carbon::createFromFormat('H:i', substr((string) $start, 0, 5));
            $e = Carbon::createFromFormat('H:i', substr((string) $end, 0, 5));
            $d = $s->diffInMinutes($e, false);
            if ($d <= 0) {
                $d += 24 * 60;
            }
            return (int) $d;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * #613 A1：以整數運算做 ROUND_HALF_UP(minutes / perSession)，不使用 float。
     * round_half_up(a/b) = floor((2a + b) / (2b))。
     */
    private static function roundHalfUp(int $minutes, int $perSession): int
    {
        if ($perSession <= 0) {
            return 0;
        }
        return intdiv($minutes * 2 + $perSession, $perSession * 2);
    }

}
