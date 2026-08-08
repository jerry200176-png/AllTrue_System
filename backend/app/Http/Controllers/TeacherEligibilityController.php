<?php

namespace App\Http\Controllers;

use App\Services\TeacherEligibilityPolicy;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
            ]);
        }

        $teacherIds = $teachers->pluck('id')->map(fn ($id) => (int) $id)->all();
        $branchFilter = $campusIds !== null ? $campusIds : null;
        $schedules = DB::table('schedules')
            ->whereIn('teacher_id', $teacherIds)
            ->whereBetween('schedule_date', [$effectiveStart->toDateString(), $period['end']->toDateString()])
            ->where('status', '!=', 'cancelled')
            ->when($branchFilter !== null, fn ($query) => $query->whereIn('branch_id', $branchFilter))
            ->get();

        $signIns = DB::table('TeacherSingIn')
            ->whereIn('TeacherID', $teacherIds)
            ->whereDate('SignInDT', '>=', $effectiveStart->toDateString())
            ->whereDate('SignInDT', '<=', $period['end']->toDateString())
            ->when($branchFilter !== null, fn ($query) => $query->whereIn('CampusID', $branchFilter))
            ->get();

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
                }))->get()
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
                }))->get()
            : collect();

        $scheduleByTeacher = $schedules->groupBy('teacher_id');
        $signInByTeacher = $signIns->groupBy('TeacherID');
        $eventByTeacher = $events->groupBy(fn ($event) => $event->teacher_id ? (string) $event->teacher_id : '*');
        $achievementByTeacher = $achievements->groupBy('teacher_id');
        $deductionByTeacher = $deductions->groupBy('teacher_id');

        $rows = $teachers->map(function ($teacher) use ($period, $effectiveStart, $scheduleByTeacher, $signInByTeacher, $eventByTeacher, $achievementByTeacher, $deductionByTeacher) {
            $teacherId = (int) $teacher->id;
            $teacherSchedules = $scheduleByTeacher->get($teacherId, collect());
            $teacherSignIns = $signInByTeacher->get($teacherId, collect());
            $teacherEvents = $eventByTeacher->get((string) $teacherId, collect())->concat($eventByTeacher->get('*', collect()));
            $weeklyRows = [];
            foreach ($this->splitIntoWeeks($effectiveStart, $period['end']) as [$weekStart, $weekEnd]) {
                $weeklyRows[] = $this->evaluateWeek($weekStart, $weekEnd, $teacherSchedules, $teacherSignIns, $teacherEvents);
            }

            $weeklyStatus = $this->aggregateWeeklyStatus($weeklyRows);
            $holidayDays = $this->holidayDays($teacherSchedules, $teacherSignIns, $teacherEvents, $effectiveStart, $period['end']);
            $weekdayHours = $this->weekdayHours($teacherSchedules, $effectiveStart, $period['end']);
            $subjectCount = $teacherSchedules
                ->map(fn ($row) => trim((string) ($row->subject ?? '')))
                ->filter()
                ->unique()
                ->count();

            $result = $this->policy->evaluate([
                'period_start' => $effectiveStart->toDateString(),
                'period_end' => $period['end']->toDateString(),
                'weekly' => [
                    'segments' => 0,
                    'work_hours' => 0,
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
                'subject_count' => $subjectCount,
            ]);
            $result['components']['weekly_16_segments'] = $weeklyStatus;

            return [
                'teacher_id' => $teacherId,
                'teacher_name' => (string) ($teacher->Name ?? '未命名老師'),
                'employment_type' => (string) ($teacher->employment_type ?? 'full_time'),
                'overall_status' => $result['overall_status'],
                'components' => $result['components'],
                'weekly' => $weeklyRows,
                'missing_fields' => $result['missing_fields'],
                'review_required' => $result['overall_status'] === TeacherEligibilityPolicy::REVIEW,
            ];
        })->values()->all();

        return response()->json([
            'policy_version' => config('teacher_salary.policy_version'),
            'effective_from' => config('teacher_salary.effective_from'),
            'period' => ['type' => $period['type'], 'start' => $effectiveStart->toDateString(), 'end' => $period['end']->toDateString()],
            'components' => ['weekly_16_segments', 'holiday_16_hours', 'weekday_afternoon', 'special_performance', 'deductions', 'subject_count_bonus'],
            'teachers' => $rows,
            'total_teachers' => count($rows),
        ]);
    }

    private function evaluateWeek(Carbon $start, Carbon $end, $schedules, $signIns, $events): array
    {
        $weekSchedules = $schedules->filter(fn ($row) => $row->schedule_date >= $start->toDateString() && $row->schedule_date <= $end->toDateString());
        $segments = 0.0;
        foreach ($weekSchedules as $row) {
            if (!$this->isRegularAssignable($row)) continue;
            $segments += $this->durationHours($row) / 2;
        }
        $weekSignIns = $signIns->filter(fn ($row) => substr((string) $row->SignInDT, 0, 10) >= $start->toDateString() && substr((string) $row->SignInDT, 0, 10) <= $end->toDateString());
        $workHours = 0.0;
        $workHoursKnown = $weekSignIns->isNotEmpty();
        foreach ($weekSignIns as $row) {
            if (!$row->SignOutDT) {
                $workHoursKnown = false;
                continue;
            }
            try {
                $workHours += Carbon::parse($row->SignInDT)->diffInMinutes(Carbon::parse($row->SignOutDT)) / 60;
            } catch (\Throwable) {
                $workHoursKnown = false;
            }
        }
        $official = $events->contains(fn ($event) => $event->event_type === 'official_closure' && $event->event_date >= $start->toDateString() && $event->event_date <= $end->toDateString());
        $leaveEligible = $events->contains(function ($event) use ($start, $end) {
            return $event->event_type === 'leave'
                && $event->event_date >= $start->toDateString()
                && $event->event_date <= $end->toDateString()
                && (($event->makeup_completed ?? false) || ((float) ($event->holiday_leave_hours ?? 0) >= (float) ($event->hours ?? 0)));
        });

        $policy = $this->policy->weekly16([
            'segments' => $segments,
            'work_hours' => $workHoursKnown ? $workHours : null,
            'exception' => ['official_event' => $official, 'leave_eligible' => $leaveEligible],
        ]);
        $policy['metrics']['week_start'] = $start->toDateString();
        $policy['metrics']['week_end'] = $end->toDateString();
        return $policy;
    }

    private function aggregateWeeklyStatus(array $weeklyRows): array
    {
        $hasReview = collect($weeklyRows)->contains(fn ($row) => $row['status'] === TeacherEligibilityPolicy::REVIEW);
        $allPass = $weeklyRows !== [] && collect($weeklyRows)->every(fn ($row) => $row['status'] === TeacherEligibilityPolicy::QUALIFIES);
        $amount = 0;
        $months = [];
        foreach ($weeklyRows as $row) {
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
            ],
            'amount' => $amount,
            'rate' => 0,
            'missing_fields' => collect($weeklyRows)->flatMap(fn ($row) => $row['missing_fields'] ?? [])->unique()->values()->all(),
        ];
    }

    private function holidayDays($schedules, $signIns, $events, Carbon $start, Carbon $end): array
    {
        $holidays = $events->filter(fn ($event) => $event->event_type === 'holiday')->groupBy('event_date');
        $result = [];
        foreach ($holidays as $date => $dayEvents) {
            $worked = $signIns->filter(fn ($row) => substr((string) $row->SignInDT, 0, 10) === $date)->sum(function ($row) {
                if (!$row->SignOutDT) return 0;
                try { return Carbon::parse($row->SignInDT)->diffInMinutes(Carbon::parse($row->SignOutDT)) / 60; } catch (\Throwable) { return 0; }
            });
            $leaveHours = $events->filter(fn ($event) => $event->event_type === 'leave' && $event->event_date === $date)->sum(fn ($event) => (float) ($event->holiday_leave_hours ?? 0));
            $result[] = ['date' => $date, 'worked_hours' => round($worked, 2), 'holiday_leave_hours' => round($leaveHours, 2)];
        }
        return $result;
    }

    private function weekdayHours($schedules, Carbon $start, Carbon $end): array
    {
        $hours = [];
        foreach ($schedules as $row) {
            if (!$this->isRegularAssignable($row)) continue;
            $date = (string) $row->schedule_date;
            if (!$date || $date < $start->toDateString() || $date > $end->toDateString()) continue;
            $day = Carbon::parse($date)->dayOfWeekIso;
            if ($day >= 6) continue;
            $hours[$date] = ($hours[$date] ?? 0) + $this->durationHours($row);
        }
        return array_map(fn ($value) => round($value, 2), $hours);
    }

    private function isRegularAssignable($row): bool
    {
        $classType = strtolower((string) ($row->class_type ?? ''));
        $type = strtolower((string) ($row->type ?? ''));
        return !in_array($classType, ['tutoring', 'trial', 'admin'], true)
            && !in_array($type, ['tutoring', 'admin', 'unavailable'], true);
    }

    private function durationHours($row): float
    {
        if ($row->duration_hours !== null && (float) $row->duration_hours > 0) return (float) $row->duration_hours;
        try {
            return Carbon::parse((string) $row->start_time)->diffInMinutes(Carbon::parse((string) $row->end_time)) / 60;
        } catch (\Throwable) {
            return 0;
        }
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
