<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\SessionDeductionLedger;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use Illuminate\Support\Facades\DB;

class SessionDeductionService
{
    /**
     * Observable "已上堂數" per course: max of (扣點出缺勤、已完成堂次狀態、已核准評量所綁堂次)，
     * 避免僅依 SessionDeducted 時與課表/評量畫面不同步。
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

        $approvedLrBound = LearningRecord::query()
            ->whereIn('StudentClassID', $ids)
            ->where('Status', 'approved')
            ->where('ClassSessionID', '>', 0)
            ->groupBy('StudentClassID')
            ->selectRaw('StudentClassID, COUNT(DISTINCT ClassSessionID) as c')
            ->pluck('c', 'StudentClassID');

        // 已核准但未綁 ClassSessionID 的評量（舊資料／補登）：以日期去重估算堂數
        $orphanDateExpr = DB::connection()->getDriverName() === 'sqlite'
            ? 'substr(SessionDate, 1, 10)'
            : 'DATE(SessionDate)';
        $approvedLrOrphan = LearningRecord::query()
            ->whereIn('StudentClassID', $ids)
            ->where('Status', 'approved')
            ->where(function ($q) {
                $q->whereNull('ClassSessionID')->orWhere('ClassSessionID', '<=', 0);
            })
            ->whereNotNull('SessionDate')
            ->groupBy('StudentClassID')
            ->selectRaw("StudentClassID, COUNT(DISTINCT {$orphanDateExpr}) as c")
            ->pluck('c', 'StudentClassID');

        $out = [];
        foreach ($ids as $id) {
            $a = max(0, (int) ($deducted[$id] ?? 0));
            $b = max(0, (int) ($completedSessions[$id] ?? 0));
            $c = max(0, (int) ($approvedLrBound[$id] ?? 0));
            $d = max(0, (int) ($approvedLrOrphan[$id] ?? 0));
            $out[$id] = max($a, $b, $c, $d);
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
        ?string $note = null
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
            'created_by'       => $createdBy,
            'note'             => $note,
        ]);

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
        ?string $note = null
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

        SessionDeductionLedger::create([
            'student_class_id' => $studentClassId,
            'class_session_id' => $classSessionId ?: null,
            'event_type'       => 'reverse',
            'source'           => $source,
            'created_by'       => $createdBy,
            'note'             => $note,
        ]);

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

            $lrBound = LearningRecord::query()
                ->where('StudentClassID', $studentClassId)
                ->where('Status', 'approved')
                ->where('ClassSessionID', '>', 0)
                ->selectRaw('COUNT(DISTINCT ClassSessionID) as c')
                ->value('c');
            $lrBound = max(0, (int) ($lrBound ?? 0));

            $lrOrphan = LearningRecord::query()
                ->where('StudentClassID', $studentClassId)
                ->where('Status', 'approved')
                ->where(function ($q) {
                    $q->whereNull('ClassSessionID')->orWhere('ClassSessionID', '<=', 0);
                })
                ->whereNotNull('SessionDate')
                ->selectRaw(
                    DB::connection()->getDriverName() === 'sqlite'
                        ? "COUNT(DISTINCT substr(SessionDate, 1, 10)) as c"
                        : 'COUNT(DISTINCT DATE(SessionDate)) as c'
                )
                ->value('c');
            $lrOrphan = max(0, (int) ($lrOrphan ?? 0));

            $lrApprovedUsed = max($lrBound, $lrOrphan);

            // Keep ledger as a secondary safeguard for attendance-driven updates
            // (e.g. status transitions that may temporarily not have sign-in rows).
            // Explicitly exclude learning-record approval sources.
            $ledgerUsed = SessionDeductionLedger::query()
                ->where('student_class_id', $studentClassId)
                ->whereIn('source', ['attendance', 'retro_leave', 'status_adjust'])
                ->selectRaw("SUM(CASE WHEN event_type = 'deduct' THEN 1 ELSE 0 END) - SUM(CASE WHEN event_type = 'reverse' THEN 1 ELSE 0 END) as net")
                ->value('net');
            $ledgerUsed = max(0, (int) ($ledgerUsed ?? 0));
            $usedByAttendance = max($attendanceUsed, $ledgerUsed, $classSessionUsed, $lrApprovedUsed);

            $isSessionMode = (string) ($sc->ScheduleMode ?? 'count') === 'count';
            $sessionCount  = max(0, (int) ($sc->SessionCount ?? 0));

            if ($isSessionMode && $sessionCount > 0) {
                $usedSessions = min($sessionCount, $usedByAttendance);
                $sc->UsedSessions      = $usedSessions;
                $sc->RemainingSessions  = max(0, $sessionCount - $usedSessions);
                $sc->Stop               = $sc->RemainingSessions <= 0 ? 1 : 0;
                if ($sc->RemainingSessions <= 2) {
                    $sc->Paid = 0;
                }
            } else {
                $sc->UsedSessions      = $usedByAttendance;
                $sc->RemainingSessions  = 0;
                $sc->Stop               = 0;
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

            $deducted = false;
            DB::transaction(function () use ($sc, $signIn, $resolvedClassSessionId, &$deducted) {
                $wrote = self::deductForSession(
                    $sc->ID,
                    $resolvedClassSessionId > 0 ? $resolvedClassSessionId : null,
                    'attendance'
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

}
