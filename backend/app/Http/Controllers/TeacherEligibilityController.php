<?php

namespace App\Http\Controllers;

use App\Services\TeacherEligibilityPolicy;
use App\Support\AttendanceStatus;
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
            ->whereNotIn('status', ['cancelled', 'leave', 'leave_requested'])
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
                ->whereIn('si.Status', $this->payableStudentAttendanceStatuses())
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
                ->filter(fn ($row) => $this->isEligibleTeachingAttendance($row))
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

        // Existing attendance/leave is the product's source of truth. The
        // additive payroll event table is only for policy facts that the core
        // product does not model (for example a company holiday calendar).
        $events = $events->concat($this->existingLeaveEvents($teacherIds, $branchFilter, $effectiveStart, $period['end']));

        $scheduleByTeacher = $schedules->groupBy('teacher_id');
        $attendanceByTeacher = $attendanceSessions->groupBy('teacher_id');
        $eventByTeacher = $events->groupBy(fn ($event) => $event->teacher_id ? (string) $event->teacher_id : '*');
        $achievementByTeacher = $achievements->groupBy('teacher_id');
        $deductionByTeacher = $deductions->groupBy('teacher_id');
        $eventsAvailable = Schema::hasTable('teacher_payroll_events') && $events->isNotEmpty();
        $subjectUnitsByTeacher = $this->subjectUnitsByTeacher($teacherIds, $branchFilter, $effectiveStart, $period['end']);
        $salaryByTeacher = $this->salaryProfilesByTeacher($teacherIds, $branchFilter, $period['end']->toDateString());

        $rows = $teachers->map(function ($teacher) use ($period, $effectiveStart, $scheduleByTeacher, $attendanceByTeacher, $eventByTeacher, $achievementByTeacher, $deductionByTeacher, $eventsAvailable, $attendanceSourceAvailable, $subjectUnitsByTeacher, $salaryByTeacher) {
            $teacherId = (int) $teacher->id;
            $teacherSchedules = $scheduleByTeacher->get($teacherId, collect());
            $teacherAttendance = $attendanceByTeacher->get($teacherId, collect());
            $teacherEvents = $eventByTeacher->get((string) $teacherId, collect())->concat($eventByTeacher->get('*', collect()));
            $weeklyRows = [];
            foreach ($this->splitIntoWeeks($effectiveStart, $period['end']) as [$weekStart, $weekEnd]) {
                $weeklyRows[] = $this->evaluateWeek($weekStart, $weekEnd, $teacherSchedules, $teacherAttendance, $teacherEvents, $eventsAvailable, $attendanceSourceAvailable);
            }

            $weeklyStatus = $this->aggregateWeeklyStatus($weeklyRows);
            $holidayCalendarAvailable = $teacherEvents->contains(fn ($event) => $event->event_type === 'holiday');
            $holidayDays = $this->holidayDays($teacherAttendance, $teacherSchedules, $teacherEvents, $effectiveStart, $period['end'], $holidayCalendarAvailable);
            $weekdayHours = $this->weekdayHours($teacherSchedules, $effectiveStart, $period['end']);
            $subjectCount = $subjectUnitsByTeacher[$teacherId] ?? null;

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
            $settlement = \App\Support\FulltimeSettlementComposer::compose($result['components'], $salaryByTeacher[$teacherId] ?? null);

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
                'settlement' => $settlement,
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

    private function evaluateWeek(Carbon $start, Carbon $end, $schedules, $attendanceSessions, $events, bool $eventsAvailable, bool $attendanceSourceAvailable): array
    {
        $weekSchedules = $schedules->filter(fn ($row) => $row->schedule_date >= $start->toDateString() && $row->schedule_date <= $end->toDateString());
        $segments = 0.0;
        foreach ($weekSchedules as $row) {
            if (!$this->isRegularAssignable($row)) continue;
            $segments += $this->durationHours($row) / 2;
        }
        $weekAttendance = $attendanceSessions->filter(fn ($row) => substr((string) $row->session_date, 0, 10) >= $start->toDateString() && substr((string) $row->session_date, 0, 10) <= $end->toDateString());
        $workHours = round($weekAttendance->sum(fn ($row) => $this->studentAttendanceDurationHours($row)), 2);
        // An empty week is a known zero when the student-attendance source is
        // available; it must not become a missing TeacherSingIn review.
        $workHoursKnown = $attendanceSourceAvailable;
        $weekEvents = $events->filter(fn ($event) => $event->event_date >= $start->toDateString() && $event->event_date <= $end->toDateString());
        $official = $eventsAvailable && $weekEvents->contains(fn ($event) => $event->event_type === 'official_closure');
        $leaveEvents = $weekEvents->filter(fn ($event) => $event->event_type === 'leave');
        $leaveEligible = $this->resolveLeaveEligibility($leaveEvents);
        $saturdayLeaveBlocked = false;
        $hasSaturdayLeave = $leaveEvents->contains(fn ($event) => Carbon::parse((string) $event->event_date)->dayOfWeekIso === 6);
        if ($hasSaturdayLeave) {
            $sundayHasRegularClass = $weekSchedules->contains(fn ($row) => Carbon::parse($row->schedule_date)->dayOfWeekIso === 7 && $this->isRegularAssignable($row));
            $saturdayLeaveBlocked = !$sundayHasRegularClass && $leaveEligible === false;
        }

        $policy = $this->policy->weekly16([
            'segments' => $segments,
            'work_hours' => $workHoursKnown ? $workHours : null,
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
     * Use the existing approved-LearningRecord subject-unit pipeline for the
     * salary table. Do not derive subject count from schedules: schedules are
     * a teaching-plan source, while subject units are an approved-assessment
     * source (see OPERATIONS_RUNBOOK §3).
     *
     * Returns null for teachers without an approved source row so the policy
     * can report review/missing data instead of silently turning it into zero.
     */
    private function subjectUnitsByTeacher(array $teacherIds, ?array $branchFilter, Carbon $start, Carbon $end): array
    {
        $query = DB::table('LearningRecord as lr')
            ->join('StudentClass as sc', 'sc.ID', '=', 'lr.StudentClassID')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->whereIn('lr.TeacherID', $teacherIds)
            ->where('lr.Status', 'approved')
            ->whereNull('lr.VoidedAt')
            ->whereBetween('lr.SessionDate', [$start->toDateString(), $end->toDateString()])
            ->when($branchFilter !== null, fn ($builder) => $builder->whereIn('s.CampusID', $branchFilter))
            ->select([
                'lr.TeacherID as teacher_id', 'lr.StartTime as start_time', 'lr.EndTime as end_time',
                'lr.SessionDate as session_date', 'sc.ClassType as class_type',
                'sc.SessionDuration as session_duration',
                'sc.week1', 'sc.week2', 'sc.week3', 'sc.week4', 'sc.week5', 'sc.week6',
                'sc.duration1', 'sc.duration2', 'sc.duration3', 'sc.duration4', 'sc.duration5', 'sc.duration6',
            ]);

        if (Schema::hasColumn('LearningRecord', 'ExcludeFromSubjectCount')) {
            $query->where(function ($builder) {
                $builder->whereNull('lr.ExcludeFromSubjectCount')->orWhere('lr.ExcludeFromSubjectCount', 0);
            });
        }

        $buckets = [];
        foreach ($query->get() as $row) {
            $classType = strtolower((string) ($row->class_type ?? 'one_on_one'));
            if (in_array($classType, ['trial', 'tutoring'], true)) {
                continue;
            }

            $weight = match ($classType) {
                'one_on_two' => 0.75,
                'one_on_three' => 0.5,
                default => 1.5,
            };
            $teacherId = (int) $row->teacher_id;
            $buckets[$teacherId] = ($buckets[$teacherId] ?? 0.0) + ($this->subjectRecordHours($row) * $weight);
        }

        $tutoringQuery = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->whereIn('sc.TeacherID', $teacherIds)
            ->where('sc.ClassType', 'tutoring')
            ->whereIn('cs.Status', ['attended', 'completed'])
            ->whereBetween('cs.SessionDate', [$start->toDateString(), $end->toDateString()])
            ->when($branchFilter !== null, fn ($builder) => $builder->whereIn('s.CampusID', $branchFilter))
            ->select([
                'sc.TeacherID as teacher_id', 'cs.StartTime as start_time', 'cs.EndTime as end_time',
                'cs.SessionDate as session_date', 'sc.SessionDuration as session_duration',
                'sc.week1', 'sc.week2', 'sc.week3', 'sc.week4', 'sc.week5', 'sc.week6',
                'sc.duration1', 'sc.duration2', 'sc.duration3', 'sc.duration4', 'sc.duration5', 'sc.duration6',
            ]);

        foreach ($tutoringQuery->get() as $row) {
            $teacherId = (int) $row->teacher_id;
            $buckets[$teacherId] = ($buckets[$teacherId] ?? 0.0) + ($this->subjectRecordHours($row) * 0.5);
        }

        return collect($buckets)->mapWithKeys(fn ($weighted, $teacherId) => [$teacherId => round($weighted / 8, 2)])->all();
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

    private function subjectRecordHours($row): float
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
                // Fall through to the same documented default as FinanceController.
            }
        }

        return 2.0;
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
