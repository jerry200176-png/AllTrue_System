<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * BusinessDigestService — read-only business-intelligence & anomaly metrics.
 *
 * Pure query surface (SELECT only) consumed by the ops:business-digest command
 * and director Operations Trust API. Separated from the command (presentation)
 * per ADR-003. Optional campus scope joins Student.CampusID — never returns other campuses.
 */
class BusinessDigestService
{
    /** @return array<string,mixed> */
    public function metrics(?int $campusId = null): array
    {
        $m = [
            'generated_at' => now()->toIso8601String(),
            'campus_id' => $campusId,
            'revenue' => [
                'stranded_sessions' => $this->strandedSessions($campusId),
                'stranded_amount' => round($this->strandedAmount($campusId), 0),
                'unpaid_active_courses' => $this->unpaidActiveCourses($campusId),
            ],
            'retention' => array_merge(
                ['no_upcoming_students' => $this->retentionRiskStudents($campusId)],
                $this->retentionDecomposition($campusId)
            ),
            'data_quality' => [
                'attended_without_lr' => $this->attendedWithoutLr($campusId),
                'cross_sc_duplicate' => $this->crossScDuplicate($campusId),
                'remaining_divergent' => $this->remainingDivergent($campusId),
            ],
            'coverage' => ['sessions_next_7d' => $this->sessionsNext7d($campusId)],
        ];
        $m['trust'] = $this->trustFromMetrics($m);

        return $m;
    }

    /**
     * Measurable Trust KPIs for director Operations Center (F1).
     * Invoice Trust is forward-only by Founder F3 — never claims historical invoices are fixed.
     *
     * @param array<string,mixed> $m
     * @return array<string,mixed>
     */
    public function trustFromMetrics(array $m): array
    {
        $stranded = (int) ($m['revenue']['stranded_sessions'] ?? 0);
        $dup = (int) ($m['data_quality']['cross_sc_duplicate'] ?? 0);
        $next7 = (int) ($m['coverage']['sessions_next_7d'] ?? 0);
        $divergent = (int) ($m['data_quality']['remaining_divergent'] ?? 0);
        $dormant = (int) ($m['retention']['dormant_prepaid_students'] ?? 0);

        return [
            'paid_class_visibility' => [
                'status' => $stranded === 0 ? 'green' : 'red',
                'stranded_sessions' => $stranded,
                'stranded_amount' => (float) ($m['revenue']['stranded_amount'] ?? 0),
                'label' => '已付堂可見性',
                'summary' => $stranded === 0
                    ? '已付預付堂均有未來排程'
                    : "有 {$stranded} 堂已付餘額尚未排上未來課表",
            ],
            'calendar_trust' => [
                'status' => ($next7 > 0 && $dup === 0) ? 'green' : ($next7 === 0 ? 'red' : 'yellow'),
                'sessions_next_7d' => $next7,
                'cross_sc_duplicate' => $dup,
                'label' => '行事曆可信度',
                'summary' => $next7 === 0
                    ? '未來 7 天沒有任何已物化課表'
                    : ($dup > 0 ? "未來 7 天有課，但偵測到 {$dup} 組跨約重複堂" : "未來 7 天有 {$next7} 堂"),
            ],
            'ledger_trust' => [
                'status' => $divergent === 0 ? 'green' : 'red',
                'remaining_divergent' => $divergent,
                'label' => '堂數帳本一致',
                'summary' => $divergent === 0
                    ? '剩餘堂數與購買／已用一致'
                    : "有 {$divergent} 筆課程剩餘堂數對不上",
            ],
            'invoice_trust' => [
                // F3: historical invoices are not rewritten; surface policy + soft unpaid signal only.
                'status' => 'policy',
                'policy' => 'forward_only_no_historical_amend',
                'unpaid_active_courses' => (int) ($m['revenue']['unpaid_active_courses'] ?? 0),
                'label' => '帳單可信度',
                'summary' => '歷史帳單不改數字；僅保證往後續期正確（完整稽核保留）',
            ],
            'dormant_hold' => [
                // F2: keep eligibility, mark as dormant reminder, never auto-generate.
                'status' => $dormant === 0 ? 'green' : 'yellow',
                'students' => $dormant,
                'recoverable_ntd' => (int) ($m['retention']['dormant_prepaid_recoverable_ntd'] ?? 0),
                'label' => '休眠保留（需提醒）',
                'summary' => $dormant === 0
                    ? '沒有已付休眠合約待聯繫'
                    : "{$dormant} 位學生保留資格、勿自動排課，請定期聯繫",
                'auto_generate' => false,
            ],
        ];
    }

