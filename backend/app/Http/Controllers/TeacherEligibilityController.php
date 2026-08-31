<?php

namespace App\Http\Controllers;

use App\Services\TeacherEligibilityPolicy;
use App\Support\AttendanceStatus;
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
        if ($lockedRun) {
            $rows = FulltimePayrollLockStore::snapshotTeachers((int) $lockedRun->id);

            return response()->json([
                'policy_version' => $lockedRun->policy_version ?: config('teacher_salary.policy_version'),
                'effective_from' => config('teacher_salary.effective_from'),
                'period' => ['type' => $period['type'], 'start' => $effectiveStart->toDateString(), 'end' => $period['end']->toDateString()],
                'components' => ['weekly_16_segments', 'holiday_16_hours', 'weekday_afternoon', 'special_performance', 'deductions', 'admin_allowance', 'cash_adjustments', 'subject_count_bonus'],
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
            ]);
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
            return response()->json([
                'policy_version' => config('teacher_salary.policy_version'),
                'period' => ['type' => $period['type'], 'start' => $effectiveStart->toDateString(), 'end' => $period['end']->toDateString()],
                'teachers' => [],
                'total_teachers' => 0,
                'lock' => ['status' => 'draft', 'month' => $lockMonth, 'branch_id' => $lockBranchId],
            ]);
        }

        $teacherIds = $teachers->pluck('id')->map(fn ($id) => (int) $id)->all();
        $branchFilter = $campusIds !== null ? $campusIds : null;
        $schedules = DB::table('schedules')
            ->whereIn('teacher_id', $teacherIds)
            ->whereBetween('schedule_date', [$effectiveStart->toDateString(), $period['end']->toDateString()])
            ->whereNotIn('status', ['cancelled', 'leave', 'leave_requested'])
            ->when($branchFilter !== null, fn ($query) => $query->whereIn('branch_id', $branchFilter))
            ->get();
        $plannedSchedules = DB::table('schedules')
            ->whereIn('teacher_id', $teacherIds)
            ->whereBetween('schedule_date', [$effectiveStart->toDateString(), $period['end']->toDateString()])
            ->whereNotIn('status', ['cancelled'])
            ->when($branchFilter !== null, fn ($query) => $query->whereIn('branch_id', $branchFilter))
            ->get();

        // Teacher RFID is still incomplete in production. Work-hours for
        // this report therefore use attended student class sessions, not
        // TeacherSingIn. Each ClassSession is counted once even for shared
        // classes with multiple student rows.
        $attendanceSourceAvailable = Schema::hasTable('ClassSession')
            && Schema::hasTable('StudentClass')
            && Schema::hasTable('StudentSingIn');
        $attendanceSessions = collect();
        if ($attendanceSourceAvailable) {
            $attendanceSessions = DB::table('ClassSession as cs')
                ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
                ->join('StudentSingIn as si', 'si.ClassSessionID', '=', 'cs.id')
                ->join('Student as st', 'st.id', '=', 'sc.StudentID')
                ->whereIn('sc.TeacherID', $teacherIds)
                ->whereBetween('cs.SessionDate', [$effectiveStart->toDateString(), $period['end']->toDateString()])
                ->whereNull('si.VoidedAt')
                ->whereIn('si.Status', $this->weeklyAttendanceStatuses())
                ->whereNotIn('cs.Status', ['cancelled', 'voided', 'leave', 'leave_adjusted', 'leave_requested', 'excused'])
                ->when($branchFilter !== null, fn ($query) => $query->whereIn('st.CampusID', $branchFilter))
                ->select([
                    'cs.id as class_session_id', 'cs.SessionDate as session_date',
                    'cs.StartTime as start_time', 'cs.EndTime as end_time',
                    'cs.Status as session_status', 'si.Status as attendance_status',
                    'si.SignInDT as student_sign_in_at', 'si.SignOutDT as student_sign_out_at',
                    'sc.TeacherID as teacher_id', 'sc.ClassType as class_type',
                ])
                ->get()
                ->filter(fn ($row) => $this->isValidWeeklyAttendance($row))
                ->unique('class_session_id')
                ->values();
        }

        $events = Schema::hasTable('teacher_payroll_events')
            ? DB::table('teacher_payroll_events')
                ->where(function ($query) use ($teacherIds) {
                    $query->whereNull('teacher_id')->orWhereIn('teacher_id', $teacherIds);
                })
                ->whereBetween('event_date', [$effectiveStart->toDateString(), $period['end']->toDateString()])
                ->where('status', 'approved')
                ->when($branchFilter !== null, function ($query) use ($branchFilter) {
                    $query->where(function ($nested) use ($branchFilter) {
                        $nested->whereNull('branch_id')->orWhereIn('branch_id', $branchFilter);
                    });
                })
                ->get()
            : collect();

        $achievements = Schema::hasTable('teacher_payroll_achievements')
            ? DB::table('teacher_payroll_achievements')
                ->whereIn('teacher_id', $teacherIds)
                ->where(function ($query) use ($effectiveStart) {
                    $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $effectiveStart->toDateString());
                })
                ->where(function ($query) use ($period) {
                    $query->whereNull('starts_on')->orWhereDate('starts_on', '<=', $period['end']->toDateString());
                })
                ->when($branchFilter !== null, fn ($query) => $query->where(function ($nested) use ($branchFilter) {
                    $nested->whereNull('branch_id')->orWhereIn('branch_id', $branchFilter);
                }))
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
                ->when($branchFilter !== null, fn ($query) => $query->where(function ($nested) use ($branchFilter) {
                    $nested->whereNull('branch_id')->orWhereIn('branch_id', $branchFilter);
                }))
                ->where('status', '!=', 'withdrawn')
                ->get()
            : collect();

        // Existing attendance/leave is the product's source of truth. The
        // additive payroll event table is only for policy facts that the core
        // product does not model (for example a company holiday calendar).
        $events = $events->concat($this->existingLeaveEvents($teacherIds, $branchFilter, $effectiveStart, $period['end']));
        $sessionCalendarAvailable = Schema::hasTable('ClassSession');
        if ($sessionCalendarAvailable) {
            $knownHolidayDates = $events->filter(fn ($event) => $event->event_type === 'holiday')
                ->map(fn ($event) => substr((string) $event->event_date, 0, 10))
                ->unique()
                ->all();
            foreach ($this->campusClosedDates($branchFilter, $effectiveStart, $period['end']) as $date) {
                if (in_array($date, $knownHolidayDates, true)) {
                    continue;
                }
                $events->push((object) [
                    'teacher_id' => null,
                    'event_date' => $date,
                    'event_type' => 'holiday',
                    'hours' => null,
                    'holiday_leave_hours' => 0,
                    'makeup_completed' => true,
                ]);
            }
        }

        $scheduleByTeacher = $schedules->groupBy('teacher_id');
        $plannedByTeacher = $plannedSchedules->groupBy('teacher_id');
        $attendanceByTeacher = $attendanceSessions->groupBy('teacher_id');
        $eventByTeacher = $events->groupBy(fn ($event) => $event->teacher_id ? (string) $event->teacher_id : '*');
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
                ->when($branchFilter !== null, fn ($query) => $query->where(function ($nested) use ($branchFilter) {
                    $nested->whereNull('branch_id')->orWhereIn('branch_id', $branchFilter);
                }))
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
                ->when($branchFilter !== null, fn ($query) => $query->where(function ($nested) use ($branchFilter) {
                    $nested->whereNull('branch_id')->orWhereIn('branch_id', $branchFilter);
                }))
                ->where('status', '!=', 'withdrawn')
                ->get()
            : collect();
        $cashByTeacher = $cashRows->groupBy('teacher_id');
        $cashSourceAvailable = Schema::hasTable('teacher_payroll_cash_adjustments');
        $allowanceSourceAvailable = Schema::hasTable('teacher_payroll_admin_allowances');
        $pendingSalaryByTeacher = $this->pendingSalaryByTeacher($teacherIds, $branchFilter);
        $eventsAvailable = $sessionCalendarAvailable || $events->isNotEmpty();
        $subjectUnitsByTeacher = $this->subjectUnitsByTeacher($teacherIds, $branchFilter, $effectiveStart, $period['end']);
        $salaryByTeacher = $this->salaryProfilesByTeacher($teacherIds, $branchFilter, $period['end']->toDateString());

        $rows = $teachers->map(function ($teacher) use ($period, $effectiveStart, $scheduleByTeacher, $plannedByTeacher, $attendanceByTeacher, $eventByTeacher, $achievementByTeacher, $deductionByTeacher, $allowanceByTeacher, $cashByTeacher, $cashSourceAvailable, $allowanceSourceAvailable, $pendingSalaryByTeacher, $eventsAvailable, $attendanceSourceAvailable, $sessionCalendarAvailable, $subjectUnitsByTeacher, $salaryByTeacher) {
            $teacherId = (int) $teacher->id;
            $teacherSchedules = $scheduleByTeacher->get($teacherId, collect());
            $teacherPlanned = $plannedByTeacher->get($teacherId, collect());
            $teacherAttendance = $attendanceByTeacher->get($teacherId, collect());
            $teacherEvents = $eventByTeacher->get((string) $teacherId, collect())->concat($eventByTeacher->get('*', collect()));
            $weeklyRows = [];
            foreach ($this->splitIntoWeeks($effectiveStart, $period['end']) as [$weekStart, $weekEnd]) {
                $weeklyRows[] = $this->evaluateWeek($weekStart, $weekEnd, $teacherSchedules, $teacherAttendance, $teacherEvents, $eventsAvailable, $attendanceSourceAvailable);
            }

            $weeklyStatus = $this->aggregateWeeklyStatus($weeklyRows);
            $holidayCalendarAvailable = $sessionCalendarAvailable
                || $teacherEvents->contains(fn ($event) => $event->event_type === 'holiday');
            $holidayDays = $this->holidayDays($teacherAttendance, $teacherPlanned, $teacherEvents, $effectiveStart, $period['end'], $holidayCalendarAvailable);
            $weekdayHours = $this->weekdayHours($teacherSchedules, $effectiveStart, $period['end']);
            $subjectUnits = $subjectUnitsByTeacher[$teacherId] ?? null;
            $subjectCount = is_array($subjectUnits) ? ($subjectUnits['payroll_total'] ?? null) : null;

            $result = $this->policy->evaluate([
                'period_start' => $effectiveStart->toDateString(),
                'period_end' => $period['end']->toDateString(),
                'weekly' => [
                    'segments' => 0,
                    'work_hours' => 0,
                    'segment_rule' => true,
                    'exception' => ['official_event' => false, 'leave_eligible' => false],
                ],
                'holiday_days' => $holidayDays,
                'weekday_hours' => $weekdayHours,
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
            // The policy was evaluated once with a placeholder weekly value
            // before the attendance-backed weekly result and source-availability
            // overrides were applied. Reclassify from the final components so
            // review/missing fields cannot silently disappear from the API.
            $result['components']['weekly_16_segments'] = $weeklyStatus;
            $result = $this->finalizePolicyResult($result['components']);
            $settlement = FulltimeSettlementComposer::compose(
                $result['components'],
                $salaryByTeacher[$teacherId] ?? null,
                is_array($subjectUnits) ? $subjectUnits : null
            );

            return [
                'teacher_id' => $teacherId,
                'teacher_name' => (string) ($teacher->Name ?? '未命名老師'),
                'employment_type' => (string) ($teacher->employment_type ?? 'full_time'),
                'overall_status' => $result['overall_status'],
                'components' => $result['components'],
                'weekly' => $weeklyRows,
                'work_hours_source' => 'student_attendance',
                'missing_fields' => $result['missing_fields'],
                'review_required' => $result['overall_status'] === TeacherEligibilityPolicy::REVIEW || $settlement['review_required'],
                'pending_salary' => $pendingSalaryByTeacher[$teacherId] ?? null,
                'calculation_status' => $settlement['calculation_status'],
                'calculated_payout' => $settlement['calculated_payout'],
                'pending_items' => $settlement['pending_items'],
                'settlement' => $settlement,
            ];
        })->values()->all();

        return response()->json([
            'policy_version' => config('teacher_salary.policy_version'),
            'effective_from' => config('teacher_salary.effective_from'),
            'period' => ['type' => $period['type'], 'start' => $effectiveStart->toDateString(), 'end' => $period['end']->toDateString()],
            'components' => ['weekly_16_segments', 'holiday_16_hours', 'weekday_afternoon', 'special_performance', 'deductions', 'admin_allowance', 'cash_adjustments', 'subject_count_bonus'],
            'teachers' => $rows,
            'total_teachers' => count($rows),
            'branch_subject_total' => round(collect($rows)->sum(fn ($row) => (float) ($row['settlement']['payroll_subject_count'] ?? 0)), 4),
            'lock' => [
                'status' => 'draft',
                'month' => $lockMonth,
                'branch_id' => $lockBranchId,
            ],
        ]);
    }

    public function lock(Request $request)
    {
        $data = $request->validate([
            'month' => ['required', 'regex:/^\\d{4}-\\d{2}$/'],
            'branch_id' => ['required', 'integer', 'min:1'],
        ]);
        $branchId = (int) $data['branch_id'];
        $this->assertWritableBranch($request, $branchId);
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
            fputcsv($out, ['老師姓名', '固定底薪', '正課科目數', '輔導試聽科目數', '核薪總科目數', '一對三總計', '科目數獎金', '一對三獎金', '教師倍率', '倍率後獎金', '加扣款', '總發放金額', '狀態']);
            foreach ($payload['teachers'] ?? [] as $teacher) {
                $s = $teacher['settlement'] ?? [];
                $adj = collect($s['adjustments'] ?? [])->map(fn ($row) => ($row['label'] ?? '') . ':' . ($row['amount'] ?? 0))->implode(';');
                $draft = !empty($teacher['review_required']) || !empty($s['payout_is_draft']);
                fputcsv($out, [
                    $teacher['teacher_name'] ?? '',
                    $s['base_salary'] ?? 0,
                    $s['regular_subject_count'] ?? '',
                    $s['tutoring_trial_subject_count'] ?? '',
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

    private function evaluateWeek(Carbon $start, Carbon $end, $schedules, $attendanceSessions, $events, bool $eventsAvailable, bool $attendanceSourceAvailable): array
    {
        $weekSchedules = $schedules->filter(fn ($row) => $row->schedule_date >= $start->toDateString() && $row->schedule_date <= $end->toDateString());
        $weekAttendance = $attendanceSessions->filter(fn ($row) => substr((string) $row->session_date, 0, 10) >= $start->toDateString() && substr((string) $row->session_date, 0, 10) <= $end->toDateString());
        // The 16-segment rule is driven by valid attended ClassSession rows,
        // never schedules or LearningRecords. A regular session contributes
        // actual duration once; trial contributes one fixed segment; tutoring
        // contributes zero. ClassSession is unique even for shared classes.
        $regularAttendance = $weekAttendance->filter(fn ($row) => $this->isEligibleTeachingAttendance($row));
        $trialAttendance = $weekAttendance->filter(fn ($row) => strtolower((string) ($row->class_type ?? '')) === 'trial');
        $tutoringAttendance = $weekAttendance->filter(fn ($row) => strtolower((string) ($row->class_type ?? '')) === 'tutoring');
        $durationUnknown = $regularAttendance->contains(fn ($row) => $this->classSessionDurationHours($row) === null);
        $regularSegments = $attendanceSourceAvailable
            ? ($durationUnknown ? null : round($regularAttendance->sum(fn ($row) => $this->classSessionDurationHours($row)) / 2, 2))
            : null;
        $trialSegments = $attendanceSourceAvailable ? (float) $trialAttendance->count() : null;
        $segments = ($regularSegments === null || $trialSegments === null)
            ? null
            : round($regularSegments + $trialSegments, 2);
        $workHours = $attendanceSourceAvailable
            ? ($durationUnknown ? null : round($regularAttendance->sum(fn ($row) => $this->classSessionDurationHours($row)), 2))
            : null;
        $weekEvents = $events->filter(fn ($event) => $event->event_date >= $start->toDateString() && $event->event_date <= $end->toDateString());
        $official = $eventsAvailable && $weekEvents->contains(
            fn ($event) => in_array($event->event_type, ['official_closure', 'holiday'], true)
        );
        $leaveEvents = $weekEvents->filter(fn ($event) => $event->event_type === 'leave');
        $leaveEligible = $official ? false : $this->resolveLeaveEligibility($leaveEvents);
        $saturdayLeaveBlocked = false;
        $hasSaturdayLeave = $leaveEvents->contains(fn ($event) => Carbon::parse((string) $event->event_date)->dayOfWeekIso === 6);
        if ($hasSaturdayLeave) {
            $sundayHasRegularClass = $weekSchedules->contains(fn ($row) => Carbon::parse($row->schedule_date)->dayOfWeekIso === 7 && $this->isRegularAssignable($row));
            $saturdayLeaveBlocked = !$sundayHasRegularClass && $leaveEligible === false;
        }

        $policy = $this->policy->weekly16([
            'segments' => $segments,
            'work_hours' => $workHours,
            'segment_rule' => true,
            'exception' => $eventsAvailable ? [
                'official_event' => $official,
                'leave_eligible' => $leaveEligible,
                'saturday_leave_blocked' => $saturdayLeaveBlocked,
            ] : null,
        ]);
        $policy['metrics']['week_start'] = $start->toDateString();
        $policy['metrics']['week_end'] = $end->toDateString();
        $policy['metrics']['work_hours_source'] = 'student_attendance';
        $policy['metrics']['attendance_sessions'] = $weekAttendance->count();
        $policy['metrics']['regular_segments'] = $regularSegments;
        $policy['metrics']['trial_segments'] = $trialSegments;
        $policy['metrics']['tutoring_sessions'] = $tutoringAttendance->count();
        $policy['metrics']['total_segments'] = $segments;
        $policy['metrics']['meets_16_segments'] = $segments !== null
            ? $segments >= (float) config('teacher_salary.weekly_segment_threshold', 16)
            : null;
        $policy['metrics']['course_sessions'] = $weekAttendance->map(fn ($row) => [
            'class_session_id' => (int) $row->class_session_id,
            'session_date' => substr((string) $row->session_date, 0, 10),
            'start_time' => (string) $row->start_time,
            'end_time' => (string) $row->end_time,
            'class_type' => strtolower((string) ($row->class_type ?? 'one_on_one')),
            'attendance_status' => (string) $row->attendance_status,
            'segment_type' => strtolower((string) ($row->class_type ?? '')) === 'trial'
                ? 'trial_fixed'
                : (strtolower((string) ($row->class_type ?? '')) === 'tutoring' ? 'tutoring_excluded' : 'regular_duration'),
            'segments' => strtolower((string) ($row->class_type ?? '')) === 'trial'
                ? 1.0
                : (strtolower((string) ($row->class_type ?? '')) === 'tutoring' ? 0.0 : (($hours = $this->classSessionDurationHours($row)) === null ? null : round($hours / 2, 2))),
        ])->values()->all();
        return $policy;
    }

    private function aggregateWeeklyStatus(array $weeklyRows): array
    {
        $hasReview = collect($weeklyRows)->contains(fn ($row) => $row['status'] === TeacherEligibilityPolicy::REVIEW);
        $allPass = $weeklyRows !== [] && collect($weeklyRows)->every(fn ($row) => $row['status'] === TeacherEligibilityPolicy::QUALIFIES);
        $amount = 0;
        $months = [];
        $courseSessions = [];
        $regularSegments = 0.0;
        $trialSegments = 0.0;
        $tutoringSessions = 0;
        foreach ($weeklyRows as $row) {
            $regularSegments += (float) ($row['metrics']['regular_segments'] ?? 0);
            $trialSegments += (float) ($row['metrics']['trial_segments'] ?? 0);
            $tutoringSessions += (int) ($row['metrics']['tutoring_sessions'] ?? 0);
            $courseSessions = array_merge($courseSessions, $row['metrics']['course_sessions'] ?? []);
            if ($row['status'] !== TeacherEligibilityPolicy::QUALIFIES) continue;
            $month = substr((string) ($row['metrics']['week_start'] ?? ''), 0, 7);
            $months[$month] = ($months[$month] ?? 0) + 1;
        }
        foreach ($months as $count) {
            $amount += min($count, (int) config('teacher_salary.monthly_week_cap', 4)) * (int) config('teacher_salary.weekly_segment_bonus', 1000);
        }
        return [
            'status' => $hasReview ? TeacherEligibilityPolicy::REVIEW : ($allPass ? TeacherEligibilityPolicy::QUALIFIES : TeacherEligibilityPolicy::NOT_QUALIFIES),
            'reason' => $hasReview ? '至少一週待人工確認。' : ($allPass ? '查詢期間每週均符合。' : '查詢期間有未達標週。'),
            'metrics' => [
                'weeks' => $weeklyRows,
                'qualifying_weeks' => collect($weeklyRows)->where('status', TeacherEligibilityPolicy::QUALIFIES)->count(),
                'week_count' => count($weeklyRows),
                'regular_segments' => round($regularSegments, 2),
                'trial_segments' => round($trialSegments, 2),
                'total_segments' => round($regularSegments + $trialSegments, 2),
                'tutoring_sessions' => $tutoringSessions,
                'course_sessions' => $courseSessions,
                'meets_16_segments' => count($weeklyRows) === 1
                    ? collect($weeklyRows)->every(fn ($row) => ($row['metrics']['meets_16_segments'] ?? null) === true)
                    : null,
            ],
            'amount' => $amount,
            'rate' => 0,
            'missing_fields' => collect($weeklyRows)->flatMap(fn ($row) => $row['missing_fields'] ?? [])->unique()->values()->all(),
        ];
    }

    private function finalizePolicyResult(array $components): array
    {
        $missing = collect($components)
            ->flatMap(fn ($component) => $component['missing_fields'] ?? [])
            ->unique()
            ->values()
            ->all();
        $hasReview = collect($components)->contains(
            fn ($component) => ($component['status'] ?? null) === TeacherEligibilityPolicy::REVIEW
        );
        $positiveKeys = ['weekly_16_segments', 'holiday_16_hours', 'weekday_afternoon', 'special_performance', 'admin_allowance'];
        $hasBenefit = collect($positiveKeys)->contains(function ($key) use ($components) {
            $component = $components[$key] ?? [];
            return (float) ($component['rate'] ?? 0) > 0 || (float) ($component['amount'] ?? 0) > 0;
        });

        return [
            'overall_status' => $hasReview
                ? TeacherEligibilityPolicy::REVIEW
                : ($hasBenefit ? TeacherEligibilityPolicy::QUALIFIES : TeacherEligibilityPolicy::NOT_QUALIFIES),
            'components' => $components,
            'missing_fields' => $missing,
        ];
    }

    private function holidayDays($attendanceSessions, $schedules, $events, Carbon $start, Carbon $end, bool $eventsAvailable): ?array
    {
        if (!$eventsAvailable) {
            return null;
        }

        $holidays = $events->filter(fn ($event) => $event->event_type === 'holiday')->groupBy('event_date');
        $result = [];
        foreach ($holidays as $date => $dayEvents) {
            $worked = $attendanceSessions
                ->filter(fn ($row) => substr((string) $row->session_date, 0, 10) === $date)
                ->sum(fn ($row) => $this->studentAttendanceDurationHours($row));
            $regularScheduledHours = $this->scheduledCoverageHoursForDate(
                $schedules->filter(fn ($row) => (string) $row->schedule_date === (string) $date),
                fn ($row) => $this->isRecurringSchedule($row)
            );
            $leaveEvents = $events->filter(fn ($event) => $event->event_type === 'leave' && $event->event_date === $date);
            $leaveHours = $leaveEvents->contains(fn ($event) => $event->holiday_leave_hours === null)
                ? null
                : $leaveEvents->sum(fn ($event) => (float) $event->holiday_leave_hours);
            $result[] = [
                'date' => $date,
                'regular_scheduled_hours' => round($regularScheduledHours, 2),
                'worked_hours' => round($worked, 2),
                'holiday_leave_hours' => $leaveHours === null ? null : round($leaveHours, 2),
            ];
        }
        return $result;
    }

    /**
     * @param  array<int, array{date?:string, status?:string}>  $rows
     * @return list<string>
     */
    private function campusClosedDatesFromSessionRows(array $rows): array
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
            if ($count['open'] === 0) {
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
            ->when($branchFilter !== null, fn ($query) => $query->whereIn('st.CampusID', $branchFilter))
            ->get(['cs.SessionDate as event_date', 'cs.Status as status']);

        return $this->campusClosedDatesFromSessionRows($rows->map(fn ($row) => [
            'date' => substr((string) $row->event_date, 0, 10),
            'status' => $row->status,
        ])->all());
    }

    private function isOpenTeachingSessionStatus(?string $status): bool
    {
        $status = strtolower(trim((string) $status));

        return !in_array($status, [
            'leave', 'leave_adjusted', 'leave_requested', 'excused',
            'cancelled', 'canceled', 'voided', 'suspended', 'holiday',
        ], true);
    }

    private function resolveLeaveEligibility($leaveEvents): ?bool
    {
        if ($leaveEvents->isEmpty()) {
            return false;
        }

        $unknown = $leaveEvents->contains(function ($event) {
            return $event->makeup_completed === null
                && ($event->holiday_leave_hours === null || $event->hours === null);
        });
        if ($unknown) {
            return null;
        }

        return $leaveEvents->contains(function ($event) {
            return (bool) ($event->makeup_completed ?? false)
                || ((float) ($event->holiday_leave_hours ?? 0) >= (float) ($event->hours ?? 0));
        });
    }

    private function existingLeaveEvents(array $teacherIds, ?array $branchFilter, Carbon $start, Carbon $end)
    {
        if (!Schema::hasTable('ClassSession')) {
            return collect();
        }

        $query = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as st', 'st.id', '=', 'sc.StudentID')
            ->whereIn('sc.TeacherID', $teacherIds)
            ->whereBetween('cs.SessionDate', [$start->toDateString(), $end->toDateString()])
            ->when($branchFilter !== null, fn ($query) => $query->whereIn('st.CampusID', $branchFilter));
        if (Schema::hasTable('StudentSingIn')) {
            $query->leftJoin('StudentSingIn as si', 'si.ClassSessionID', '=', 'cs.id')
                ->where(function ($nested) {
                    $nested->whereIn('cs.Status', ['leave', 'leave_adjusted', 'leave_requested', 'excused'])
                        ->orWhereIn('si.Status', ['leave', 'excused']);
                });
        } else {
            $query->whereIn('cs.Status', ['leave', 'leave_adjusted', 'leave_requested', 'excused']);
        }

        return $query->get([
                'sc.TeacherID as teacher_id', 'cs.SessionDate as event_date', 'cs.Status as source_status',
                'cs.StartTime as start_time', 'cs.EndTime as end_time',
            ])
            ->map(function ($row) {
                $hours = null;
                try {
                    $hours = Carbon::parse((string) $row->start_time)->diffInMinutes(Carbon::parse((string) $row->end_time)) / 60;
                } catch (\Throwable) {
                    // Keep null: missing duration must remain reviewable.
                }

                return (object) [
                    'teacher_id' => (int) $row->teacher_id,
                    'event_date' => substr((string) $row->event_date, 0, 10),
                    'event_type' => 'leave',
                    'hours' => $hours,
                    'holiday_leave_hours' => null,
                    'makeup_completed' => $row->source_status === 'leave_adjusted' ? true : null,
                ];
            });
    }

    /**
     * Prefer approved LearningRecords for subject units, then fall back to
     * valid attended ClassSessions. Do not derive subject count from
     * schedules: schedules are a teaching-plan source.
     *
     * A source that exists but has no rows is a known zero. An unparseable
     * duration remains unknown rather than becoming a two-hour default.
     */
    private function subjectUnitsByTeacher(array $teacherIds, ?array $branchFilter, Carbon $start, Carbon $end): array
    {
        $hasLearningRecords = Schema::hasTable('LearningRecord');
        $hasAttendance = Schema::hasTable('ClassSession')
            && Schema::hasTable('StudentClass')
            && Schema::hasTable('StudentSingIn');
        if (!$hasLearningRecords && !$hasAttendance) {
            return collect($teacherIds)->mapWithKeys(fn ($id) => [(int) $id => null])->all();
        }

        $buckets = collect($teacherIds)->mapWithKeys(fn ($id) => [(int) $id => [
            'regular' => 0.0, 'tutoring_trial' => 0.0, 'one_to_three' => 0.0,
        ]])->all();
        $unknown = [];
        $loggedSessionIds = [];
        $addRow = function ($row, float $weight, string $bucket) use (&$buckets, &$unknown): void {
            $teacherId = (int) $row->teacher_id;
            if (!isset($buckets[$teacherId])) return;
            $hours = $this->subjectRecordHours($row);
            if ($hours === null) {
                $unknown[$teacherId] = true;
                return;
            }
            $buckets[$teacherId][$bucket] += $hours * $weight;
        };

        if ($hasLearningRecords) {
            $query = DB::table('LearningRecord as lr')
                ->join('StudentClass as sc', 'sc.ID', '=', 'lr.StudentClassID')
                ->join('Student as s', 's.id', '=', 'sc.StudentID')
                ->whereIn('lr.TeacherID', $teacherIds)
                ->where('lr.Status', 'approved')
                ->whereNull('lr.VoidedAt')
                ->whereBetween('lr.SessionDate', [$start->toDateString(), $end->toDateString()])
                ->when($branchFilter !== null, fn ($builder) => $builder->whereIn('s.CampusID', $branchFilter))
                ->select([
                    'lr.TeacherID as teacher_id', 'lr.ClassSessionID as class_session_id',
                    'lr.StartTime as start_time', 'lr.EndTime as end_time', 'lr.SessionDate as session_date',
                    'sc.ClassType as class_type', 'sc.SessionDuration as session_duration',
                    'sc.week1', 'sc.week2', 'sc.week3', 'sc.week4', 'sc.week5', 'sc.week6',
                    'sc.duration1', 'sc.duration2', 'sc.duration3', 'sc.duration4', 'sc.duration5', 'sc.duration6',
                ]);
            if (Schema::hasColumn('LearningRecord', 'ExcludeFromSubjectCount')) {
                $query->where(function ($builder) {
                    $builder->whereNull('lr.ExcludeFromSubjectCount')->orWhere('lr.ExcludeFromSubjectCount', 0);
                });
            }
            foreach ($query->get() as $row) {
                $classType = strtolower((string) ($row->class_type ?? 'one_on_one'));
                if (in_array($classType, ['trial', 'tutoring'], true)) continue;
                if ($row->class_session_id !== null) $loggedSessionIds[] = (int) $row->class_session_id;
                $addRow($row, match ($classType) {
                    'one_on_two' => 0.75,
                    'one_on_three' => 0.5,
                    default => 1.5,
                }, $classType === 'one_on_three' ? 'one_to_three' : 'regular');
            }
        }

        if ($hasAttendance) {
            $query = DB::table('ClassSession as cs')
                ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
                ->join('StudentSingIn as si', 'si.ClassSessionID', '=', 'cs.id')
                ->join('Student as s', 's.id', '=', 'sc.StudentID')
                ->whereIn('sc.TeacherID', $teacherIds)
                ->whereBetween('cs.SessionDate', [$start->toDateString(), $end->toDateString()])
                ->whereNull('si.VoidedAt')
                ->whereIn('si.Status', $this->weeklyAttendanceStatuses())
                ->whereNotIn('cs.Status', ['cancelled', 'voided', 'leave', 'leave_adjusted', 'leave_requested', 'excused'])
                ->whereNotIn('sc.ClassType', ['trial', 'tutoring'])
                ->when($branchFilter !== null, fn ($builder) => $builder->whereIn('s.CampusID', $branchFilter))
                ->when($loggedSessionIds !== [], fn ($builder) => $builder->whereNotIn('cs.id', array_values(array_unique($loggedSessionIds))))
                ->select([
                    'sc.TeacherID as teacher_id', 'cs.id as class_session_id', 'cs.StartTime as start_time',
                    'cs.EndTime as end_time', 'cs.SessionDate as session_date', 'sc.ClassType as class_type',
                    'sc.SessionDuration as session_duration', 'sc.week1', 'sc.week2', 'sc.week3', 'sc.week4',
                    'sc.week5', 'sc.week6', 'sc.duration1', 'sc.duration2', 'sc.duration3', 'sc.duration4',
                    'sc.duration5', 'sc.duration6',
                ]);
            foreach ($query->get()->unique('class_session_id') as $row) {
                $classType = strtolower((string) ($row->class_type ?? 'one_on_one'));
                $addRow($row, match ($classType) {
                    'one_on_two' => 0.75,
                    'one_on_three' => 0.5,
                    default => 1.5,
                }, $classType === 'one_on_three' ? 'one_to_three' : 'regular');
            }

            foreach (['tutoring', 'trial'] as $classType) {
                $query = DB::table('ClassSession as cs')
                    ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
                    ->join('StudentSingIn as si', 'si.ClassSessionID', '=', 'cs.id')
                    ->join('Student as s', 's.id', '=', 'sc.StudentID')
                    ->whereIn('sc.TeacherID', $teacherIds)
                    ->where('sc.ClassType', $classType)
                    ->whereBetween('cs.SessionDate', [$start->toDateString(), $end->toDateString()])
                    ->whereNull('si.VoidedAt')
                    ->whereIn('si.Status', $this->weeklyAttendanceStatuses())
                    ->whereNotIn('cs.Status', ['cancelled', 'voided', 'leave', 'leave_adjusted', 'leave_requested', 'excused'])
                    ->when($branchFilter !== null, fn ($builder) => $builder->whereIn('s.CampusID', $branchFilter))
                    ->select([
                        'sc.TeacherID as teacher_id', 'cs.id as class_session_id', 'cs.StartTime as start_time',
                        'cs.EndTime as end_time', 'cs.SessionDate as session_date', 'sc.SessionDuration as session_duration',
                        'sc.week1', 'sc.week2', 'sc.week3', 'sc.week4', 'sc.week5', 'sc.week6',
                        'sc.duration1', 'sc.duration2', 'sc.duration3', 'sc.duration4', 'sc.duration5', 'sc.duration6',
                    ]);
                foreach ($query->get()->unique('class_session_id') as $row) {
                    $addRow($row, 0.5, 'tutoring_trial');
                }
            }
        }

        return collect($buckets)->mapWithKeys(function ($parts, $teacherId) use ($unknown) {
            if (!empty($unknown[$teacherId])) return [$teacherId => null];
            $regular = round($parts['regular'] / 8, 4);
            $tutoringTrial = round($parts['tutoring_trial'] / 8, 4);
            $oneToThree = round($parts['one_to_three'] / 8, 4);
            return [$teacherId => [
                'regular' => $regular,
                'tutoring_trial' => $tutoringTrial,
                'one_to_three' => $oneToThree,
                'payroll_total' => round($regular + $tutoringTrial, 4),
            ]];
        })->all();
    }

    /**
     * Latest fulltime_salary_profiles row at or before $onDate, per teacher.
     * Scoped the same way as scopedQuery()/subjectUnitsByTeacher(): a teacher
     * shared across campuses can have a different base_salary row per branch,
     * so a branch-scoped viewer must not see another campus's profile.
     * @return array<int, float>
     */
    private function salaryProfilesByTeacher(array $teacherIds, ?array $branchFilter, string $onDate): array
    {
        if (!Schema::hasTable('fulltime_salary_profiles') || $teacherIds === []) {
            return [];
        }

        return DB::table('fulltime_salary_profiles')
            ->whereIn('teacher_id', $teacherIds)
            ->where('status', 'approved') // TD-078: unapproved writes must never feed payroll
            ->where('effective_from', '<=', $onDate)
            ->when($branchFilter !== null, fn ($query) => $query->where(function ($nested) use ($branchFilter) {
                $nested->whereNull('branch_id')->orWhereIn('branch_id', $branchFilter);
            }))
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get(['teacher_id', 'base_salary', 'effective_from', 'id'])
            ->groupBy('teacher_id')
            ->map(fn ($rows) => (float) $rows->last()->base_salary)
            ->all();
    }

    /**
     * @return array<int, array{id:int,base_salary:float,effective_from:string}>
     */
    private function pendingSalaryByTeacher(array $teacherIds, ?array $branchFilter): array
    {
        if (!Schema::hasTable('fulltime_salary_profiles') || $teacherIds === []) {
            return [];
        }

        return DB::table('fulltime_salary_profiles')
            ->whereIn('teacher_id', $teacherIds)
            ->where('status', 'pending')
            ->when($branchFilter !== null, fn ($query) => $query->where(function ($nested) use ($branchFilter) {
                $nested->whereNull('branch_id')->orWhereIn('branch_id', $branchFilter);
            }))
            ->orderBy('id')
            ->get(['id', 'teacher_id', 'base_salary', 'effective_from'])
            ->groupBy('teacher_id')
            ->map(fn ($rows) => [
                'id' => (int) $rows->last()->id,
                'base_salary' => (float) $rows->last()->base_salary,
                'effective_from' => (string) $rows->last()->effective_from,
            ])
            ->all();
    }

    private function subjectRecordHours($row): ?float
    {
        $weekday = null;
        if (!empty($row->session_date)) {
            try {
                $weekday = Carbon::parse($row->session_date)->isoWeekday();
            } catch (\Throwable) {
                $weekday = null;
            }
        }

        if ($weekday !== null) {
            $weekField = 'week' . $weekday;
            $durationField = 'duration' . $weekday;
            if ((int) ($row->{$weekField} ?? 0) === $weekday && (int) ($row->{$durationField} ?? 0) >= 30) {
                return (int) $row->{$durationField} / 60;
            }
        }

        if ((int) ($row->session_duration ?? 0) >= 30) {
            return (int) $row->session_duration / 60;
        }

        if (!empty($row->start_time) && !empty($row->end_time)) {
            try {
                $start = Carbon::parse((string) $row->start_time);
                $end = Carbon::parse((string) $row->end_time);
                if ($end->gt($start)) {
                    return $start->diffInMinutes($end) / 60;
                }
            } catch (\Throwable) {
                // Keep unknown: a missing/invalid duration must not become pay.
            }
        }

        return null;
    }

    private function weekdayHours($schedules, Carbon $start, Carbon $end): array
    {
        $intervalsByDate = [];
        $fallbackHoursByDate = [];
        foreach ($schedules as $row) {
            if (!$this->isRecurringSchedule($row)) continue;
            $date = (string) $row->schedule_date;
            if (!$date || $date < $start->toDateString() || $date > $end->toDateString()) continue;
            $day = Carbon::parse($date)->dayOfWeekIso;
            if ($day >= 6) continue;

            $startMinutes = $this->clockMinutes($row->start_time ?? null);
            $endMinutes = $this->clockMinutes($row->end_time ?? null);
            if ($startMinutes !== null && $endMinutes !== null && $endMinutes > $startMinutes) {
                $intervalsByDate[$date][] = [$startMinutes, $endMinutes];
                continue;
            }

            // Preserve the previous duration fallback when a legacy schedule
            // row has no parseable clock window; it remains reviewable data,
            // but must not make valid overlapping windows double-count.
            $fallbackHoursByDate[$date] = ($fallbackHoursByDate[$date] ?? 0) + $this->durationHours($row);
        }

        $hours = [];
        foreach (array_unique(array_merge(array_keys($intervalsByDate), array_keys($fallbackHoursByDate))) as $date) {
            $coverageMinutes = 0;
            $mergedEnd = null;
            $mergedStart = null;
            $intervals = $intervalsByDate[$date] ?? [];
            usort($intervals, fn ($left, $right) => $left[0] <=> $right[0] ?: $left[1] <=> $right[1]);
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
            $hours[$date] = round(($coverageMinutes / 60) + ($fallbackHoursByDate[$date] ?? 0), 2);
        }

        return array_map(fn ($value) => round($value, 2), $hours);
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

    private function isRegularAssignable($row): bool
    {
        $classType = strtolower((string) ($row->class_type ?? ''));
        $type = strtolower((string) ($row->type ?? ''));
        return !in_array($classType, ['tutoring', 'trial', 'admin'], true)
            && !in_array($type, ['tutoring', 'admin', 'unavailable'], true);
    }

    private function isRecurringSchedule($row): bool
    {
        if (!$this->isRegularAssignable($row)) {
            return false;
        }

        $type = strtolower((string) ($row->type ?? ''));
        $origin = strtolower((string) ($row->schedule_origin ?? $row->origin ?? ''));

        return !in_array($type, ['extra', 'makeup', 'temporary', 'temp', 'one_off', 'one-time'], true)
            && !in_array($origin, ['extra', 'makeup', 'temporary', 'temp', 'one_off', 'one-time'], true);
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
            return Carbon::parse((string) $row->start_time)->diffInMinutes(Carbon::parse((string) $row->end_time)) / 60;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function classSessionDurationHours($row): ?float
    {
        if (!empty($row->start_time) && !empty($row->end_time)) {
            try {
                $start = Carbon::parse((string) $row->start_time);
                $end = Carbon::parse((string) $row->end_time);
                if ($end->gt($start)) return $start->diffInMinutes($end) / 60;
            } catch (\Throwable) {
                // Keep unknown: actual teaching time is required for a segment.
            }
        }
        return null;
    }

    private function studentAttendanceDurationHours($row): float
    {
        if (!empty($row->student_sign_in_at) && !empty($row->student_sign_out_at)) {
            try {
                $start = Carbon::parse((string) $row->student_sign_in_at);
                $end = Carbon::parse((string) $row->student_sign_out_at);
                if ($end->gt($start)) {
                    return round($start->diffInMinutes($end) / 60, 2);
                }
            } catch (\Throwable) {
                // Fall back to the persisted ClassSession duration below.
            }
        }

        // A student may be marked present without a reliable sign-out time;
        // use the persisted class-session window instead of returning review.
        return $this->durationHours($row);
    }

    /** @return list<string> */
    private function payableStudentAttendanceStatuses(): array
    {
        // Include legacy values written by older attendance paths.
        return array_values(array_unique(array_merge(
            AttendanceStatus::payableCodes(),
            ['attended', 'completed']
        )));
    }

    /** @return list<string> */
    private function weeklyAttendanceStatuses(): array
    {
        return array_values(array_unique(array_merge(
            $this->payableStudentAttendanceStatuses(),
            ['trial', 'tutoring']
        )));
    }

    private function isValidWeeklyAttendance($row): bool
    {
        $status = strtolower((string) ($row->attendance_status ?? ''));
        $classType = strtolower((string) ($row->class_type ?? ''));
        return !in_array($status, ['absent', 'leave', 'trial_absent', 'tutoring_absent'], true)
            && in_array($status, $this->weeklyAttendanceStatuses(), true)
            && !in_array($classType, ['admin'], true);
    }

    private function isEligibleTeachingAttendance($row): bool
    {
        $classType = strtolower((string) ($row->class_type ?? ''));
        return !in_array($classType, ['trial', 'tutoring'], true)
            && in_array(strtolower((string) ($row->attendance_status ?? '')), $this->payableStudentAttendanceStatuses(), true);
    }

    private function splitIntoWeeks(Carbon $start, Carbon $end): array
    {
        $weeks = [];
        $cursor = $start->copy()->startOfWeek(Carbon::MONDAY);
        while ($cursor->lte($end)) {
            $weekStart = $cursor->copy()->max($start->copy());
            $weekEnd = $cursor->copy()->endOfWeek(Carbon::SUNDAY)->min($end->copy());
            $weeks[] = [$weekStart->startOfDay(), $weekEnd->endOfDay()];
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
