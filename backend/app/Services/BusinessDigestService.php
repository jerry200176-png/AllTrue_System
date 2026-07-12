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
            'retention' => array_merge(
                ['no_upcoming_students' => $this->retentionRiskStudents()],
                $this->retentionDecomposition()
            ),
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
        if (($m['retention']['dormant_prepaid_students'] ?? 0) > 0) {
            $out[] = "dormant prepaid: {$m['retention']['dormant_prepaid_students']} students hold ~NT\${$m['retention']['dormant_prepaid_recoverable_ntd']} of paid-but-unscheduled balance — retain/refund/write-off decision (#1152).";
        }
        if (($m['retention']['reenroll_candidates'] ?? 0) > 0) {
            $out[] = "re-enrollment: {$m['retention']['reenroll_candidates']} active students have exhausted balance and no upcoming class — outreach opportunity (#1149).";
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

    /**
     * Split the raw retention-risk population (active course, no session in 14d) into the
     * two actionable segments so the digest is directable, not just a scary total:
     *   - dormant_prepaid: has a count-mode course with paid balance left → #1152 liability /
     *     recoverable revenue (Rate x remaining) — a Founder retain/refund/write-off decision.
     *   - reenroll_candidates: balance exhausted, no active month course → re-enrollment
     *     outreach opportunity (#1149), not a liability.
     * Aggregate only (no PII).
     *
     * @return array{dormant_prepaid_students:int,dormant_prepaid_recoverable_ntd:int,reenroll_candidates:int}
     */
    private function retentionDecomposition(): array
    {
        $rows = DB::table('Student as s')
            ->join('StudentClass as sc', function ($j) {
                $j->on('sc.StudentID', '=', 's.id')->where('sc.Stop', 0);
            })
            ->where('s.enable', 1)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('StudentClass as sc2')
                    ->join('ClassSession as cs', 'cs.StudentClassID', '=', 'sc2.ID')
                    ->whereColumn('sc2.StudentID', 's.id')
                    ->whereRaw('cs.SessionDate BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)')
                    ->whereRaw("LOWER(cs.Status) NOT IN ('cancelled','voided')");
            })
            ->groupBy('s.id')
            ->select([
                's.id',
                DB::raw("MAX(CASE WHEN sc.ScheduleMode='count' AND sc.RemainingSessions>0 THEN 1 ELSE 0 END) AS has_prepaid"),
                DB::raw("MAX(CASE WHEN sc.ScheduleMode='date' THEN 1 ELSE 0 END) AS has_month"),
                DB::raw("SUM(CASE WHEN sc.ScheduleMode='count' AND sc.RemainingSessions>0 THEN sc.RemainingSessions * COALESCE(sc.Rate,0) ELSE 0 END) AS recoverable"),
            ])
            ->get();

        $dormant = $rows->where('has_prepaid', 1);
        $reenroll = $rows->filter(fn ($r) => (int) $r->has_prepaid === 0 && (int) $r->has_month === 0);

        return [
            'dormant_prepaid_students' => $dormant->count(),
            'dormant_prepaid_recoverable_ntd' => (int) round($dormant->sum(fn ($r) => (float) $r->recoverable)),
            'reenroll_candidates' => $reenroll->count(),
        ];
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
