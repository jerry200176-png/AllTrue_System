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

        $teachers = $this->fullTimeTeachers($campusIds);

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
            // Records this campus entered for those teachers and has not finished approving: once this campus locks
            // the month they can no longer be approved, and the settling campus stays blocked by them.
            $unfinished = $this->unfinishedInputsByTeacher($moved, $lockBranchId, $effectiveStart, $period['end']);
            foreach ($settledElsewhere as &$row) {
                $row['pending_inputs'] = $unfinished[$row['teacher_id']] ?? 0;
            }
            unset($row);
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
        $uncountedByTeacher = $weeks === [] ? [] : $this->uncountedLessonsByTeacher($teacherIds, $weeks[0][0]->toDateString(), end($weeks)[1]->toDateString());
        $nearClosures = $weeks === [] ? [] : $this->nearClosureDays($weeks[0][0], end($weeks)[1]);
        $today = Carbon::now('Asia/Taipei')->toDateString();

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

        $rows = $teachers->map(function ($teacher) use ($period, $effectiveStart, $weeks, $closureDates, $uncountedByTeacher, $nearClosures, $today, $lessonsByTeacher, $achievementByTeacher, $deductionByTeacher, $allowanceByTeacher, $cashByTeacher, $cashSourceAvailable, $allowanceSourceAvailable, $subjectUnitsByTeacher, $firstLessonByTeacher) {
            $teacherId = (int) $teacher->id;
            $weeklyRows = [];
            foreach ($weeks as [$weekStart, $weekEnd]) {
                $weeklyRows[] = $this->evaluateWeek($weekStart, $weekEnd, $lessonsByTeacher[$teacherId] ?? [], $closureDates, $uncountedByTeacher[$teacherId] ?? [], $today, $nearClosures);
            }

            $weeklyStatus = $this->aggregateWeeklyStatus($weeklyRows);
            // Closure weeks are waived and weeks still running are not judged yet: neither feeds the two rates.
            $judgedWeeks = array_column(array_filter($weeklyRows, fn ($week) => $week['metrics']['closure_dates'] === [] && $week['metrics']['finished']), 'metrics');
            $subjectUnits = $subjectUnitsByTeacher[$teacherId] ?? null;
            $subjectCount = is_array($subjectUnits) ? ($subjectUnits['payroll_total'] ?? null) : null;

            $result = $this->policy->evaluate([
                'period_start' => $effectiveStart->toDateString(),
                'period_end' => $period['end']->toDateString(),
                'weekly' => ['segments' => 0], // replaced below by the per-week result
                // A closure week is not judged (Founder 2026-11-15): it stays out of both rates.
                'holiday_weekend_hours' => array_column($judgedWeeks, 'weekend_hours'),
                // Only closure weeks are waived; a week still running is simply not judged yet.
                'holiday_waived_weeks' => count(array_filter($weeklyRows, fn ($week) => $week['metrics']['closure_dates'] !== [])),
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

    /** Active full-time teachers who belong to one of the campuses (null = every campus). */
    private function fullTimeTeachers(?array $campusIds): \Illuminate\Support\Collection
    {
        return DB::table('User as u')
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
    }

    /**
     * Founder 2026-11-20: each full-time teacher's teaching hours in ONE calendar month, split by the campus the lesson
     * was at. Same teacher calendar and overlap merge as the weekly 段 (一對二／一對三 at one time count once, a student's
     * leave still counts, substitute and extra lessons count where they were taught). The weekly report cannot answer
     * this: its weeks run Monday to Sunday across the month's edges. Read-only; `format=csv` downloads it.
     */
    public function campusHours(Request $request)
    {
        $data = $request->validate([
            'month' => ['required', 'regex:/^\\d{4}-(0[1-9]|1[0-2])$/'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $campusIds = $this->resolveCampusIds($request);
        if ($campusIds instanceof \Illuminate\Http\JsonResponse) {
            return $campusIds;
        }
        $from = Carbon::parse($data['month'] . '-01', 'Asia/Taipei');
        $teachers = $this->fullTimeTeachers($campusIds);
        $ids = $teachers->pluck('id')->map(fn ($id) => (int) $id)->all();
        $calendar = $ids === [] ? [] : TeacherClassCalendar::load($from->toDateString(), $from->copy()->endOfMonth()->toDateString(), null, $ids, true);
        $hours = function (array $lessons): float {
            $rows = array_map(fn ($lesson) => (object) ['start_time' => $lesson['start'], 'end_time' => $lesson['end'] !== '' ? $lesson['end'] : date('H:i', strtotime($lesson['start'] . ' +2 hours'))], $lessons);

            return $this->scheduledCoverageHoursForDate($rows, fn () => true);
        };
        // [start, end) in minutes, with the same two-hour default as the hours above when the end is missing or not after the start.
        $span = function (array $lesson): array {
            $start = (int) $this->clockMinutes($lesson['start']);
            $end = $this->clockMinutes($lesson['end']);

            return [$start, $end !== null && $end > $start ? $end : min($start + 120, 1440)];
        };
        // A director sees which of another campus's lessons clash (time and campus, as the hours columns already show),
        // but not whose lesson it is: the student and course ids stay with the campuses the caller may read.
        $visible = $campusIds === null ? null : array_map('intval', $campusIds);
        $rows = $teachers->map(function ($teacher) use ($calendar, $hours, $span, $visible) {
            $total = 0.0;
            $byCampus = [];
            $overlaps = [];
            $byDay = [];
            $days = $calendar[(int) $teacher->id] ?? [];
            ksort($days);
            foreach ($days as $date => $lessons) {
                // Lessons of two campuses that share clock time: each campus column counts them, the total counts them once.
                $hit = [];
                foreach ($lessons as $i => $a) {
                    foreach ($lessons as $j => $b) {
                        if ($j > $i && $a['campus_id'] !== $b['campus_id'] && $span($a)[0] < $span($b)[1] && $span($b)[0] < $span($a)[1]) {
                            $hit[$i] = $a;
                            $hit[$j] = $b;
                        }
                    }
                }
                if ($hit !== []) {
                    usort($hit, fn ($x, $y) => [$x['start'], $x['campus_id'], $x['student_id']] <=> [$y['start'], $y['campus_id'], $y['student_id']]);
                    $overlaps[] = ['date' => (string) $date, 'lessons' => array_map(fn ($lesson) => $visible === null || in_array($lesson['campus_id'], $visible, true)
                        ? $lesson : ['student_id' => null, 'course_id' => null] + $lesson, $hit)];
                }
                $dayTotal = $hours($lessons);
                $total += $dayTotal;
                $dayCampus = [];
                foreach (collect($lessons)->groupBy('campus_id') as $campusId => $atCampus) {
                    $campusHours = $hours($atCampus->all());
                    $dayCampus[(int) $campusId] = (int) round($campusHours * 60);
                    $byCampus[(int) $campusId] = ($byCampus[(int) $campusId] ?? 0.0) + $campusHours;
                }
                ksort($dayCampus);
                // Whole minutes, so the days add up to the month exactly (hours rounded per day would drift).
                $byDay[] = ['date' => (string) $date, 'minutes' => (int) round($dayTotal * 60), 'minutes_by_campus' => $dayCampus];
            }
            ksort($byCampus);

            // total_hours merges the whole day, so two campuses' lessons that overlap on the clock count once there.
            return ['teacher_id' => (int) $teacher->id, 'teacher_name' => (string) $teacher->Name, 'total_hours' => round($total, 2),
                'by_campus' => array_map(fn ($value) => round($value, 2), $byCampus), 'overlaps' => $overlaps, 'by_day' => $byDay];
        })->values()->all();
        $campuses = DB::table('Campus')->whereIn('id', collect($rows)->flatMap(fn ($row) => array_keys($row['by_campus']))->unique()->all())->orderBy('id')->pluck('name', 'id')->all();
        if ($request->input('format') !== 'csv') {
            return response()->json(['month' => $data['month'], 'campuses' => $campuses, 'teachers' => $rows]);
        }

        return response()->streamDownload(function () use ($rows, $campuses) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
            // Names are typed by people: a cell starting with = + - @ tab or CR gets a leading ' so a spreadsheet never runs it.
            $text = fn ($value) => preg_match('/^[=+\-@\t\r]/', (string) $value) ? "'" . $value : $value;
            fputcsv($out, array_merge(['老師姓名'], array_map($text, array_values($campuses)), ['合計時數']));
            foreach ($rows as $row) {
                fputcsv($out, array_merge([$text($row['teacher_name'])], array_map(fn ($id) => $row['by_campus'][$id] ?? 0, array_keys($campuses)), [$row['total_hours']]));
            }
            fclose($out);
        }, "正職老師各分校時數_{$data['month']}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
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
     * The one campus that settles each teacher for the month.
     * - A campus that has already locked the month with the teacher in it keeps the teacher, whatever changed since:
     *   that month is settled there, and moving it would pay it twice.
     * - Only approved campus links count; a link nobody approved must not decide where money is paid. A teacher
     *   with no approved link at all is placed by all of their links, so nobody is left listed in two campuses.
     * - A campus that locked the month without the teacher can no longer take the teacher, so the choice is among the
     *   campuses still open (if every one is locked, the answer simply stays put until one is reopened).
     * - Among those: the most lesson time in the weeks in view; a tie or no lessons goes to the lowest campus id, so
     *   every campus reaches the same answer.
     *
     * @param  array<int, array<string, list<array{start:string,end:string,campus_id:int}>>>  $lessonsByTeacher
     * @return array<int, int> teacher id => campus id
     */
    private function settlementCampusByTeacher(array $teacherIds, array $lessonsByTeacher, string $month): array
    {
        $campusOf = [];
        $lockedCampuses = [];
        if (FulltimePayrollLockStore::tablesReady()) {
            $lockedCampuses = DB::table('fulltime_payroll_runs')->where('month', $month)->where('status', 'locked')
                ->pluck('branch_id')->map(fn ($id) => (int) $id)->all();
            $campusOf = DB::table('fulltime_payroll_run_teachers as t')
                ->join('fulltime_payroll_runs as r', 'r.id', '=', 't.run_id')
                ->where('r.month', $month)->where('r.status', 'locked')->whereIn('t.teacher_id', $teacherIds)
                ->orderBy('r.id')->pluck('r.branch_id', 't.teacher_id')->map(fn ($id) => (int) $id)->all();
        }
        // Approved links decide (a legacy link with no approval value counts as approved, as everywhere else). A
        // teacher with none approved still has to land in exactly one campus (the list shows a teacher wherever any
        // link exists), so all of that teacher's links are used instead. A switched-off campus cannot be opened to
        // lock or export, so it is never a candidate.
        $links = ['approved' => [], 'any' => []];
        $linkRows = DB::table('UserCampus')->whereIn('UserID', $teacherIds)
            ->when(Schema::hasColumn('Campus', 'active'), fn ($query) => $query->whereIn('CampusID', DB::table('Campus')->where('active', true)->select('id')))
            ->orderBy('CampusID')->get(['UserID', 'CampusID', 'Approved']);
        foreach ($linkRows as $row) {
            $links['any'][(int) $row->UserID][(int) $row->CampusID] = 0;
            if (\App\Models\UserCampus::isApproved($row->Approved)) $links['approved'][(int) $row->UserID][(int) $row->CampusID] = 0;
        }
        $memberOf = $links['approved'] + $links['any'];

        foreach ($memberOf as $teacherId => $minutes) {
            if (isset($campusOf[$teacherId])) continue;
            $open = array_diff_key($minutes, array_flip($lockedCampuses));
            if ($open !== []) $minutes = $open;
            foreach ($lessonsByTeacher[$teacherId] ?? [] as $lessons) {
                // Clock time, like the 段: three students in one two-hour slot are two hours, not six.
                $rowsByCampus = [];
                foreach ($lessons as $lesson) {
                    if (!isset($minutes[$lesson['campus_id']])) continue;
                    $rowsByCampus[$lesson['campus_id']][] = (object) ['start_time' => $lesson['start'], 'end_time' => $lesson['end'] !== '' ? $lesson['end'] : date('H:i', strtotime($lesson['start'] . ' +2 hours'))];
                }
                foreach ($rowsByCampus as $campusId => $rows) {
                    $minutes[$campusId] += (int) round(60 * $this->scheduledCoverageHoursForDate($rows, fn () => true));
                }
            }
            // Campuses are in id order and only a strictly larger total takes over: ties stay with the lowest id.
            $campusOf[$teacherId] = (int) array_keys($minutes, max($minutes))[0];
        }

        return $campusOf;
    }

    /**
     * How many records a campus entered for each of these teachers are still waiting (not approved, verified or
     * withdrawn) in the period.
     *
     * @return array<int, int> teacher id => count
     */
    private function unfinishedInputsByTeacher(array $teacherIds, int $branchId, Carbon $start, Carbon $end): array
    {
        $counts = [];
        if ($teacherIds === []) {
            return $counts;
        }
        foreach (['teacher_payroll_achievements', 'teacher_payroll_deductions', 'teacher_payroll_admin_allowances', 'teacher_payroll_cash_adjustments'] as $table) {
            if (!Schema::hasTable($table)) continue;
            $rows = DB::table($table)->whereIn('teacher_id', $teacherIds)->where('branch_id', $branchId)->where('status', 'pending')
                ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $start->toDateString()))
                ->where(fn ($query) => $query->whereNull('starts_on')->orWhereDate('starts_on', '<=', $end->toDateString()))
                ->groupBy('teacher_id')->selectRaw('teacher_id, COUNT(*) as n')->pluck('n', 'teacher_id');
            foreach ($rows as $teacherId => $n) {
                $counts[(int) $teacherId] = ($counts[(int) $teacherId] ?? 0) + (int) $n;
            }
        }

        return $counts;
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
        // A week belongs to its Monday's month, so the month's last week can run into the next month. Until that
        // week is over its 段 are not final, and locking earlier would freeze a week as short.
        $monthStart = Carbon::parse($data['month'] . '-01', 'Asia/Taipei')->startOfDay();
        $monthWeeks = $this->splitIntoWeeks($monthStart, $monthStart->copy()->endOfMonth());
        if ($monthWeeks !== [] && end($monthWeeks)[1]->toDateString() >= Carbon::now('Asia/Taipei')->toDateString()) {
            return response()->json(['message' => '這個月最後一週到 ' . end($monthWeeks)[1]->toDateString() . ' 才結束，結束後才能鎖定。'], 422);
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
        if (array_sum(array_column($payload['settled_elsewhere'] ?? [], 'pending_inputs')) > 0) {
            return response()->json(['message' => '這個分校替由其他分校結算的老師補登的資料還沒核准完，請先核准或撤回再鎖定。'], 422);
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
    private function evaluateWeek(Carbon $start, Carbon $end, array $lessonsByDate, array $closureDates = [], array $uncountedByDate = [], ?string $today = null, array $nearClosures = []): array
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
        // A week still running is shown but not judged yet: its lessons can still change, and for the month's last
        // week the following month's lessons may not exist in the system yet.
        $finished = $today === null || $end->toDateString() < $today;
        if (!$finished && $closed === []) {
            $policy['reason'] = '這一週還沒結束，結束後才判定。';
        }
        // What the calendar leaves out, so a short week can be explained: lessons called off, and lessons moved to
        // another time (those count where they moved to).
        $uncounted = ['cancelled' => 0, 'moved' => 0];
        foreach ($uncountedByDate as $date => $counts) {
            if ($date < $start->toDateString() || $date > $end->toDateString()) continue;
            $uncounted['cancelled'] += (int) ($counts['cancelled'] ?? 0);
            $uncounted['moved'] += (int) ($counts['moved'] ?? 0);
        }
        $policy['metrics'] = [
            'closure_dates' => $closed,
            'finished' => $finished,
            'segment_threshold' => $policy['metrics']['segment_threshold'] ?? 16,
            'uncounted' => $uncounted,
            // Days that look like a closure but are not treated as one (a few lessons still ran), so a week that was
            // not waived can be seen for what it is.
            'near_closures' => array_values(array_filter($nearClosures, fn ($day) => $day['date'] >= $start->toDateString() && $day['date'] <= $end->toDateString())),
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

    /**
     * Lessons of a teacher's own courses that the payroll calendar leaves out, per date: called off (cancelled or
     * voided) and moved (a student's leave with a makeup arranged; that one counts where it moved to). Only used to
     * explain a short week; the statuses are the ones TeacherClassCalendar excludes for payroll.
     *
     * @return array<int, array<string, array{cancelled:int, moved:int}>> teacher id => Y-m-d => counts
     */
    private function uncountedLessonsByTeacher(array $teacherIds, string $from, string $to): array
    {
        if ($teacherIds === [] || !Schema::hasTable('ClassSession') || !Schema::hasTable('StudentClass')) {
            return [];
        }
        $out = [];
        $rows = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->whereIn('sc.TeacherID', $teacherIds)
            ->whereBetween('cs.SessionDate', [$from, $to])
            ->whereIn('cs.Status', ['cancelled', 'voided', 'leave_adjusted'])
            ->get(['sc.TeacherID as teacher_id', 'cs.SessionDate as session_date', 'cs.Status as status']);
        foreach ($rows as $row) {
            $date = substr((string) $row->session_date, 0, 10);
            $kind = $row->status === 'leave_adjusted' ? 'moved' : 'cancelled';
            $out[(int) $row->teacher_id][$date] ??= ['cancelled' => 0, 'moved' => 0];
            $out[(int) $row->teacher_id][$date][$kind]++;
        }

        return $out;
    }

    /**
     * Days on which most of the company's lessons were called off but not all of them, so the closure rule (every
     * lesson called off) does not apply. Only for explaining a week; nothing is decided from it.
     * ponytail: reads the period's lessons a second time (the closure rule reads them too); share one read if this page gets slow.
     *
     * @return list<array{date:string, total:int, open:int}>
     */
    private function nearClosureDays(Carbon $start, Carbon $end): array
    {
        if (!Schema::hasTable('ClassSession')) {
            return [];
        }
        $totals = [];
        foreach (DB::table('ClassSession')->whereBetween('SessionDate', [$start->toDateString(), $end->toDateString()])->get(['SessionDate', 'Status']) as $row) {
            $date = substr((string) $row->SessionDate, 0, 10);
            $totals[$date] ??= ['date' => $date, 'total' => 0, 'open' => 0];
            $totals[$date]['total']++;
            if ($this->isOpenTeachingSessionStatus($row->Status)) $totals[$date]['open']++;
        }
        $minimum = (int) config('teacher_salary.closure_min_sessions', 10);
        ksort($totals);

        return array_values(array_filter($totals, fn ($day) => $day['total'] >= $minimum && $day['open'] > 0 && $day['open'] * 5 <= $day['total']));
    }

    private function aggregateWeeklyStatus(array $weeklyRows): array
    {
        $qualifying = collect($weeklyRows)->where('status', TeacherEligibilityPolicy::QUALIFIES);
        $allPass = $weeklyRows !== [] && $qualifying->count() === count($weeklyRows);
        $unfinished = collect($weeklyRows)->filter(fn ($row) => !($row['metrics']['finished'] ?? true))->count();
        // HQ 115.07 + Founder 2026-11-15: the 16段課獎金 is monthly and needs every week of the month full;
        // one short week removes it. A week belongs to its Monday's month.
        $amount = (int) config('teacher_salary.weekly_segment_month_bonus', 4000) * collect($weeklyRows)
            ->groupBy(fn ($row) => substr((string) $row['metrics']['week_start'], 0, 7))
            ->filter(fn ($month) => $month->every(fn ($row) => $row['status'] === TeacherEligibilityPolicy::QUALIFIES))
            ->count();

        return [
            'status' => $allPass ? TeacherEligibilityPolicy::QUALIFIES : TeacherEligibilityPolicy::NOT_QUALIFIES,
            'reason' => $weeklyRows === [] ? '查詢期間沒有從週一開始的完整週。' : ($allPass ? '查詢期間每週均排滿16段。' : ($unfinished > 0 ? "還有 {$unfinished} 週沒結束，結束後才判定。" : '查詢期間有未排滿16段的週。')),
            'metrics' => [
                'weeks' => $weeklyRows,
                'qualifying_weeks' => $qualifying->count(),
                'week_count' => count($weeklyRows),
                'unfinished_weeks' => $unfinished,
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
            // An end time that is not after the start (a typing slip such as 20:00-00:00) is no end time: the lesson
            // gets the two-hour default, as an interval so it still merges with the day's other lessons. Taking the
            // gap between the two clock times instead counted such a lesson as up to 22 hours (seen in 2026-10).
            // ponytail: the default is two hours for every course; use the course's own lesson length if it matters.
            if ($startMinutes !== null) {
                $intervals[] = [$startMinutes, min($startMinutes + 120, 1440)];
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
