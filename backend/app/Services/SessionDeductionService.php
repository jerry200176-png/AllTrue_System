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
    /**
     * Observable "已用堂數" per course: max of (扣點出缺勤、已完成堂次狀態、無綁定堂次之已核准評量筆數)。
     * 已核准但綁定 ClassSession 的評量不計入：堂次仍 scheduled 時須以點名／核課為準，避免與出缺勤待點名矛盾。
     *
     * @param  array<int|string>  $studentClassIds
     * @return array<int,int> student_class_id => used count (not capped by SessionCount)
     */
    public static function batchObservedUsedSessions(array $studentClassIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $studentClassIds), fn ($id) => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $deducted = StudentSignIn::query()
            ->whereIn('StudentClassID', $ids)
            ->active()
            ->where('SessionDeducted', true)
            ->groupBy('StudentClassID')
            ->selectRaw('StudentClassID, COUNT(DISTINCT COALESCE(NULLIF(ClassSessionID, 0), id)) as c')
            ->pluck('c', 'StudentClassID');

        $completedSessions = ClassSession::query()
            ->whereIn('StudentClassID', $ids)
            ->whereIn('Status', ['completed', 'attended', 'late'])
            ->groupBy('StudentClassID')
            ->selectRaw('StudentClassID, COUNT(*) as c')
            ->pluck('c', 'StudentClassID');

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
            ->whereNotNull('SessionDate')
            ->groupBy('StudentClassID')
            ->selectRaw("StudentClassID, COUNT(DISTINCT {$orphanDateTimeExpr}) as c")
            ->pluck('c', 'StudentClassID');

        $out = [];
        foreach ($ids as $id) {
            $a = max(0, (int) ($deducted[$id] ?? 0));
            $b = max(0, (int) ($completedSessions[$id] ?? 0));
            $d = max(0, (int) ($approvedLrOrphan[$id] ?? 0));
            $out[$id] = max($a, $b, $d);
        }

        return $out;
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

        $courses = StudentClass::query()
            ->whereIn('ID', $ids)
            ->get(['ID', 'ScheduleMode', 'SessionCount', 'SessionDuration'])
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
            ->whereIn('ledger.student_class_id', $ids)
            ->whereIn('ledger.source', ['attendance', 'retro_leave', 'status_adjust', 'duplicate_session'])
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

            $out[$id] = [
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
            StudentClass::query()->where('ID', $studentClassId)->lockForUpdate()->first();

            if ($classSessionId && $classSessionId > 0
                && self::sessionLedgerNet($studentClassId, $classSessionId) > 0) {
                return false;
            }

            SessionDeductionLedger::create([
                'student_class_id' => $studentClassId,
                'class_session_id' => $classSessionId ?: null,
                'event_type'       => 'deduct',
                'source'           => $source,
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
            if ($minutes === null && $classSessionId && $classSessionId > 0) {
                $matched = SessionDeductionLedger::query()
                    ->where('student_class_id', $studentClassId)
                    ->where('class_session_id', $classSessionId)
                    ->where('event_type', 'deduct')
                    ->orderByDesc('id')
                    ->value('minutes');
                if ($matched !== null) {
                    $minutes = (int) $matched;
                }
            }

            SessionDeductionLedger::create([
                'student_class_id' => $studentClassId,
                'class_session_id' => $classSessionId ?: null,
                'event_type'       => 'reverse',
                'source'           => $source,
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

            $attendanceUsed = StudentSignIn::query()
                ->where('StudentClassID', $studentClassId)
                ->active()
                ->where('SessionDeducted', true)
                ->selectRaw('COUNT(DISTINCT COALESCE(NULLIF(ClassSessionID, 0), id)) as aggregate_count')
                ->value('aggregate_count');
            $attendanceUsed = max(0, (int) ($attendanceUsed ?? 0));

            $classSessionUsed = ClassSession::query()
                ->where('StudentClassID', $studentClassId)
                ->whereIn('Status', ['completed', 'attended', 'late'])
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
                ->where('student_class_id', $studentClassId)
                ->whereIn('source', ['attendance', 'retro_leave', 'status_adjust', 'duplicate_session'])
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
                $hasPartial = SessionDeductionLedger::query()
                    ->where('student_class_id', $studentClassId)
                    ->whereIn('source', ['attendance', 'retro_leave', 'status_adjust', 'duplicate_session'])
                    ->whereNotNull('minutes')
                    ->where('minutes', '!=', $perSession)
                    ->exists();

                if ($hasPartial) {
                    $netMinutes = (int) (SessionDeductionLedger::query()
                        ->where('student_class_id', $studentClassId)
                        ->whereIn('source', ['attendance', 'retro_leave', 'status_adjust', 'duplicate_session'])
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
                $sc->Stop               = 0;
                $sc->RemainingMinutes  = 0;
                $sc->PurchasedMinutes  = null;
            }

            $sc->save();
        });
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
     * environment flag and the course's own opt-in. Fail-safe: with the flag off, an
     * opted-in course still behaves as fixed_session.
     */
    private static function isActualDurationActive(StudentClass $sc): bool
    {
        return (bool) config('perfflags.actual_duration_deduction_enabled', false)
            && $sc->isActualDurationBasis();
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
     * than inventing a divisor. Also returns null when the session runs exactly one
     * standard length, so the whole-session ledger encoding is preserved and the
     * result stays identical to the fixed-session path for well-formed schedules.
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
        if ($mins <= 0 || $mins === $standard) {
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
