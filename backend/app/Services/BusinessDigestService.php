<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * BusinessDigestService — read-only business-intelligence & anomaly metrics.
 *
 * Pure query surface (SELECT only) consumed by the ops:business-digest command
 * and, later, by a dashboard endpoint / anomaly model. Separated from the command
 * (presentation) per ADR-003 so the numbers are directly testable without stdout.
 */
class BusinessDigestService
{
    /** @return array<string,mixed> */
    public function metrics(): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'revenue' => [
                'stranded_sessions' => $this->strandedSessions(),
                'stranded_amount' => round($this->strandedAmount(), 0),
                'unpaid_active_courses' => $this->unpaidActiveCourses(),
            ],
            'retention' => ['no_upcoming_students' => $this->retentionRiskStudents()],
            'data_quality' => [
                'attended_without_lr' => $this->attendedWithoutLr(),
                'cross_sc_duplicate' => $this->crossScDuplicate(),
                'remaining_divergent' => $this->remainingDivergent(),
            ],
            'coverage' => ['sessions_next_7d' => $this->sessionsNext7d()],
        ];
    }

    /**
     * Explainable threshold anomalies (the seed set an ML model would later generalise).
     *
     * @param array<string,mixed> $m
     * @return list<string>
     */
    public function anomalies(array $m): array
    {
        $out = [];
        if ($m['data_quality']['attended_without_lr'] > 0) {
            $out[] = 'attended sessions without a learning record > 0 — evaluation integrity regressed (should be 0).';
        }
        if ($m['revenue']['stranded_sessions'] > 0) {
            $out[] = "revenue at risk: {$m['revenue']['stranded_sessions']} prepaid sessions (~NT\${$m['revenue']['stranded_amount']}) owed but not scheduled (#1062).";
        }
        if ($m['coverage']['sessions_next_7d'] === 0) {
            $out[] = 'ZERO sessions materialized in the next 7 days — forward generation may be stalled.';
        }
        return $out;
    }

    private function strandedBase()
    {
        return DB::table('StudentClass as sc')
            ->where(fn ($q) => $q->where('sc.Stop', 0)->orWhereNull('sc.Stop'))
            ->where('sc.ScheduleMode', 'count')
            ->where('sc.RemainingSessions', '>', 0)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('ClassSession as cs')
                    ->whereColumn('cs.StudentClassID', 'sc.ID')
                    ->whereRaw('cs.SessionDate >= CURDATE()')
                    ->whereRaw("LOWER(cs.Status) NOT IN ('cancelled','voided')");
            });
    }

    private function strandedSessions(): int
    {
        return (int) $this->strandedBase()->sum('sc.RemainingSessions');
    }

    private function strandedAmount(): float
    {
        return (float) $this->strandedBase()->sum(DB::raw('sc.RemainingSessions * COALESCE(sc.Rate, 0)'));
    }

    private function unpaidActiveCourses(): int
    {
        return (int) DB::table('StudentClass')
            ->where('Stop', 0)
            ->where(fn ($q) => $q->where('Paid', 0)->orWhereNull('Paid')->orWhere('RemainingSessions', '<=', 2))
            ->count();
    }

    private function retentionRiskStudents(): int
    {
        return (int) DB::table('Student as s')
            ->where('s.enable', 1)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))->from('StudentClass as sc')
                    ->whereColumn('sc.StudentID', 's.id')->where('sc.Stop', 0);
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('StudentClass as sc')
                    ->join('ClassSession as cs', 'cs.StudentClassID', '=', 'sc.ID')
                    ->whereColumn('sc.StudentID', 's.id')
                    ->whereRaw('cs.SessionDate BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)')
                    ->whereRaw("LOWER(cs.Status) NOT IN ('cancelled','voided')");
            })
            ->count();
    }

    private function attendedWithoutLr(): int
    {
        return (int) DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->whereRaw("LOWER(cs.Status) IN ('attended','late','absent')")
            ->whereRaw("CONCAT(cs.SessionDate, ' ', COALESCE(cs.StartTime, '00:00:00')) <= NOW()")
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('LearningRecord as lr')
                    ->whereColumn('lr.ClassSessionID', 'cs.id')->whereNull('lr.VoidedAt');
            })
            ->count();
    }

    private function crossScDuplicate(): int
    {
        return (int) DB::table(DB::raw('(
            SELECT 1 FROM ClassSession cs
            JOIN StudentClass sc ON sc.ID = cs.StudentClassID
            WHERE LOWER(cs.Status) IN (\'attended\',\'completed\')
            GROUP BY sc.StudentID, cs.SessionDate, SUBSTRING(cs.StartTime,1,5)
            HAVING COUNT(*) > 1 AND COUNT(DISTINCT cs.StudentClassID) > 1
        ) d'))->count();
    }

    private function remainingDivergent(): int
    {
        return (int) DB::table('StudentClass')
            ->where('ScheduleMode', 'count')
            ->whereNotNull('SessionCount')->whereNotNull('RemainingSessions')->whereNotNull('UsedSessions')
            ->whereRaw('RemainingSessions <> (SessionCount - UsedSessions)')
            ->count();
    }

    private function sessionsNext7d(): int
    {
        return (int) DB::table('ClassSession')
            ->whereRaw('SessionDate BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)')
            ->whereRaw("LOWER(Status) NOT IN ('cancelled','voided')")
            ->count();
    }
}
