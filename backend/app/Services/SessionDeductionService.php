<?php

namespace App\Services;

use App\Models\LearningRecord;
use App\Models\SessionDeductionLedger;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SessionDeductionService
{
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

            $ledgerUsed = SessionDeductionLedger::netCount($studentClassId);

            $isSessionMode = (string) ($sc->ScheduleMode ?? 'count') === 'count';
            $sessionCount  = max(0, (int) ($sc->SessionCount ?? 0));

            if ($isSessionMode && $sessionCount > 0) {
                $usedSessions = min($sessionCount, $ledgerUsed);
                $sc->UsedSessions      = $usedSessions;
                $sc->RemainingSessions  = max(0, $sessionCount - $usedSessions);
                $sc->Stop               = $sc->RemainingSessions <= 0 ? 1 : 0;
                if ($sc->RemainingSessions <= 2) {
                    $sc->Paid = 0;
                }
            } else {
                $sc->UsedSessions      = $ledgerUsed;
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
        self::ensureLedgerSeeded($studentClass);
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
            $hasLrDeducted = Schema::hasColumn('LearningRecord', 'SessionDeducted');

            // If already deducted by approved LearningRecord, just mark sign-in and sync.
            if ($hasLrDeducted && $resolvedClassSessionId > 0) {
                $alreadyByLr = LearningRecord::active()
                    ->where('ClassSessionID', $resolvedClassSessionId)
                    ->where('SessionDeducted', true)
                    ->exists();
                if ($alreadyByLr) {
                    if ($signIn && !$signIn->SessionDeducted) {
                        $signIn->SessionDeducted = true;
                        $signIn->save();
                    }
                    self::syncCounters($sc);
                    return false;
                }
            }

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

    // ─── ledger seeding (bridge from flag-based to ledger) ────────

    /**
     * Seed ledger entries from existing SessionDeducted flags on
     * StudentSignIn / LearningRecord so that old data is captured.
     * Only runs once per student_class in the ledger (fast no-op after).
     */
    private static function ensureLedgerSeeded(StudentClass $sc): void
    {
        $alreadySeeded = SessionDeductionLedger::where('student_class_id', $sc->ID)->exists();
        if ($alreadySeeded) {
            return;
        }

        $hasLrDeducted = Schema::hasColumn('LearningRecord', 'SessionDeducted');

        $attendanceIds = StudentSignIn::where('StudentClassID', $sc->ID)
            ->active()
            ->where('SessionDeducted', true)
            ->whereNotNull('ClassSessionID')
            ->where('ClassSessionID', '>', 0)
            ->pluck('ClassSessionID')
            ->unique()
            ->values()
            ->all();

        $lrIds = [];
        if ($hasLrDeducted) {
            $lrIds = LearningRecord::where('StudentClassID', $sc->ID)
                ->active()
                ->where('SessionDeducted', true)
                ->whereNotNull('ClassSessionID')
                ->where('ClassSessionID', '>', 0)
                ->pluck('ClassSessionID')
                ->unique()
                ->values()
                ->all();
        }

        $allSessionIds = array_unique(array_merge($attendanceIds, $lrIds));

        $noSessionAttendance = StudentSignIn::where('StudentClassID', $sc->ID)
            ->active()
            ->where('SessionDeducted', true)
            ->where(function ($q) {
                $q->whereNull('ClassSessionID')->orWhere('ClassSessionID', 0);
            })
            ->count();

        $noSessionLr = 0;
        if ($hasLrDeducted) {
            $noSessionLr = LearningRecord::where('StudentClassID', $sc->ID)
                ->active()
                ->where('SessionDeducted', true)
                ->where(function ($q) {
                    $q->whereNull('ClassSessionID')->orWhere('ClassSessionID', 0);
                })
                ->count();
        }

        $rows = [];
        $now  = now();

        foreach ($allSessionIds as $csId) {
            $rows[] = [
                'student_class_id' => $sc->ID,
                'class_session_id' => $csId,
                'event_type'       => 'deduct',
                'source'           => 'seed',
                'created_by'       => null,
                'note'             => 'Auto-seeded from flag data',
                'created_at'       => $now,
                'updated_at'       => $now,
            ];
        }

        $orphanCount = max($noSessionAttendance, $noSessionLr);
        for ($i = 0; $i < $orphanCount; $i++) {
            $rows[] = [
                'student_class_id' => $sc->ID,
                'class_session_id' => null,
                'event_type'       => 'deduct',
                'source'           => 'seed',
                'created_by'       => null,
                'note'             => 'Auto-seeded (no session link)',
                'created_at'       => $now,
                'updated_at'       => $now,
            ];
        }

        if (!empty($rows)) {
            SessionDeductionLedger::insert($rows);
        }
    }
}
