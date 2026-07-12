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
        $ids = array_values(array_unique(array_filter(array_map('intval', $studentClassIds), fn ($id) => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $courses = StudentClass::query()
            ->whereIn('ID', $ids)
            ->get(['ID', 'ScheduleMode', 'SessionCount', 'SessionDuration'])
            ->keyBy('ID');
        $observed = self::batchObservedUsedSessions($ids);

        $perSessionSql = 'CASE WHEN sc.SessionDuration >= 1 THEN sc.SessionDuration ELSE '
            . StudentClass::DEFAULT_SESSION_MINUTES . ' END';
        $ledger = SessionDeductionLedger::query()
            ->from('session_deduction_ledger as ledger')
            ->join('StudentClass as sc', 'sc.ID', '=', 'ledger.student_class_id')
            ->whereIn('ledger.student_class_id', $ids)
            ->whereIn('ledger.source', ['attendance', 'retro_leave', 'status_adjust'])
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
            $usedByAttendance = max(0, (int) ($observed[$id] ?? 0), $ledgerUsed);
            $sessionCount = max(0, (int) ($course->SessionCount ?? 0));
            $isSessionMode = (string) ($course->ScheduleMode ?? 'count') === 'count';

            if (!$isSessionMode || $sessionCount === 0) {
                $out[$id] = $usedByAttendance;
                continue;
            }

            if ((int) ($ledgerRow->has_partial ?? 0) === 1) {
                $perSession = max(1, $course->perSessionMinutes());
                $purchasedMinutes = $sessionCount * $perSession;
                $usedMinutes = max(0, min($purchasedMinutes, (int) ($ledgerRow->net_minutes ?? 0)));
                $remainingMinutes = $purchasedMinutes - $usedMinutes;
                $remainingSessions = max(
                    0,
                    min($sessionCount, self::roundHalfUp($remainingMinutes, $perSession))
                );
                $out[$id] = $sessionCount - $remainingSessions;
                continue;
            }

            $out[$id] = min($sessionCount, $usedByAttendance);
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
        if ($classSessionId && $classSessionId > 0) {
            $exists = SessionDeductionLedger::where('student_class_id', $studentClassId)
                ->where('class_session_id', $classSessionId)
                ->where('event_type', 'deduct')
                ->exists();
            if ($exists) {
                return false;
            }
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
    }

    /**
     * Record a reverse (undo-deduction) event in the ledger.
     * Idempotent: skips if a reverse already exists for the same class_session_id.
     */
    public static function reverseForSession(
        int $studentClassId,
        ?int $classSessionId,
        string $source = 'retro_leave',
        ?int $createdBy = null,
        ?string $note = null,
        ?int $minutes = null
    ): bool {
        if ($classSessionId && $classSessionId > 0) {
            $exists = SessionDeductionLedger::where('student_class_id', $studentClassId)
                ->where('class_session_id', $classSessionId)
                ->where('event_type', 'reverse')
                ->exists();
            if ($exists) {
                return false;
            }
        }

        // #613 A1：還原必須沖回「對應 deduct 當初記錄的分鐘」，否則部分扣堂的淨值會漂移。
        // 未指定 minutes 時，查回同一堂次的 deduct 列分鐘（整堂 deduct 為 null → 維持 null）。
        if ($minutes === null && $classSessionId && $classSessionId > 0) {
            $matched = SessionDeductionLedger::query()
                ->where('student_class_id', $studentClassId)
                ->where('class_session_id', $classSessionId)
                ->where('event_type', 'deduct')
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
    }

    /**
     * Recompute UsedSessions / RemainingSessions from the ledger and persist.
     */
    public static function recomputeCounters(int $studentClassId): void
    {
        DB::transaction(function () use ($studentClassId) {
            $sc = StudentClass::where('ID', $studentClassId)->lockForUpdate()->first();
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
                ->whereIn('source', ['attendance', 'retro_leave', 'status_adjust'])
                ->selectRaw("SUM(CASE WHEN event_type = 'deduct' THEN 1 ELSE 0 END) - SUM(CASE WHEN event_type = 'reverse' THEN 1 ELSE 0 END) as net")
                ->value('net');
            $ledgerUsed = max(0, (int) ($ledgerUsed ?? 0));
            $usedByAttendance = max($attendanceUsed, $ledgerUsed, $classSessionUsed, $lrOrphan);

            $isSessionMode = (string) ($sc->ScheduleMode ?? 'count') === 'count';
            $sessionCount  = max(0, (int) ($sc->SessionCount ?? 0));
            $perSession    = max(1, $sc->perSessionMinutes());

            if ($isSessionMode && $sessionCount > 0) {
                $purchasedMinutes = $sessionCount * $perSession;

                // #613 A1：是否存在「部分時數」事件（minutes 已記錄且 ≠ 整堂）。
                // 否 → 完全沿用既有 count-based 邏輯（行為 byte-identical），僅補寫衍生分鐘欄。
                // 是 → 分鐘為權威，RemainingSessions 改為 ROUND_HALF_UP 衍生顯示值。
                $hasPartial = SessionDeductionLedger::query()
                    ->where('student_class_id', $studentClassId)
                    ->whereIn('source', ['attendance', 'retro_leave', 'status_adjust'])
                    ->whereNotNull('minutes')
                    ->where('minutes', '!=', $perSession)
                    ->exists();

                if ($hasPartial) {
                    $netMinutes = (int) (SessionDeductionLedger::query()
                        ->where('student_class_id', $studentClassId)
                        ->whereIn('source', ['attendance', 'retro_leave', 'status_adjust'])
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
            $partialMinutes = self::resolvePartialMakeupMinutes($sc, $resolvedClassSessionId);

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
     * #613 A1：補課（schedules.type='extra'）只覆蓋部分時數時，回傳該補課實際分鐘
     * （上限為每堂分鐘）。非補課、完整時長補課、或時間資料不足 → null（＝整堂，行為不變）。
     * 刻意只對補課做比例扣除，正常課堂一律整堂，最小化 blast radius。
     */
    private static function resolvePartialMakeupMinutes(StudentClass $sc, int $classSessionId): ?int
    {
        if ($classSessionId <= 0) {
            return null;
        }
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
        if ($mins <= 0 || $mins >= $perSession) {
            return null; // 完整（或更長）時長補課 → 整堂，保持 byte-identical
        }

        return $mins;
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
