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
        $m['decision_center'] = $this->decisionCenter($m);

        return $m;
    }

    /**
     * Decision Center: one Trust Score + only today's actionable decisions.
     *
     * Scoring rules (Measure Phase):
     * - Campus-scoped only — not cross-campus comparable.
     * - Critical risks use HARD CAPS (cannot be diluted by other greens).
     * - Dormant is a retention_hold (valid business state), soft penalty only.
     *
     * @param array<string,mixed> $m
     * @return array<string,mixed>
     */
    public function decisionCenter(array $m): array
    {
        $campusId = isset($m['campus_id']) ? (int) $m['campus_id'] : null;
        $decisions = [];
        $hardCaps = [];
        $softPenalty = 0;

        $stranded = (int) ($m['revenue']['stranded_sessions'] ?? 0);
        $strandedAmt = (float) ($m['revenue']['stranded_amount'] ?? 0);
        $next7 = (int) ($m['coverage']['sessions_next_7d'] ?? 0);
        $dup = (int) ($m['data_quality']['cross_sc_duplicate'] ?? 0);
        $divergent = (int) ($m['data_quality']['remaining_divergent'] ?? 0);
        $dormant = (int) ($m['retention']['dormant_prepaid_students'] ?? 0);
        $dormantNtd = (int) ($m['retention']['dormant_prepaid_recoverable_ntd'] ?? 0);

        if ($stranded > 0) {
            $people = $this->strandedPeople($campusId, 20);
            $hardCaps[] = ['key' => 'stranded_paid', 'max_score' => 45, 'reason' => 'critical_not_dilutable'];
            $decisions[] = [
                'key' => 'stranded_paid',
                'severity' => 'critical',
                'risk_kind' => 'system_fault',
                'title' => "{$stranded} 堂已付還沒排進未來課表",
                'why' => '家長已付但行事曆看不到課，客訴風險最高。',
                'next_step' => '從下方名單選人：補固定時段；暫時不上課就聯繫休眠（勿自動排課）。',
                'action_label' => '打開名單處理',
                'target' => 'course-mgmt',
                'owner' => 'director',
                'one_click_resolve' => false,
                'has_drilldown' => true,
                'detail' => $strandedAmt > 0 ? ('約 NT$' . number_format((int) round($strandedAmt))) : null,
                'people' => $people,
                'people_total' => count($people),
            ];
        }

        if ($next7 === 0) {
            $hardCaps[] = ['key' => 'calendar_empty_week', 'max_score' => 40, 'reason' => 'critical_not_dilutable'];
            $decisions[] = [
                'key' => 'calendar_empty_week',
                'severity' => 'critical',
                'risk_kind' => 'system_fault',
                'title' => '未來 7 天完全沒有課表',
                'why' => '師長會以為本週沒課，也可能是向前產生停擺。',
                'next_step' => '看行事曆確認後，回課程管理檢查缺固定上課日的合約。',
                'action_label' => '去看行事曆',
                'target' => 'calendar',
                'owner' => 'director',
                'one_click_resolve' => false,
                'has_drilldown' => false,
                'detail' => null,
                'people' => [],
                'people_total' => 0,
            ];
        } elseif ($dup > 0) {
            $softPenalty += min(15, 5 + $dup);
            $decisions[] = [
                'key' => 'calendar_duplicate',
                'severity' => 'warning',
                'risk_kind' => 'data_quality',
                'title' => "偵測到 {$dup} 組跨約重複堂",
                'why' => '同學生同時段可能兩張卡，點名/評量會亂。',
                'next_step' => '打開行事曆對照本週，整理舊約殘留或重複堂。',
                'action_label' => '去看行事曆',
                'target' => 'calendar',
                'owner' => 'director',
                'one_click_resolve' => false,
                'has_drilldown' => false,
                'detail' => null,
                'people' => [],
                'people_total' => 0,
            ];
        }

        if ($divergent > 0) {
            $softPenalty += min(20, 8 + $divergent);
            $decisions[] = [
                'key' => 'ledger_divergent',
                'severity' => 'warning',
                'risk_kind' => 'data_quality',
                'title' => "{$divergent} 筆課程剩餘堂數對不起來",
                'why' => '家長問剩幾堂時系統可能講錯。',
                'next_step' => '到課程管理核對堂數；改帳走核准（不作廢自助）。',
                'action_label' => '去核對堂數',
                'target' => 'course-mgmt',
                'owner' => 'director',
                'one_click_resolve' => false,
                'has_drilldown' => false,
                'detail' => null,
                'people' => [],
                'people_total' => 0,
            ];
        }

        if ($dormant > 0) {
            // Soft informational card only — legitimate retention_hold must NOT permanently block green.
            // Overdue-untreated dormant penalty is deferred until disposition tracking exists (Hypothesis).
            $people = $this->dormantPeople($campusId, 20);
            $decisions[] = [
                'key' => 'dormant_hold',
                'severity' => 'warning',
                'risk_kind' => 'retention_hold',
                'title' => "{$dormant} 位已付休眠要聯繫",
                'why' => '這是保留資格（合法），不是系統故障；追蹤 owner／聯繫／下一步即可，不要求清零。',
                'next_step' => '從下方名單逐一連絡：恢復上課、繼續暫停、或討論結案方向（勿自動排課）。',
                'action_label' => '打開聯繫名單',
                'target' => 'course-mgmt',
                'owner' => 'director',
                'one_click_resolve' => false,
                'has_drilldown' => true,
                'detail' => $dormantNtd > 0 ? ('約可回收 NT$' . number_format($dormantNtd)) : null,
                'people' => $people,
                'people_total' => count($people),
            ];
        }

        $score = 100 - $softPenalty;
        foreach ($hardCaps as $cap) {
            $score = min($score, (int) $cap['max_score']);
        }
        $score = max(0, min(100, $score));
        $hasCritical = count($hardCaps) > 0;
        if ($hasCritical) {
            $status = 'red';
        } else {
            // Score-based: legitimate dormant alone must be allowed to stay green.
            $status = $score >= 90 ? 'green' : ($score >= 70 ? 'yellow' : 'red');
        }
        $criticalCount = count(array_filter($decisions, fn ($d) => $d['severity'] === 'critical'));
        $warningCount = count($decisions) - $criticalCount;
        $onlyDormant = count($decisions) === 1 && (($decisions[0]['key'] ?? '') === 'dormant_hold');
        $headline = count($decisions) === 0
            ? '今天課表與剩課看起來可信，先處理下方每日待辦即可。'
            : ($hasCritical
                ? ('今天有 ' . $criticalCount . ' 件 Critical 必須先處理（分數已被硬門檻封頂）。')
                : ($onlyDormant
                    ? '無 Critical。休眠保留是合法狀態：請指定聯繫與下一步（不要求清零，也不因此無法變綠）。'
                    : ('今天有 ' . count($decisions) . ' 件信任事項要注意。')));

        return [
            'score' => $score,
            'max' => 100,
            'status' => $status,
            'headline' => $headline,
            'critical_count' => $criticalCount,
            'warning_count' => $warningCount,
            'decisions' => $decisions,
            'score_rules' => [
                'campus_scoped' => true,
                'cross_campus_comparable' => false,
                'critical_uses_hard_cap' => true,
                'hard_caps' => $hardCaps,
                'soft_penalty' => $softPenalty,
                'dormant_is_retention_hold' => true,
                'dormant_does_not_block_green' => true,
                'dormant_count_not_success_metric' => true,
                'overdue_untreated_dormant_penalty' => 'deferred_hypothesis',
            ],
            'policy_notes' => [
                '歷史帳單數字不改；只保證往後續期正確（完整稽核保留）。',
                '催繳仍由主任人工處理，系統不自動傳訊息給家長。',
                '休眠保留是合法狀態：Score 不因合法 dormant 永久無法變綠；逾期未處置才應扣分（處置追蹤尚未上線前暫不自動扣）。',
                '本分校分數不可與其他分校直接比較（規模不同）。',
            ],
        ];
    }

    /**
     * Stranded prepaid courses for drill-down (no phone / email).
     *
     * @return list<array<string,mixed>>
     */
    public function strandedPeople(?int $campusId, int $limit = 20): array
    {
        $q = DB::table('StudentClass as sc')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->where(fn ($w) => $w->where('sc.Stop', 0)->orWhereNull('sc.Stop'))
            ->where('sc.ScheduleMode', 'count')
            ->where('sc.RemainingSessions', '>', 0)
            ->whereNotExists(function ($e) {
                $e->select(DB::raw(1))->from('ClassSession as cs')
                    ->whereColumn('cs.StudentClassID', 'sc.ID')
                    ->whereRaw('cs.SessionDate >= CURDATE()')
                    ->whereRaw("LOWER(cs.Status) NOT IN ('cancelled','voided')");
            })
            ->orderByDesc('sc.RemainingSessions')
            ->limit(max(1, min(50, $limit)))
            ->select([
                'sc.ID as student_class_id',
                'sc.StudentID as student_id',
                's.name as student_name',
                'sc.SubjectID as subject_id',
                'sc.RemainingSessions as remaining_sessions',
                'sc.Rate as rate',
            ]);
        if ($campusId !== null && $campusId > 0) {
            $q->where('s.CampusID', $campusId);
        }

        return $q->get()->map(function ($r) {
            $remaining = (int) $r->remaining_sessions;
            $rate = (float) ($r->rate ?? 0);

            return [
                'student_class_id' => (int) $r->student_class_id,
                'student_id' => (int) $r->student_id,
                'student_name' => (string) $r->student_name,
                'subject_id' => (int) $r->subject_id,
                'remaining_sessions' => $remaining,
                'approx_amount' => (int) round($remaining * $rate),
                'why' => '已付餘額卻沒有未來課表',
                'next_step' => '補固定上課時段，或改做休眠聯繫',
            ];
        })->all();
    }

    /**
     * Dormant prepaid students for drill-down (no phone / email).
     *
     * @return list<array<string,mixed>>
     */
    public function dormantPeople(?int $campusId, int $limit = 20): array
    {
        $q = DB::table('StudentClass as sc')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->where('s.enable', 1)
            ->where(fn ($w) => $w->where('sc.Stop', 0)->orWhereNull('sc.Stop'))
            ->where('sc.ScheduleMode', 'count')
            ->where('sc.RemainingSessions', '>', 0)
            ->whereNotExists(function ($e) {
                $e->select(DB::raw(1))->from('StudentClass as sc2')
                    ->join('ClassSession as cs', 'cs.StudentClassID', '=', 'sc2.ID')
                    ->whereColumn('sc2.StudentID', 's.id')
                    ->whereRaw('cs.SessionDate BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)')
                    ->whereRaw("LOWER(cs.Status) NOT IN ('cancelled','voided')");
            })
            ->orderByDesc('sc.RemainingSessions')
            ->limit(max(1, min(50, $limit)))
            ->select([
                'sc.ID as student_class_id',
                's.id as student_id',
                's.name as student_name',
                'sc.SubjectID as subject_id',
                'sc.RemainingSessions as remaining_sessions',
                'sc.Rate as rate',
            ]);
        if ($campusId !== null && $campusId > 0) {
            $q->where('s.CampusID', $campusId);
        }

        return $q->get()->map(function ($r) {
            $remaining = (int) $r->remaining_sessions;
            $rate = (float) ($r->rate ?? 0);

            return [
                'student_class_id' => (int) $r->student_class_id,
                'student_id' => (int) $r->student_id,
                'student_name' => (string) $r->student_name,
                'subject_id' => (int) $r->subject_id,
                'remaining_sessions' => $remaining,
                'approx_amount' => (int) round($remaining * $rate),
                'why' => '已付但近 14 天沒排課（休眠保留，非系統故障）',
                'next_step' => '連絡家長：恢復／續暫停／結案方向',
            ];
        })->all();
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
