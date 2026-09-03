<?php

namespace App\Services;

use App\Models\Campus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branch Health V1 — read-only, explainable operating signals.
 *
 * This is deliberately not a quality score. It composes existing evidence
 * from BusinessDigestService and small aggregate queries, then returns one
 * contract for the HQ board. No PII and no writes belong in this service.
 */
class BranchHealthService
{
    public function __construct(private readonly BusinessDigestService $digest)
    {
    }

    /** @return array<string,mixed> */
    public function board(?int $branchId = null): array
    {
        $query = Campus::query()->orderBy('id');
        if (Schema::hasColumn('Campus', 'active')) {
            $query->where('active', true);
        }
        if ($branchId !== null) {
            $query->where('id', $branchId);
        }

        $campuses = $query->get(['id', 'name', 'code']);
        $rows = $campuses->map(fn (Campus $campus) => $this->summarize($campus))->values()->all();

        return [
            'data' => $rows,
            'meta' => [
                'version' => 'branch-health-v1',
                'generated_at' => now()->toIso8601String(),
                'scope' => $branchId === null ? 'active_campuses' : 'single_campus',
                'periods' => [
                    'current' => '目前資料',
                    'next_7_days' => '今天起 7 天',
                    'rolling_28_days' => '近 28 天（目前只有家長回饋訊號使用）',
                ],
                'ranking' => false,
                'score' => false,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function summarize(Campus $campus): array
    {
        $branchId = (int) $campus->getAttribute('id');
        $metrics = $this->digest->metrics($branchId);
        $parent = $this->parentSignals($branchId);
        $teacher = $this->teacherSignals($branchId);

        $dimensions = [
            'students' => $this->studentsDimension($metrics, $branchId),
            'teaching' => $this->teachingDimension($metrics),
            'parents' => $this->parentsDimension($parent),
            'teachers' => $this->teachersDimension($teacher),
            'operations' => $this->operationsDimension($metrics),
        ];

        $priority = collect($dimensions)
            ->filter(fn (array $dimension) => in_array($dimension['status'], ['red', 'yellow'], true))
            ->sortBy(fn (array $dimension) => $dimension['status'] === 'red' ? 0 : 1)
            ->first();
        $hasUnavailable = collect($dimensions)->contains(fn (array $dimension) => $dimension['status'] === 'unavailable');

        return [
            'branch_id' => $branchId,
            'branch_name' => (string) ($campus->name ?? ('分校 #' . $branchId)),
            'branch_code' => (string) ($campus->code ?? ''),
            'status' => $priority['status'] ?? ($hasUnavailable ? 'yellow' : 'green'),
            'headline' => $priority['next_step'] ?? ($hasUnavailable ? '部分維度尚未接入，不能視為完整健康。' : '目前沒有命中已接入的優先訊號。'),
            'dimensions' => $dimensions,
            'limitations' => [
                '不是分校總分或排名。',
                '教師流失、教師 capacity、完整續班率與家長客訴尚未接入。',
                '沒有可靠 snapshot 的歷史趨勢不會被假造。',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function studentsDimension(array $metrics, int $branchId): array
    {
        $retention = $metrics['retention'] ?? [];
        $signals = [
            $this->signal('active_students', '活躍學生', $this->activeStudents($branchId)),
            $this->signal('no_upcoming_students', '14 天沒有未來課的學生', (int) ($retention['no_upcoming_students'] ?? 0)),
            $this->signal('dormant_prepaid_students', '已付但暫無課的學生', (int) ($retention['dormant_prepaid_students'] ?? 0)),
            $this->signal('reenroll_candidates', '可能需要續課聯繫', (int) ($retention['reenroll_candidates'] ?? 0)),
        ];
        $attention = (int) ($retention['dormant_prepaid_students'] ?? 0)
            + (int) ($retention['reenroll_candidates'] ?? 0);

        return $this->dimension(
            $attention > 0 ? 'yellow' : 'green',
            $signals,
            'current',
            'BusinessDigestService：retention decomposition + Student.CampusID',
            $attention > 0 ? '先抽查沒有未來課的學生，確認是合法休眠、續課，還是課表缺漏。' : '目前沒有命中學生留存訊號。'
        );
    }

    /** @return array<string,mixed> */
    private function teachingDimension(array $metrics): array
    {
        $quality = $metrics['data_quality'] ?? [];
        $critical = (int) ($quality['attended_without_lr'] ?? 0)
            + (int) ($quality['cross_sc_duplicate'] ?? 0)
            + (int) ($quality['scheduled_cross_sc'] ?? 0)
            + (int) ($quality['orphan_stop_scheduled'] ?? 0);
        $watch = (int) ($quality['remaining_divergent_reviewable'] ?? 0);
        $signals = [
            $this->signal('attended_without_lr', '已上課但沒有學習紀錄', (int) ($quality['attended_without_lr'] ?? 0)),
            $this->signal('cross_sc_duplicate', '已發生重疊課程', (int) ($quality['cross_sc_duplicate'] ?? 0)),
            $this->signal('scheduled_cross_sc', '未來排課重疊', (int) ($quality['scheduled_cross_sc'] ?? 0)),
            $this->signal('orphan_stop_scheduled', '停用課程仍有未來堂', (int) ($quality['orphan_stop_scheduled'] ?? 0)),
            $this->signal('sessions_next_7d', '未來 7 天課堂', (int) ($metrics['coverage']['sessions_next_7d'] ?? 0)),
        ];

        return $this->dimension(
            $critical > 0 ? 'red' : ($watch > 0 || (int) ($metrics['coverage']['sessions_next_7d'] ?? 0) === 0 ? 'yellow' : 'green'),
            $signals,
            'next_7_days',
            'BusinessDigestService：data_quality + ClassSession coverage',
            $critical > 0 ? '先處理資料可信度或課表重疊，避免點名、評量與家長看到不同結果。' : ($watch > 0 ? '從剩餘堂數差異名單逐筆核對，不直接改寫堂數。' : '目前沒有命中教學資料品質訊號。')
        );
    }

    /** @param array{available:bool,unread_backlog:int} $parent */
    private function parentsDimension(array $parent): array
    {
        if (!$parent['available']) {
            return $this->dimension('unavailable', [], 'rolling_28_days', 'learning_record_feedbacks', '家長回饋資料尚未接入，不能判定正常。');
        }
        $backlog = $parent['unread_backlog'];
        return $this->dimension(
            $backlog >= 5 ? 'red' : ($backlog > 0 ? 'yellow' : 'green'),
            [$this->signal('unread_feedback_backlog', '尚未讀取的家長回饋', $backlog)],
            'rolling_28_days',
            'learning_record_feedbacks.last_read_by_director_at',
            $backlog > 0 ? '打開家長回饋待處理清單，先確認是否有逾期未回覆事項。' : '目前沒有未讀家長回饋。'
        );
    }

    /** @param array{active_teachers:int,unassigned_sessions_next_7d:int} $teacher */
    private function teachersDimension(array $teacher): array
    {
        $unassigned = $teacher['unassigned_sessions_next_7d'];
        return $this->dimension(
            $unassigned > 0 ? 'red' : 'green',
            [
                $this->signal('active_teachers', '啟用中的教師', $teacher['active_teachers']),
                $this->signal('unassigned_sessions_next_7d', '未來 7 天未指派教師堂數', $unassigned),
            ],
            'next_7_days',
            'UserCampus + StudentClass.TeacherID + ClassSession',
            $unassigned > 0 ? '先找出未指派教師的堂次，補上人力或調整課表。' : '目前沒有未指派教師的未來堂次；教師流失與 capacity 尚未接入。'
        );
    }

    /** @return array<string,mixed> */
    private function operationsDimension(array $metrics): array
    {
        $revenue = $metrics['revenue'] ?? [];
        $quality = $metrics['data_quality'] ?? [];
        $stranded = (int) ($revenue['stranded_sessions'] ?? 0);
        $next7 = (int) ($metrics['coverage']['sessions_next_7d'] ?? 0);
        $divergent = (int) ($quality['remaining_divergent_reviewable'] ?? 0);
        $signals = [
            $this->signal('stranded_sessions', '已付但未排入未來課表堂數', $stranded),
            $this->signal('remaining_divergent_reviewable', '剩餘堂數待核對課程', $divergent),
            $this->signal('unpaid_active_courses', '未付款或低餘額進行中課程', (int) ($revenue['unpaid_active_courses'] ?? 0)),
            $this->signal('sessions_next_7d', '未來 7 天課堂', $next7),
        ];

        return $this->dimension(
            $stranded > 0 || $next7 === 0 ? 'red' : ($divergent > 0 ? 'yellow' : 'green'),
            $signals,
            'next_7_days',
            'BusinessDigestService：revenue + coverage + data_quality',
            $stranded > 0 ? '先處理已付但未排課的課程，避免家長已付款卻看不到課。' : ($next7 === 0 ? '確認向前產生與固定上課日設定，避免整校沒有未來課表。' : ($divergent > 0 ? '先核對剩餘堂數差異，資料修復另走核准流程。' : '目前沒有命中營運訊號。'))
        );
    }

    /** @return array{available:bool,unread_backlog:int} */
    private function parentSignals(int $branchId): array
    {
        if (!Schema::hasTable('learning_record_feedbacks')) {
            return ['available' => false, 'unread_backlog' => 0];
        }

        $unread = DB::table('learning_record_feedbacks')
            ->where('campus_id', $branchId)
            ->where(fn ($q) => $q->whereNull('last_read_by_director_at')->orWhereColumn('last_read_by_director_at', '<', 'updated_at'))
            ->count();

        return ['available' => true, 'unread_backlog' => (int) $unread];
    }

    /** @return array{active_teachers:int,unassigned_sessions_next_7d:int} */
    private function teacherSignals(int $branchId): array
    {
        $activeTeachers = DB::table('UserCampus as uc')
            ->join('User as u', 'u.id', '=', 'uc.UserID')
            ->where('uc.CampusID', $branchId)
            ->where('u.type', 'T')
            ->where(function ($q) {
                $q->whereNull('u.status')->orWhere('u.status', 'active');
            })
            ->distinct('u.id')
            ->count('u.id');

        $unassigned = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as st', 'st.id', '=', 'sc.StudentID')
            ->where('st.CampusID', $branchId)
            ->whereBetween('cs.SessionDate', [now()->toDateString(), now()->addDays(7)->toDateString()])
            ->whereRaw("LOWER(cs.Status) = 'scheduled'")
            ->where(function ($q) {
                $q->whereNull('sc.TeacherID')->orWhere('sc.TeacherID', 0);
            })
            ->count('cs.id');

        return ['active_teachers' => (int) $activeTeachers, 'unassigned_sessions_next_7d' => (int) $unassigned];
    }

    private function activeStudents(int $branchId): int
    {
        return (int) DB::table('Student')->where('CampusID', $branchId)->where('enable', 1)->count();
    }

    /** @param list<array{key:string,label:string,value:int}> $signals */
    private function dimension(string $status, array $signals, string $period, string $source, string $nextStep): array
    {
        return [
            'status' => $status,
            'label' => match ($status) {
                'red' => '優先處理',
                'yellow' => '注意',
                'unavailable' => '待接資料',
                default => '正常',
            },
            'signals' => $signals,
            'period' => $period,
            'source' => $source,
            'next_step' => $nextStep,
        ];
    }

    /** @return array{key:string,label:string,value:int} */
    private function signal(string $key, string $label, int $value): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value];
    }
}
