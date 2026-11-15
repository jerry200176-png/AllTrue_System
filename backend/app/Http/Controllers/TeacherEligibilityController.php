<?php

namespace App\Http\Controllers;

use App\Services\SubjectUnitsTimelineService;
use App\Services\TeacherClassCalendar;
use App\Services\TeacherEligibilityPolicy;
use App\Support\FulltimePayrollLockStore;
use App\Support\FulltimeSettlementComposer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TeacherEligibilityController extends Controller
{
    public function __construct(private TeacherEligibilityPolicy $policy)
    {
    }

    /**
     * GET /api/v1/finance/teacher-eligibility
     * Director-facing, component-by-component eligibility report for full-time teachers.
     */
    public function index(Request $request)
    {
        $period = $this->resolvePeriod($request);
        if ($period['end']->lt(Carbon::parse(config('teacher_salary.effective_from'), 'Asia/Taipei'))) {
            return response()->json([
                'policy_version' => config('teacher_salary.policy_version'),
                'period' => ['type' => $period['type'], 'start' => $period['start']->toDateString(), 'end' => $period['end']->toDateString()],
                'teachers' => [],
                'total_teachers' => 0,
                'message' => '115.07制度生效日前沒有可計算資料。',
            ]);
        }

        $effectiveStart = $period['start']->lt(Carbon::parse(config('teacher_salary.effective_from'), 'Asia/Taipei'))
            ? Carbon::parse(config('teacher_salary.effective_from'), 'Asia/Taipei')->startOfDay()
            : $period['start'];
        $campusIds = $this->resolveCampusIds($request);
        if ($campusIds instanceof \Illuminate\Http\JsonResponse) {
            return $campusIds;
        }

        $lockBranchId = is_array($campusIds) && count($campusIds) === 1 ? (int) $campusIds[0] : null;
        $lockMonth = $period['start']->format('Y-m');
        $lockedRun = ($lockBranchId && $period['type'] === 'month')
            ? FulltimePayrollLockStore::lockedRun($lockBranchId, $lockMonth)
            : null;
        // A locked month is served from its snapshot. With compare=1 the month is also computed with today's rules and
        // each snapshot row gets the result next to it (Founder 2026-11-15: months locked under the old rules keep
        // their numbers; the difference has to be visible). Read-only; nothing is stored.
        $locked = null;
        if ($lockedRun) {
            $rows = FulltimePayrollLockStore::snapshotTeachers((int) $lockedRun->id);

            $locked = [
                'policy_version' => $lockedRun->policy_version ?: config('teacher_salary.policy_version'),
                'effective_from' => config('teacher_salary.effective_from'),
                'period' => ['type' => $period['type'], 'start' => $effectiveStart->toDateString(), 'end' => $period['end']->toDateString()],
                'components' => ['weekly_16_segments', 'holiday_16_hours', 'weekday_afternoon', 'special_performance', 'deductions', 'admin_allowance', 'cash_adjustments', 'subject_count_bonus', 'new_hire_guarantee'],
                'teachers' => $rows,
                'total_teachers' => count($rows),
                'branch_subject_total' => (float) $lockedRun->branch_subject_total,
                'lock' => [
                    'status' => 'locked',
                    'month' => $lockMonth,
                    'branch_id' => $lockBranchId,
                    'locked_at' => $lockedRun->locked_at,
                    'locked_by' => $lockedRun->locked_by,
                    'run_id' => $lockedRun->id,
                ],
            ];
            if (!$request->boolean('compare')) {
                return response()->json($locked);
            }
        }

        $teachers = DB::table('User as u')
            ->join('UserCampus as uc', 'uc.UserID', '=', 'u.id')
            ->where('u.type', 'T')
            ->where(function ($query) {
                $query->whereNull('u.status')->orWhere('u.status', 'active');
            })
            ->where(function ($query) {
                $query->whereNull('u.employment_type')->orWhere('u.employment_type', 'full_time');
            })
            ->when($campusIds !== null, fn ($query) => $query->whereIn('uc.CampusID', $campusIds))
            ->select('u.id', 'u.Name', 'u.employment_type')
            ->distinct()
            ->orderBy('u.Name')
            ->get();

        if ($teachers->isEmpty()) {
            return response()->json($locked !== null ? $this->withRecalculation($locked, []) : [
                'policy_version' => config('teacher_salary.policy_version'),
                'period' => ['type' => $period['type'], 'start' => $effectiveStart->toDateString(), 'end' => $period['end']->toDateString()],
                'teachers' => [],
                'total_teachers' => 0,
                'lock' => ['status' => 'draft', 'month' => $lockMonth, 'branch_id' => $lockBranchId],
            ]);
        }

        $teacherIds = $teachers->pluck('id')->map(fn ($id) => (int) $id)->all();
        // 每週16段 and the 假日／平日下午 rates all read the teacher calendar (ClassSession + live schedule exceptions,
        // all campuses), out to the last week's Sunday so a week that crosses the month end is whole.
        $weeks = $this->splitIntoWeeks($effectiveStart, $period['end']);
        $lessonsByTeacher = $weeks === [] ? [] : TeacherClassCalendar::load($weeks[0][0]->toDateString(), end($weeks)[1]->toDateString(), null, $teacherIds, true);
        // The totals are company-wide, so a teacher who belongs to several campuses would be paid the same bonus once
        // per campus. One campus settles the teacher; the others only name where.
        $settledElsewhere = [];
        if ($lockBranchId !== null) {
            $campusNames = [];
            foreach ($this->settlementCampusByTeacher($teacherIds, $lessonsByTeacher, $lockMonth) as $teacherId => $campusId) {
                if ($campusId === $lockBranchId) continue;
                $campusNames[$campusId] ??= (string) (DB::table('Campus')->where('id', $campusId)->value('name') ?? $campusId);
                $settledElsewhere[] = ['teacher_id' => $teacherId, 'teacher_name' => (string) ($teachers->firstWhere('id', $teacherId)->Name ?? ''), 'campus_id' => $campusId, 'campus_name' => $campusNames[$campusId]];
            }
            $moved = array_column($settledElsewhere, 'teacher_id');
            $teachers = $teachers->reject(fn ($teacher) => in_array((int) $teacher->id, $moved, true))->values();
            $teacherIds = array_values(array_diff($teacherIds, $moved));
        }
        $achievements = Schema::hasTable('teacher_payroll_achievements')
            ? DB::table('teacher_payroll_achievements')
                ->whereIn('teacher_id', $teacherIds)
                ->where(function ($query) use ($effectiveStart) {
                    $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $effectiveStart->toDateString());
                })
                ->where(function ($query) use ($period) {
                    $query->whereNull('starts_on')->orWhereDate('starts_on', '<=', $period['end']->toDateString());
                })
                ->where('status', '!=', 'withdrawn')
                ->get()
            : collect();

        $deductions = Schema::hasTable('teacher_payroll_deductions')
            ? DB::table('teacher_payroll_deductions')
                ->whereIn('teacher_id', $teacherIds)
                ->where(function ($query) use ($effectiveStart) {
                    $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $effectiveStart->toDateString());
                })
                ->where(function ($query) use ($period) {
                    $query->whereNull('starts_on')->orWhereDate('starts_on', '<=', $period['end']->toDateString());
                })
                ->where('status', '!=', 'withdrawn')
                ->get()
            : collect();

        // Founder 2026-11-15: a week with a company-wide closure day is not judged; it counts as full. Only days the
        // system infers (every lesson in the company called off) count: a hand-entered holiday event can be created and
        // approved by one director, so it must not be able to waive a week. Out to the last week's Sunday.
        $closureDates = $weeks === [] ? [] : $this->campusClosedDates(null, $weeks[0][0], end($weeks)[1]);

        $achievementByTeacher = $achievements->groupBy('teacher_id');
        $deductionByTeacher = $deductions->groupBy('teacher_id');
        $allowances = Schema::hasTable('teacher_payroll_admin_allowances')
            ? DB::table('teacher_payroll_admin_allowances')
                ->whereIn('teacher_id', $teacherIds)
                ->where(function ($query) use ($effectiveStart) {
                    $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $effectiveStart->toDateString());
                })
                ->where(function ($query) use ($period) {
                    $query->whereNull('starts_on')->orWhereDate('starts_on', '<=', $period['end']->toDateString());
                })
                ->where('status', '!=', 'withdrawn')
                ->get()
            : collect();
        $allowanceByTeacher = $allowances->groupBy('teacher_id');
        $cashRows = Schema::hasTable('teacher_payroll_cash_adjustments')
            ? DB::table('teacher_payroll_cash_adjustments')
                ->whereIn('teacher_id', $teacherIds)
                ->where(function ($query) use ($effectiveStart) {
                    $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $effectiveStart->toDateString());
                })
                ->where(function ($query) use ($period) {
                    $query->whereNull('starts_on')->orWhereDate('starts_on', '<=', $period['end']->toDateString());
                })
                ->where('status', '!=', 'withdrawn')
                ->get()
            : collect();
        $cashByTeacher = $cashRows->groupBy('teacher_id');
        $cashSourceAvailable = Schema::hasTable('teacher_payroll_cash_adjustments');
        $allowanceSourceAvailable = Schema::hasTable('teacher_payroll_admin_allowances');
        // Payroll subject counts are company/teacher scoped. The selected
        // campus only controls which teachers are listed; it must never
        // partition a teacher's cross-campus subject inputs.
        $subjectUnitsByTeacher = $this->globalSubjectUnitsByTeacher($teacherIds, $effectiveStart, $period['end']);

        $firstLessonByTeacher = $this->firstLessonDateByTeacher($teacherIds);

        $rows = $teachers->map(function ($teacher) use ($period, $effectiveStart, $weeks, $closureDates, $lessonsByTeacher, $achievementByTeacher, $deductionByTeacher, $allowanceByTeacher, $cashByTeacher, $cashSourceAvailable, $allowanceSourceAvailable, $subjectUnitsByTeacher, $firstLessonByTeacher) {
            $teacherId = (int) $teacher->id;
            $weeklyRows = [];
            foreach ($weeks as [$weekStart, $weekEnd]) {
                $weeklyRows[] = $this->evaluateWeek($weekStart, $weekEnd, $lessonsByTeacher[$teacherId] ?? [], $closureDates);
            }

            $weeklyStatus = $this->aggregateWeeklyStatus($weeklyRows);
            $judgedWeeks = array_column(array_filter($weeklyRows, fn ($week) => $week['metrics']['closure_dates'] === []), 'metrics');
            $subjectUnits = $subjectUnitsByTeacher[$teacherId] ?? null;
            $subjectCount = is_array($subjectUnits) ? ($subjectUnits['payroll_total'] ?? null) : null;

            $result = $this->policy->evaluate([
                'period_start' => $effectiveStart->toDateString(),
                'period_end' => $period['end']->toDateString(),
                'weekly' => ['segments' => 0], // replaced below by the per-week result
                // A closure week is not judged (Founder 2026-11-15): it stays out of both rates.
                'holiday_weekend_hours' => array_column($judgedWeeks, 'weekend_hours'),
                'holiday_waived_weeks' => count($weeklyRows) - count($judgedWeeks),
                'weekday_early_segments' => array_column($judgedWeeks, 'weekday_early_segments'),
                'achievements' => $achievementByTeacher->get($teacherId, collect())->map(fn ($row) => (array) $row)->all(),
                'deductions' => $deductionByTeacher->get($teacherId, collect())->map(fn ($row) => [
                    'deduction_key' => $row->deduction_key,
                    'status' => ($row->director_confirmed_at && $row->hq_approved_at) ? 'approved' : $row->status,
                    'starts_on' => $row->starts_on,
                    'ends_on' => $row->ends_on,
                ])->all(),
                'admin_allowances' => $allowanceByTeacher->get($teacherId, collect())->map(fn ($row) => [
                    'role_key' => $row->role_key,
                    'rate' => (float) $row->rate,
                    'status' => ($row->director_confirmed_at && $row->hq_approved_at) ? 'approved' : $row->status,
                    'starts_on' => $row->starts_on,
                    'ends_on' => $row->ends_on,
                ])->all(),
                'cash_adjustments' => $cashByTeacher->get($teacherId, collect())->map(fn ($row) => [
                    'amount' => (float) $row->amount,
                    'reason' => (string) ($row->reason ?? '現金加扣款'),
                    'status' => ($row->director_confirmed_at && $row->hq_approved_at) ? 'approved' : $row->status,
                    'starts_on' => $row->starts_on,
                    'ends_on' => $row->ends_on,
                ])->all(),
                'subject_count' => $subjectCount,
                'subject_units' => is_array($subjectUnits) ? $subjectUnits : [],
            ]);
            if (!$cashSourceAvailable) {
                $result['components']['cash_adjustments'] = [
                    'status' => TeacherEligibilityPolicy::REVIEW,
                    'reason' => 'AllTrue 尚未提供現金加扣款資料來源。',
                    'metrics' => [], 'amount' => null, 'rate' => 0,
                    'missing_fields' => ['teacher_payroll_cash_adjustments'],
                ];
            }
            if (!$allowanceSourceAvailable) {
                $result['components']['admin_allowance'] = [
                    'status' => TeacherEligibilityPolicy::REVIEW,
                    'reason' => 'AllTrue 尚未提供行政加給資料來源。',
                    'metrics' => [], 'amount' => 0, 'rate' => null,
                    'missing_fields' => ['teacher_payroll_admin_allowances'],
                ];
            }
            $result['components']['weekly_16_segments'] = $weeklyStatus;
            $result['components']['new_hire_guarantee'] = $this->policy->newHireGuarantee(
                $firstLessonByTeacher[$teacherId] ?? null,
                $effectiveStart->toDateString(),
                $weeklyStatus['status'] === TeacherEligibilityPolicy::QUALIFIES
            );
            $result = $this->finalizePolicyResult($result['components']);
            $settlement = FulltimeSettlementComposer::compose(
                $result['components'],
                is_array($subjectUnits) ? $subjectUnits : null
            );

            return [
                'teacher_id' => $teacherId,
                'teacher_name' => (string) ($teacher->Name ?? '未命名老師'),
                'employment_type' => (string) ($teacher->employment_type ?? 'full_time'),
                'overall_status' => $result['overall_status'],
                'components' => $result['components'],
                'weekly' => $weeklyRows,
                'work_hours_source' => 'schedule',
                'missing_fields' => $result['missing_fields'],
                'review_required' => $result['overall_status'] === TeacherEligibilityPolicy::REVIEW || $settlement['review_required'],
                'calculation_status' => $settlement['calculation_status'],
                'calculated_payout' => $settlement['calculated_payout'],
                'pending_items' => $settlement['pending_items'],
                'settlement' => $settlement,
            ];
        })->values()->all();
        if ($locked !== null) {
            return response()->json($this->withRecalculation($locked, $rows));
        }

        return response()->json([
            'policy_version' => config('teacher_salary.policy_version'),
            'effective_from' => config('teacher_salary.effective_from'),
            'period' => ['type' => $period['type'], 'start' => $effectiveStart->toDateString(), 'end' => $period['end']->toDateString()],
            'components' => ['weekly_16_segments', 'holiday_16_hours', 'weekday_afternoon', 'special_performance', 'deductions', 'admin_allowance', 'cash_adjustments', 'subject_count_bonus', 'new_hire_guarantee'],
            'teachers' => $rows,
            'total_teachers' => count($rows),
            'settled_elsewhere' => $settledElsewhere,
            // Divide only after the complete eligible monthly raw total is
            // summed, so teacher-level rounding cannot affect the branch total.
            'branch_subject_total' => round(collect($subjectUnitsByTeacher)->sum(fn ($units) => (float) ($units['payroll_raw_total'] ?? 0)) / 8, 4),
            'lock' => [
                'status' => 'draft',
                'month' => $lockMonth,
                'branch_id' => $lockBranchId,
            ],
        ]);
    }

    /**
     * Puts today's result next to each locked row: the payout now, whether it is final, and now minus locked
     * (only when today's result is final). A teacher who is in only one of the two lists has no difference; those computed now but absent from the
     * snapshot are listed by id.
     */
    private function withRecalculation(array $locked, array $liveRows): array
    {
        $live = array_column($liveRows, null, 'teacher_id');
        $locked['teachers'] = array_map(function ($teacher) use ($live) {
            $now = $live[$teacher['teacher_id'] ?? null] ?? null;
            $before = $teacher['calculated_payout'] ?? null;
            $teacher['recalculated'] = $now === null ? null : [
                'calculated_payout' => $now['calculated_payout'],
                'calculation_status' => $now['calculation_status'],
                // A difference is stated only for a finished result: with items still to confirm the payout is a subtotal.
                'difference' => $now['calculation_status'] === 'calculated' && $now['calculated_payout'] !== null && $before !== null
                    ? round((float) $now['calculated_payout'] - (float) $before, 2) : null,
            ];

            return $teacher;
        }, $locked['teachers']);
        $locked['recalculated_only_teacher_ids'] = array_values(array_diff(array_keys($live), array_column($locked['teachers'], 'teacher_id')));

        return $locked;
    }

    /**
     * The one campus that settles each teacher who belongs to more than one (teachers of a single campus are left out).
     * A campus that has already locked the month with the teacher in it keeps the teacher, whatever the lessons say
     * now. Otherwise it is the campus, among those the teacher belongs to, with the most lesson time in the weeks in
     * view; a tie or no lessons goes to the lowest campus id, so every campus reaches the same answer.
     *
     * @param  array<int, array<string, list<array{start:string,end:string,campus_id:int}>>>  $lessonsByTeacher
     * @return array<int, int> teacher id => campus id
     */
    private function settlementCampusByTeacher(array $teacherIds, array $lessonsByTeacher, string $month): array
    {
        $memberOf = [];
        foreach (DB::table('UserCampus')->whereIn('UserID', $teacherIds)->orderBy('CampusID')->get(['UserID', 'CampusID']) as $row) {
            $memberOf[(int) $row->UserID][(int) $row->CampusID] = 0;
        }
        $lockedIn = FulltimePayrollLockStore::tablesReady()
            ? DB::table('fulltime_payroll_run_teachers as t')
                ->join('fulltime_payroll_runs as r', 'r.id', '=', 't.run_id')
                ->where('r.month', $month)->where('r.status', 'locked')->whereIn('t.teacher_id', $teacherIds)
                ->orderBy('r.id')->pluck('r.branch_id', 't.teacher_id')->all()
            : [];

        $campusOf = [];
        foreach ($memberOf as $teacherId => $minutes) {
            if (count($minutes) < 2) continue;
            if (isset($lockedIn[$teacherId])) {
                $campusOf[$teacherId] = (int) $lockedIn[$teacherId];
                continue;
            }
            foreach ($lessonsByTeacher[$teacherId] ?? [] as $lessons) {
                foreach ($lessons as $lesson) {
                    if (!isset($minutes[$lesson['campus_id']])) continue;
                    $start = strtotime($lesson['start']);
                    $end = $lesson['end'] !== '' ? strtotime($lesson['end']) : $start + 7200;
                    $minutes[$lesson['campus_id']] += max(0, $end - $start) / 60;
                }
            }
            // Campuses are in id order and only a strictly larger total takes over: ties stay with the lowest id.
            $campusOf[$teacherId] = (int) array_keys($minutes, max($minutes))[0];
        }

        return $campusOf;
    }

    private function finalizePolicyResult(array $components): array
    {
        $missing = collect($components)->flatMap(fn ($component) => $component['missing_fields'] ?? [])->unique()->values()->all();
        $hasReview = collect($components)->contains(fn ($component) => ($component['status'] ?? null) === TeacherEligibilityPolicy::REVIEW);
        $positiveKeys = ['weekly_16_segments', 'holiday_16_hours', 'weekday_afternoon', 'special_performance', 'admin_allowance'];
        $hasBenefit = collect($positiveKeys)->contains(function ($key) use ($components) {
            $component = $components[$key] ?? [];
            return (float) ($component['rate'] ?? 0) > 0 || (float) ($component['amount'] ?? 0) > 0;
        });

        return [
            'overall_status' => $hasReview ? TeacherEligibilityPolicy::REVIEW : ($hasBenefit ? TeacherEligibilityPolicy::QUALIFIES : TeacherEligibilityPolicy::NOT_QUALIFIES),
            'components' => $components,
            'missing_fields' => $missing,
        ];
    }

    public function lock(Request $request)
    {
        $data = $request->validate([
            'month' => ['required', 'regex:/^\\d{4}-\\d{2}$/'],
            'branch_id' => ['required', 'integer', 'min:1'],
        ]);
        $branchId = (int) $data['branch_id'];
        $this->assertWritableBranch($request, $branchId);
        // Only a month the 115.07 rules cover and that has begun: an earlier month has nothing to settle, and locking a
        // future month would freeze numbers that do not exist yet.
        $firstMonth = substr((string) config('teacher_salary.effective_from'), 0, 7);
        if ($data['month'] < $firstMonth || $data['month'] > Carbon::now('Asia/Taipei')->format('Y-m')) {
            return response()->json(['message' => "只能鎖定 {$firstMonth} 起到這個月的結算。"], 422);
        }
        $end = Carbon::parse($data['month'] . '-01', 'Asia/Taipei')->endOfMonth()->toDateString();
        $request->merge([
            'period' => 'month',
            'start' => $data['month'] . '-01',
            'end' => $end,
            'branch_id' => $branchId,
        ]);
        $payload = $this->index($request)->getData(true);
        if (($payload['lock']['status'] ?? '') === 'locked') {
            return response()->json(['message' => '本月結算已鎖定。'], 422);
        }
        $userId = (int) ($request->attributes->get('auth_user_id') ?? 0);
        if ($userId === 0) {
            $authUser = $request->attributes->get('auth_user');
            if (is_object($authUser) && isset($authUser->id)) {
                $userId = (int) $authUser->id;
            }
        }
        try {
            $runId = FulltimePayrollLockStore::lock(
                $branchId,
                $data['month'],
                $payload['teachers'] ?? [],
                $userId,
                (string) ($payload['policy_version'] ?? config('teacher_salary.policy_version'))
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => '本月結算已鎖定。'], 422);
        }

        return response()->json(['ok' => true, 'status' => 'locked', 'run_id' => $runId]);
    }

    public function reopen(Request $request)
    {
        if ($request->attributes->get('auth_role') !== 'super_admin') {
            return response()->json(['message' => '只有總部可以重開已鎖定的正職結算。'], 403);
        }
        $data = $request->validate([
            'month' => ['required', 'regex:/^\\d{4}-\\d{2}$/'],
            'branch_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:4', 'max:500'],
        ]);
        try {
            FulltimePayrollLockStore::reopen((int) $data['branch_id'], $data['month'], (int) $request->attributes->get('auth_user_id'), $data['reason']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => '本月尚未鎖定。'], 422);
        }

        return response()->json(['ok' => true, 'status' => 'reopened']);
    }

    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'month' => ['required', 'regex:/^\\d{4}-\\d{2}$/'],
            'branch_id' => ['required', 'integer', 'min:1'],
        ]);
        $this->assertWritableBranch($request, (int) $data['branch_id']);
        $end = Carbon::parse($data['month'] . '-01', 'Asia/Taipei')->endOfMonth()->toDateString();
        $request->merge([
            'period' => 'month',
            'start' => $data['month'] . '-01',
            'end' => $end,
            'branch_id' => (int) $data['branch_id'],
        ]);
        $payload = $this->index($request)->getData(true);
        $branchName = DB::table('Campus')->where('id', $data['branch_id'])->value('name') ?? $data['branch_id'];
        $filename = "正職薪資_{$branchName}_{$data['month']}.csv";
        FulltimePayrollLockStore::audit((int) $data['branch_id'], $data['month'], 'export', (int) ($request->attributes->get('auth_user_id') ?? 0));

        return response()->streamDownload(function () use ($payload) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($out, ['老師姓名', '正課科目數', '輔導試聽科目數', '核薪總科目數', '一對三總計', '科目數獎金', '一對三獎金', '教師倍率', '倍率後獎金', '加扣款', '獎金合計', '狀態']);
            foreach ($payload['teachers'] ?? [] as $teacher) {
                $s = $teacher['settlement'] ?? [];
                $adj = collect($s['adjustments'] ?? [])->map(fn ($row) => ($row['label'] ?? '') . ':' . ($row['amount'] ?? 0))->implode(';');
                $draft = !empty($teacher['review_required']) || !empty($s['payout_is_draft']);
                fputcsv($out, [
                    $teacher['teacher_name'] ?? '',
                    isset($s['regular_subject_count']) ? round($s['regular_subject_count'] / 8, 4) : '',
                    isset($s['tutoring_trial_subject_count']) ? round($s['tutoring_trial_subject_count'] / 8, 4) : '',
                    $s['payroll_subject_count'] ?? '',
                    $s['one_to_three_count'] ?? '',
                    $s['subject_count_bonus'] ?? 0,
                    $s['one_to_three_bonus'] ?? 0,
                    $s['multiplier_pct'] ?? 100,
                    $s['weighted_bonus_amount'] ?? 0,
                    $adj,
                    $s['total_payout'] ?? 0,
                    $draft ? '試算' : (($payload['lock']['status'] ?? '') === 'locked' ? '已鎖定' : '草稿'),
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function assertWritableBranch(Request $request, int $branchId): void
    {
        $role = $request->attributes->get('auth_role');
        if ($role === 'super_admin') {
            return;
        }
        $allowed = array_map('intval', $request->attributes->get('auth_campus_ids', []));
        if (!in_array($branchId, $allowed, true)) {
            abort(403, 'Forbidden');
        }
    }

    /**
     * 每週16段 follows the calendar (HQ 115.07: 「未排滿16段課」), not attendance. One 段 is 2 hours of the
     * teacher's clock time: lessons that overlap (two 一對二 courses in the same 2 hours) count once.
     * What counts as a lesson is TeacherClassCalendar's payroll view: 正課, 輔導 and 試聽; a student's leave
     * still counts; cancelled or moved lessons and admin time do not; a substituted lesson is the substitute's.
     * ponytail: a teacher's own leave recorded only as a leave status on the lesson still counts, because that
     * status cannot be told from a student's leave; split the status if that case turns out to matter.
     *
     * @param  array<string, list<array{start:string,end:string}>>  $lessonsByDate
     */
    private function evaluateWeek(Carbon $start, Carbon $end, array $lessonsByDate, array $closureDates = []): array
    {
        $dailyMinutes = [];
        // 平日下午倍率 (HQ 115.07: 週一至週五 18:00 前可接 5 段課): the same lessons, only the part before the cutoff.
        $cutoff = $this->policy->weekdayCutoff();
        $earlyMinutes = 0;
        $weekendMinutes = 0; // 假日16小時倍率: Saturday and Sunday
        foreach ($lessonsByDate as $date => $lessons) {
            if ($date < $start->toDateString() || $date > $end->toDateString()) continue;
            // A lesson with no end time keeps the established two-hour default.
            $rows = array_map(fn ($lesson) => (object) ['start_time' => $lesson['start'], 'end_time' => $lesson['end'] !== '' ? $lesson['end'] : date('H:i', strtotime($lesson['start'] . ' +2 hours'))], $lessons);
            $dailyMinutes[$date] = (int) round(60 * $this->scheduledCoverageHoursForDate($rows, fn () => true));
            if (Carbon::parse($date)->dayOfWeekIso > 5) {
                $weekendMinutes += $dailyMinutes[$date];
            } else {
                $early = array_map(
                    fn ($row) => (object) ['start_time' => $row->start_time, 'end_time' => min($row->end_time, $cutoff)],
                    array_filter($rows, fn ($row) => $row->start_time < $cutoff && $row->end_time > $row->start_time)
                );
                $earlyMinutes += (int) round(60 * $this->scheduledCoverageHoursForDate($early, fn () => true));
            }
        }
        ksort($dailyMinutes);
        $segments = round(array_sum($dailyMinutes) / 120, 2);

        $policy = $this->policy->weekly16(['segments' => $segments]);
        $closed = array_values(array_filter($closureDates, fn ($date) => $date >= $start->toDateString() && $date <= $end->toDateString()));
        if ($closed !== []) {
            $policy['status'] = TeacherEligibilityPolicy::QUALIFIES;
            $policy['reason'] = '本週有全校停課日，不列入判定。';
        }
        $policy['metrics'] = [
            'closure_dates' => $closed,
            'week_start' => $start->toDateString(),
            'week_end' => $end->toDateString(),
            'daily_hours' => array_map(fn ($minutes) => round($minutes / 60, 2), $dailyMinutes),
            'total_segments' => $segments,
            'weekday_early_segments' => round($earlyMinutes / 120, 2),
            'weekend_hours' => round($weekendMinutes / 60, 2),
            'meets_16_segments' => $policy['status'] === TeacherEligibilityPolicy::QUALIFIES,
        ];
        return $policy;
    }

    private function aggregateWeeklyStatus(array $weeklyRows): array
    {
        $qualifying = collect($weeklyRows)->where('status', TeacherEligibilityPolicy::QUALIFIES);
        $allPass = $weeklyRows !== [] && $qualifying->count() === count($weeklyRows);
        // HQ 115.07 + Founder 2026-11-15: the 16段課獎金 is monthly and needs every week of the month full;
        // one short week removes it. A week belongs to its Monday's month.
        $amount = (int) config('teacher_salary.weekly_segment_month_bonus', 4000) * collect($weeklyRows)
            ->groupBy(fn ($row) => substr((string) $row['metrics']['week_start'], 0, 7))
            ->filter(fn ($month) => $month->every(fn ($row) => $row['status'] === TeacherEligibilityPolicy::QUALIFIES))
            ->count();

        return [
            'status' => $allPass ? TeacherEligibilityPolicy::QUALIFIES : TeacherEligibilityPolicy::NOT_QUALIFIES,
            'reason' => $weeklyRows === [] ? '查詢期間沒有從週一開始的完整週。' : ($allPass ? '查詢期間每週均排滿16段。' : '查詢期間有未排滿16段的週。'),
            'metrics' => [
                'weeks' => $weeklyRows,
                'qualifying_weeks' => $qualifying->count(),
                'week_count' => count($weeklyRows),
                'total_segments' => round(collect($weeklyRows)->sum(fn ($row) => (float) $row['metrics']['total_segments']), 2),
                'meets_16_segments' => count($weeklyRows) === 1 ? $allPass : null,
            ],
            'amount' => $amount,
            'rate' => 0,
            'missing_fields' => [],
        ];
    }

    /**
     * @param  array<int, array{date?:string, status?:string}>  $rows
     * @return list<string>
     */
    private function campusClosedDatesFromSessionRows(array $rows, int $minSessions = 1): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $date = substr((string) ($row['date'] ?? ''), 0, 10);
            if ($date === '') {
                continue;
            }
            $counts[$date] ??= ['total' => 0, 'open' => 0];
            $counts[$date]['total']++;
            if ($this->isOpenTeachingSessionStatus($row['status'] ?? null)) {
                $counts[$date]['open']++;
            }
        }
        $dates = [];
        foreach ($counts as $date => $count) {
            if ($count['open'] === 0 && $count['total'] >= $minSessions) {
                $dates[] = $date;
            }
        }
        sort($dates);

        return $dates;
    }

    private function campusClosedDates(?array $branchFilter, Carbon $start, Carbon $end): array
    {
        if (!Schema::hasTable('ClassSession') || !Schema::hasTable('StudentClass') || !Schema::hasTable('Student')) {
            return [];
        }

        $rows = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as st', 'st.id', '=', 'sc.StudentID')
            ->whereBetween('cs.SessionDate', [$start->toDateString(), $end->toDateString()])
            ->get(['cs.SessionDate as event_date', 'cs.Status as status']);

        // ponytail: "every lesson that day was called off" is the only closure signal the product has; the floor keeps a
        // quiet day with a couple of student leaves from passing as one. A closure whose lessons were never created is
        // missed; if that happens, add an HQ-only closure calendar instead of trusting director-entered events.
        $closed = $this->campusClosedDatesFromSessionRows($rows->map(fn ($row) => [
            'date' => substr((string) $row->event_date, 0, 10),
            'status' => $row->status,
        ])->all(), (int) config('teacher_salary.closure_min_sessions', 10));

        // A makeup or substitute lesson can live in `schedules` with no ClassSession: a day that still has one is open.
        return array_values(array_diff($closed, TeacherClassCalendar::datesWithLiveExceptionLessons($closed)));
    }

    private function isOpenTeachingSessionStatus(?string $status): bool
    {
        $status = strtolower(trim((string) $status));

        return !in_array($status, [
            'leave', 'leave_adjusted', 'leave_requested', 'excused',
            'cancelled', 'canceled', 'voided', 'suspended', 'holiday',
        ], true);
    }

    /**
     * Subject units come from the read model behind the 科目數統計 page, so the payroll count is
     * the number that page shows for the teacher (all campuses). 一對三 lessons are part of 正課.
     */
    private function globalSubjectUnitsByTeacher(array $teacherIds, Carbon $start, Carbon $end): array
    {
        // ponytail: builds every teacher's entries company-wide; give the service a teacher filter if this page gets slow.
        $contributions = collect(app(SubjectUnitsTimelineService::class)->build($start, $end, [])['teacher_contributions'])
            ->keyBy('teacher_id');

        return collect($teacherIds)->mapWithKeys(function ($teacherId) use ($contributions) {
            $row = $contributions->get((int) $teacherId, []);
            $regular = (float) ($row['regular_subject_count'] ?? 0);
            $tutoringTrial = (float) ($row['tutoring_trial_subject_count'] ?? 0);
            // Buckets stay raw; the /8 divisor applies once, to the complete monthly total.
            $payrollRawTotal = (float) ($row['raw_subject_count'] ?? 0);
            return [(int) $teacherId => [
                'regular' => $regular,
                'tutoring_trial' => $tutoringTrial,
                'one_to_three' => (float) ($row['one_to_three_count'] ?? 0),
                'payroll_total' => round($payrollRawTotal / 8, 4),
                // 正課 only (不含輔導／試聽), same /8 scale: the "20科 +5%" rule reads this.
                'regular_total' => round($regular / 8, 4),
                'payroll_raw_total' => $payrollRawTotal,
            ]];
        })->all();
    }

    /**
     * 新進保底 counts from the teacher's first lesson (Founder 2026-11-15: no hire-date field). Company-wide, like the
     * subject counts: the campus filter only decides which teachers are listed.
     * The date is the earlier of two: the first lesson signed in under the teacher (who taught is the sign-in, the
     * same evidence the calendar keeps history with, so a covered lesson counts), and the first lesson of a course
     * that is theirs now, leaving out lessons signed in under someone else (a course taken over does not bring the
     * former teacher's lessons along). Taking the earlier one keeps it fail-closed: missing sign-ins can only make a
     * teacher look less new, never more.
     * ponytail: one pass over the sign-in table per load; add an index on its teacher column if this page gets slow.
     *
     * @return array<int, string> teacher id => Y-m-d
     */
    private function firstLessonDateByTeacher(array $teacherIds): array
    {
        if ($teacherIds === [] || !Schema::hasTable('ClassSession') || !Schema::hasTable('StudentClass') || !Schema::hasTable('StudentSingIn')) {
            return [];
        }
        $taught = DB::table('StudentSingIn as si')
            ->join('ClassSession as cs', 'cs.id', '=', 'si.ClassSessionID')
            ->whereIn('si.TeacherID', $teacherIds)
            ->whereNull('si.VoidedAt')
            ->groupBy('si.TeacherID')
            ->selectRaw('si.TeacherID as teacher_id, MIN(cs.SessionDate) as first_date')
            ->pluck('first_date', 'teacher_id');
        $own = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->whereIn('sc.TeacherID', $teacherIds)
            ->whereNotIn('cs.Status', ['cancelled', 'voided'])
            ->whereNotExists(fn ($other) => $other->select(DB::raw(1))->from('StudentSingIn as other')
                ->whereColumn('other.ClassSessionID', 'cs.id')->whereNull('other.VoidedAt')
                ->where('other.TeacherID', '>', 0)->whereColumn('other.TeacherID', '!=', 'sc.TeacherID'))
            ->groupBy('sc.TeacherID')
            ->selectRaw('sc.TeacherID as teacher_id, MIN(cs.SessionDate) as first_date')
            ->pluck('first_date', 'teacher_id');

        $dates = [];
        foreach ([$taught, $own] as $found) {
            foreach ($found as $teacherId => $date) {
                $date = substr((string) $date, 0, 10);
                if ($date !== '' && (!isset($dates[$teacherId]) || $date < $dates[$teacherId])) $dates[$teacherId] = $date;
            }
        }

        return $dates;
    }

    private function clockMinutes($value): ?int
    {
        if ($value === null || $value === '') return null;
        try {
            $parsed = Carbon::parse((string) $value);
            return ($parsed->hour * 60) + $parsed->minute;
        } catch (\Throwable) {
            return null;
        }
    }

    private function scheduledCoverageHoursForDate($rows, callable $include): float
    {
        $intervals = [];
        $fallbackHours = 0.0;
        foreach ($rows as $row) {
            if (!$include($row)) {
                continue;
            }

            $startMinutes = $this->clockMinutes($row->start_time ?? null);
            $endMinutes = $this->clockMinutes($row->end_time ?? null);
            if ($startMinutes !== null && $endMinutes !== null && $endMinutes > $startMinutes) {
                $intervals[] = [$startMinutes, $endMinutes];
                continue;
            }

            $fallbackHours += $this->durationHours($row);
        }

        usort($intervals, fn ($left, $right) => $left[0] <=> $right[0] ?: $left[1] <=> $right[1]);
        $coverageMinutes = 0;
        $mergedStart = null;
        $mergedEnd = null;
        foreach ($intervals as [$intervalStart, $intervalEnd]) {
            if ($mergedStart === null) {
                $mergedStart = $intervalStart;
                $mergedEnd = $intervalEnd;
                continue;
            }
            if ($intervalStart <= $mergedEnd) {
                $mergedEnd = max($mergedEnd, $intervalEnd);
                continue;
            }
            $coverageMinutes += $mergedEnd - $mergedStart;
            $mergedStart = $intervalStart;
            $mergedEnd = $intervalEnd;
        }
        if ($mergedStart !== null) {
            $coverageMinutes += $mergedEnd - $mergedStart;
        }

        return ($coverageMinutes / 60) + $fallbackHours;
    }

    private function durationHours($row): float
    {
        if (property_exists($row, 'duration_hours') && $row->duration_hours !== null && (float) $row->duration_hours > 0) return (float) $row->duration_hours;
        try {
            return (int) Carbon::parse((string) $row->start_time)->diffInMinutes(Carbon::parse((string) $row->end_time), true) / 60;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Whole Monday-to-Sunday weeks whose Monday falls in the period. A week that crosses a month end
     * is judged once, in its Monday's month, and is never cut in two.
     *
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    private function splitIntoWeeks(Carbon $start, Carbon $end): array
    {
        $weeks = [];
        $cursor = $start->copy()->startOfWeek(Carbon::MONDAY);
        if ($cursor->lt($start->copy()->startOfDay())) $cursor->addWeek();
        while ($cursor->lte($end)) {
            $weeks[] = [$cursor->copy()->startOfDay(), $cursor->copy()->endOfWeek(Carbon::SUNDAY)];
            $cursor->addWeek();
        }
        return $weeks;
    }

    private function resolvePeriod(Request $request): array
    {
        $type = $request->input('period', 'month');
        if (!in_array($type, ['week', 'month', 'year'], true)) abort(422, 'period must be week, month, or year');
        $tz = 'Asia/Taipei';
        if ($request->filled('start') || $request->filled('end')) {
            if (!$request->filled('start') || !$request->filled('end')) abort(422, 'start and end are both required');
            $start = Carbon::parse($request->input('start'), $tz)->startOfDay();
            $end = Carbon::parse($request->input('end'), $tz)->endOfDay();
            if ($end->lt($start)) abort(422, 'end must be after or equal to start');
            return compact('type', 'start', 'end');
        }
        $now = Carbon::now($tz);
        return match ($type) {
            'week' => ['type' => $type, 'start' => $now->startOfWeek(Carbon::MONDAY), 'end' => $now->endOfWeek(Carbon::SUNDAY)],
            'year' => ['type' => $type, 'start' => $now->startOfYear(), 'end' => $now->endOfYear()],
            default => ['type' => $type, 'start' => $now->startOfMonth(), 'end' => $now->endOfMonth()],
        };
    }

    private function resolveCampusIds(Request $request): array|null|\Illuminate\Http\JsonResponse
    {
        $role = $request->attributes->get('auth_role');
        $authCampusIds = array_map('intval', $request->attributes->get('auth_campus_ids', []));
        if ($role === 'super_admin') {
            return $request->filled('branch_id') ? [(int) $request->input('branch_id')] : null;
        }
        if ($authCampusIds === []) return response()->json(['message' => 'Forbidden: no campus assignment'], 403);
        if ($request->filled('branch_id')) {
            $branchId = (int) $request->input('branch_id');
            if (!in_array($branchId, $authCampusIds, true)) return response()->json(['message' => 'Forbidden'], 403);
            return [$branchId];
        }
        return $authCampusIds;
    }
}