    /**
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

    private function strandedBase(?int $campusId)
    {
        $q = DB::table('StudentClass as sc')
            ->where(fn ($w) => $w->where('sc.Stop', 0)->orWhereNull('sc.Stop'))
            ->where('sc.ScheduleMode', 'count')
            ->where('sc.RemainingSessions', '>', 0)
            ->whereNotExists(function ($e) {
                $e->select(DB::raw(1))->from('ClassSession as cs')
                    ->whereColumn('cs.StudentClassID', 'sc.ID')
                    ->whereRaw('cs.SessionDate >= CURDATE()')
                    ->whereRaw("LOWER(cs.Status) NOT IN ('cancelled','voided')");
            });
        if ($campusId !== null && $campusId > 0) {
            $q->join('Student as s', 's.id', '=', 'sc.StudentID')
                ->where('s.CampusID', $campusId);
        }

        return $q;
    }

    private function strandedSessions(?int $campusId): int
    {
        return (int) $this->strandedBase($campusId)->sum('sc.RemainingSessions');
    }

    private function strandedAmount(?int $campusId): float
    {
        return (float) $this->strandedBase($campusId)->sum(DB::raw('sc.RemainingSessions * COALESCE(sc.Rate, 0)'));
    }

    private function unpaidActiveCourses(?int $campusId): int
    {
        $q = DB::table('StudentClass as sc')
            ->where('sc.Stop', 0)
            ->where(fn ($w) => $w->where('sc.Paid', 0)->orWhereNull('sc.Paid')->orWhere('sc.RemainingSessions', '<=', 2));
        if ($campusId !== null && $campusId > 0) {
            $q->join('Student as s', 's.id', '=', 'sc.StudentID')->where('s.CampusID', $campusId);
        }

        return (int) $q->count('sc.ID');
    }

    private function retentionRiskStudents(?int $campusId): int
    {
        $q = DB::table('Student as s')
            ->where('s.enable', 1)
            ->whereExists(function ($e) {
                $e->select(DB::raw(1))->from('StudentClass as sc')
                    ->whereColumn('sc.StudentID', 's.id')->where('sc.Stop', 0);
            })
            ->whereNotExists(function ($e) {
                $e->select(DB::raw(1))->from('StudentClass as sc')
                    ->join('ClassSession as cs', 'cs.StudentClassID', '=', 'sc.ID')
                    ->whereColumn('sc.StudentID', 's.id')
                    ->whereRaw('cs.SessionDate BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)')
                    ->whereRaw("LOWER(cs.Status) NOT IN ('cancelled','voided')");
            });
        if ($campusId !== null && $campusId > 0) {
            $q->where('s.CampusID', $campusId);
        }

        return (int) $q->count();
    }

    /**
     * @return array{dormant_prepaid_students:int,dormant_prepaid_recoverable_ntd:int,reenroll_candidates:int}
     */
    private function retentionDecomposition(?int $campusId): array
    {
        $q = DB::table('Student as s')
            ->join('StudentClass as sc', function ($j) {
                $j->on('sc.StudentID', '=', 's.id')->where('sc.Stop', 0);
            })
            ->where('s.enable', 1)
            ->whereNotExists(function ($e) {
                $e->select(DB::raw(1))->from('StudentClass as sc2')
                    ->join('ClassSession as cs', 'cs.StudentClassID', '=', 'sc2.ID')
                    ->whereColumn('sc2.StudentID', 's.id')
                    ->whereRaw('cs.SessionDate BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)')
                    ->whereRaw("LOWER(cs.Status) NOT IN ('cancelled','voided')");
            });
        if ($campusId !== null && $campusId > 0) {
            $q->where('s.CampusID', $campusId);
        }

        $rows = $q->groupBy('s.id')
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

    private function attendedWithoutLr(?int $campusId): int
    {
        $q = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->whereRaw("LOWER(cs.Status) IN ('attended','late','absent')")
            ->whereRaw("CONCAT(cs.SessionDate, ' ', COALESCE(cs.StartTime, '00:00:00')) <= NOW()")
            ->whereNotExists(function ($e) {
                $e->select(DB::raw(1))->from('LearningRecord as lr')
                    ->whereColumn('lr.ClassSessionID', 'cs.id')->whereNull('lr.VoidedAt');
            });
        if ($campusId !== null && $campusId > 0) {
            $q->join('Student as s', 's.id', '=', 'sc.StudentID')->where('s.CampusID', $campusId);
        }

        return (int) $q->count('cs.id');
    }

    private function crossScDuplicate(?int $campusId): int
    {
        $campusFilter = ($campusId !== null && $campusId > 0)
            ? ' AND s.CampusID = ' . (int) $campusId
            : '';

        return (int) DB::table(DB::raw('(
            SELECT 1 FROM ClassSession cs
            JOIN StudentClass sc ON sc.ID = cs.StudentClassID
            JOIN Student s ON s.id = sc.StudentID
            WHERE LOWER(cs.Status) IN (\'attended\',\'completed\')
            ' . $campusFilter . '
            GROUP BY sc.StudentID, cs.SessionDate, SUBSTRING(cs.StartTime,1,5)
            HAVING COUNT(*) > 1 AND COUNT(DISTINCT cs.StudentClassID) > 1
        ) d'))->count();
    }

    private function remainingDivergent(?int $campusId): int
    {
        $q = DB::table('StudentClass as sc')
            ->where('sc.ScheduleMode', 'count')
            ->whereNotNull('sc.SessionCount')->whereNotNull('sc.RemainingSessions')->whereNotNull('sc.UsedSessions')
            ->whereRaw('sc.RemainingSessions <> (sc.SessionCount - sc.UsedSessions)');
        if ($campusId !== null && $campusId > 0) {
            $q->join('Student as s', 's.id', '=', 'sc.StudentID')->where('s.CampusID', $campusId);
        }

        return (int) $q->count('sc.ID');
    }

    private function sessionsNext7d(?int $campusId): int
    {
        $q = DB::table('ClassSession as cs')
            ->whereRaw('cs.SessionDate BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)')
            ->whereRaw("LOWER(cs.Status) NOT IN ('cancelled','voided')");
        if ($campusId !== null && $campusId > 0) {
            $q->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
                ->join('Student as s', 's.id', '=', 'sc.StudentID')
                ->where('s.CampusID', $campusId);
        }

        return (int) $q->count('cs.id');
    }
}
