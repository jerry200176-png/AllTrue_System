<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\LearningRecord;
use App\Models\PayrollAuditLog;
use App\Models\PayrollBranchRule;
use App\Models\PayrollMonthStatus;
use App\Models\PayrollTeacherBranchRule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceController extends Controller
{
    /**
     * Start time tolerance (minutes) for concurrency/group-class detection.
     *
     * Two sessions by the same teacher on the same day are treated as a
     * simultaneous group class (and therefore enter the v1.4 tie-break)
     * only when their StartTime values are within this window.
     *
     * Without this guard, contracted-duration overlap alone (e.g. 09:30+120min
     * overlapping 10:00+120min) would be misclassified as concurrent and the
     * non-primary record would lose its base pay for the overlapping slice,
     * underpaying teachers who run back-to-back staggered sessions.
     *
     * See AI_REGRESSION_LESSONS §2026-04-18, PayrollConcurrencyTest
     * (test_staggered_* cases) and the PRD 薪資計算與調課按鈕修正.
     */
    private const CONCURRENCY_START_TOLERANCE_MINUTES = 15;

    /**
     * GET /api/v1/finance/summary
     * Revenue and expense summary for a branch.
     */
    public function summary(Request $request)
    {
        $campusIds      = $this->getCampusIds($request);
        $branchFiltered = !empty($campusIds);
        $studentIds     = $branchFiltered ? Student::whereIn('CampusID', $campusIds)->pluck('id')->all() : [];

        if ($branchFiltered && empty($studentIds)) {
            return response()->json([
                'total_courses' => 0, 'paid_courses' => 0,
                'unpaid_courses' => 0, 'approved_lessons' => 0,
            ]);
        }

        $classQuery = StudentClass::query();
        if (!empty($studentIds)) {
            $classQuery->whereIn('StudentID', $studentIds);
        }

        $totalClasses = $classQuery->count();
        $paidClasses = (clone $classQuery)->where('Paid', 1)->count();
        $unpaidClasses = $totalClasses - $paidClasses;

        $classIds = (clone $classQuery)->pluck('ID')->all();

        $approvedRecords = empty($classIds) ? 0 : LearningRecord::active()
            ->whereIn('StudentClassID', $classIds)
            ->where('Status', 'approved')
            ->count();

        return response()->json([
            'total_courses'   => $totalClasses,
            'paid_courses'    => $paidClasses,
            'unpaid_courses'  => $unpaidClasses,
            'approved_lessons' => $approvedRecords,
        ]);
    }

    /**
     * GET /api/v1/finance/revenue
     */
    public function revenue(Request $request)
    {
        $campusIds      = $this->getCampusIds($request);
        $branchFiltered = !empty($campusIds);
        $studentIds     = $branchFiltered ? Student::whereIn('CampusID', $campusIds)->pluck('id')->all() : [];

        if ($branchFiltered && empty($studentIds)) {
            return response()->json([
                'total_sessions_sold' => 0,
                'total_sessions_remaining' => 0,
                'total_sessions_used' => 0,
            ]);
        }

        $query = StudentClass::query();
        if (!empty($studentIds)) {
            $query->whereIn('StudentID', $studentIds);
        }

        $classes = $query->select('SubjectID', 'SessionCount', 'RemainingSessions', 'Paid')->get();

        $revenue = [
            'total_sessions_sold'     => $classes->sum('SessionCount'),
            'total_sessions_remaining' => $classes->sum('RemainingSessions'),
            'total_sessions_used'     => $classes->sum('SessionCount') - $classes->sum('RemainingSessions'),
        ];

        return response()->json($revenue);
    }

    /**
     * GET /api/v1/finance/outstanding
     * Students with unpaid courses or low remaining sessions.
     */
    public function outstanding(Request $request)
    {
        $campusIds      = $this->getCampusIds($request);
        $branchFiltered = !empty($campusIds);
        $studentIds     = $branchFiltered ? Student::whereIn('CampusID', $campusIds)->pluck('id')->all() : [];

        if ($branchFiltered && empty($studentIds)) {
            return response()->json([]);
        }

        $query = StudentClass::where(function ($q) {
            $q->where('Paid', 0)
              ->orWhere('RemainingSessions', '<=', 2);
        })->where('Stop', 0);

        if (!empty($studentIds)) {
            $query->whereIn('StudentID', $studentIds);
        }

        $classes = $query->with('student')->get()->map(function ($c) {
            return [
                'student_id'         => $c->StudentID,
                'student_name'       => $c->student->name ?? 'Unknown',
                'class_id'           => $c->ID,
                'subject'            => $c->Subject,
                'remaining_sessions' => (int) ($c->RemainingSessions ?? 0),
                'paid'               => (bool) $c->Paid,
            ];
        });

        return response()->json($classes);
    }

    /**
     * GET /api/v1/finance/teacher-payroll
     * Summary of approved teaching sessions per teacher.
     */
    public function teacherPayroll(Request $request)
    {
        $campusIds      = $this->getCampusIds($request);
        $branchFiltered = !empty($campusIds);
        $studentIds     = $branchFiltered ? Student::whereIn('CampusID', $campusIds)->pluck('id')->all() : [];
        $classIds       = !empty($studentIds) ? StudentClass::whereIn('StudentID', $studentIds)->pluck('ID')->all() : [];

        if ($branchFiltered && empty($classIds)) {
            return response()->json([]);
        }

        $query = LearningRecord::active()->where('Status', 'approved');
        if (!empty($classIds)) {
            $query->whereIn('StudentClassID', $classIds);
        }

        $records = $query->select('TeacherID', DB::raw('COUNT(*) as session_count'))
            ->groupBy('TeacherID')
            ->get();

        $teacherNames = DB::table('Teacher')->pluck('T_Name', 'id')->toArray()
            + DB::table('User')->whereIn('type', ['T', 'D'])->pluck('Name', 'id')->toArray();

        $payroll = $records->map(function ($r) use ($teacherNames) {
            return [
                'teacher_id'    => $r->TeacherID,
                'teacher_name'  => $teacherNames[$r->TeacherID] ?? 'Unknown',
                'session_count' => $r->session_count,
            ];
        });

        return response()->json($payroll);
    }

    /**
     * GET /api/v1/finance/subject-units
     * Weighted subject-unit statistics per teacher for a given month.
     *
     * Query params: start (YYYY-MM-DD), end (YYYY-MM-DD), branch_id (optional)
     *
     * Weights: one_on_one × 1.5, one_on_two × 0.75, one_on_three × 0.5, tutoring × 0.5
     * 科目數 = weighted_total ÷ 8
     */
    public function subjectUnits(Request $request)
    {
        $campusIds  = $this->getCampusIds($request);
        $branchFiltered = !empty($campusIds); // whether a branch scope is active

        $studentIds = $branchFiltered ? Student::whereIn('CampusID', $campusIds)->pluck('id')->all() : [];
        $classIds   = !empty($studentIds)   ? StudentClass::whereIn('StudentID', $studentIds)->pluck('ID')->all() : [];

        // If a branch is specified but has no classes, return empty — never leak other branches' data
        if ($branchFiltered && empty($classIds)) {
            $role = $request->attributes->get('auth_role');
            $empty = [
                'teachers' => [],
                'total_teachers' => 0,
            ];
            if ($role !== 'teacher') {
                $empty['totals'] = [
                    'one_on_one_hours' => 0, 'one_on_two_hours' => 0,
                    'one_on_three_hours' => 0, 'tutoring_hours' => 0,
                    'total_hours' => 0,
                    'weighted_with_tutoring' => 0, 'weighted_without_tutoring' => 0,
                    'subject_count_with' => 0, 'subject_count_without' => 0,
                ];
            }
            return response()->json($empty);
        }

        $query = LearningRecord::active()->where('Status', 'approved')
            ->with('studentClass');

        if (!empty($classIds)) {
            $query->whereIn('StudentClassID', $classIds);
        }
        if ($request->filled('start')) {
            $query->where('SessionDate', '>=', $request->input('start'));
        }
        if ($request->filled('end')) {
            $query->where('SessionDate', '<=', $request->input('end'));
        }
        $records = $query->get();

        // Teacher name lookup (User table + Teacher table)
        $userNames    = DB::table('User')->pluck('Name', 'id')->toArray();
        $teacherNames = DB::table('Teacher')->pluck('T_Name', 'id')->toArray();

        $includeLevel = $request->boolean('include_level', false);
        $gradeIdLevelMap = [
            1 => 'elementary', 2 => 'elementary', 3 => 'elementary',
            4 => 'elementary', 5 => 'elementary', 6 => 'elementary',
            7 => 'junior', 8 => 'junior', 9 => 'junior',
            10 => 'high', 11 => 'high', 12 => 'high',
        ];
        $levelLabels = ['elementary' => '國小', 'junior' => '國中', 'high' => '高中'];

        $buckets = []; // tid => [ one_on_one, one_on_two, one_on_three, tutoring ]
        $levelBuckets = []; // tid => level => [one_on_one, one_on_two, one_on_three, tutoring]

        // --- Non-tutoring: from approved LearningRecords (existing logic) ---
        foreach ($records as $r) {
            $tid = $r->TeacherID;
            $classType = $r->studentClass->ClassType ?? 'one_on_one';
            if ($classType === 'trial' || $classType === 'tutoring') {
                continue;
            }
            if (!isset($buckets[$tid])) {
                $buckets[$tid] = ['one_on_one' => 0, 'one_on_two' => 0, 'one_on_three' => 0, 'tutoring' => 0];
            }
            $hours     = $this->calcHours($r->StartTime, $r->EndTime, $r->studentClass, $r->SessionDate ? substr((string) $r->SessionDate, 0, 10) : null);
            $key       = in_array($classType, ['one_on_one', 'one_on_two', 'one_on_three'])
                         ? $classType : 'one_on_one';
            $buckets[$tid][$key] += $hours;

            if ($includeLevel) {
                $gradeId = (int) ($r->studentClass->GradeID ?? 0);
                $level = $gradeIdLevelMap[$gradeId] ?? 'unknown';
                if (!isset($levelBuckets[$tid])) {
                    $levelBuckets[$tid] = [];
                }
                if (!isset($levelBuckets[$tid][$level])) {
                    $levelBuckets[$tid][$level] = ['one_on_one' => 0, 'one_on_two' => 0, 'one_on_three' => 0, 'tutoring' => 0];
                }
                $levelBuckets[$tid][$level][$key] += $hours;
            }
        }

        // --- Tutoring: from ClassSession attended/completed (不依賴 approved LR) ---
        $tutoringClassIds = StudentClass::where('ClassType', 'tutoring');
        if (!empty($classIds)) {
            $tutoringClassIds->whereIn('ID', $classIds);
        }
        $tutoringClasses = $tutoringClassIds->get()->keyBy('ID');

        if ($tutoringClasses->isNotEmpty()) {
            $tutoringSessionQuery = ClassSession::whereIn('StudentClassID', $tutoringClasses->keys())
                ->whereIn('Status', ['attended', 'completed']);
            if ($request->filled('start')) {
                $tutoringSessionQuery->where('SessionDate', '>=', $request->input('start'));
            }
            if ($request->filled('end')) {
                $tutoringSessionQuery->where('SessionDate', '<=', $request->input('end'));
            }

            foreach ($tutoringSessionQuery->get() as $cs) {
                $sc  = $tutoringClasses->get($cs->StudentClassID);
                if (!$sc) continue;
                $tid = (int) ($sc->TeacherID ?? 0);
                if (!isset($buckets[$tid])) {
                    $buckets[$tid] = ['one_on_one' => 0, 'one_on_two' => 0, 'one_on_three' => 0, 'tutoring' => 0];
                }
                $hours = $this->calcHours($cs->StartTime, $cs->EndTime, $sc, $cs->SessionDate ? substr((string) $cs->SessionDate, 0, 10) : null);
                $buckets[$tid]['tutoring'] += $hours;

                if ($includeLevel) {
                    $gradeId = (int) ($sc->GradeID ?? 0);
                    $level = $gradeIdLevelMap[$gradeId] ?? 'unknown';
                    if (!isset($levelBuckets[$tid])) {
                        $levelBuckets[$tid] = [];
                    }
                    if (!isset($levelBuckets[$tid][$level])) {
                        $levelBuckets[$tid][$level] = ['one_on_one' => 0, 'one_on_two' => 0, 'one_on_three' => 0, 'tutoring' => 0];
                    }
                    $levelBuckets[$tid][$level]['tutoring'] += $hours;
                }
            }
        }

        $grandTotals = ['one_on_one' => 0, 'one_on_two' => 0, 'one_on_three' => 0, 'tutoring' => 0, 'total' => 0];
        $grandWeightedWith    = 0;
        $grandWeightedWithout = 0;

        $result = [];
        foreach ($buckets as $tid => $b) {
            $o1 = round($b['one_on_one'],   2);
            $o2 = round($b['one_on_two'],   2);
            $o3 = round($b['one_on_three'], 2);
            $tt = round($b['tutoring'],     2);
            $total = $o1 + $o2 + $o3 + $tt;

            $wWith    = $o1 * 1.5 + $o2 * 0.75 + $o3 * 0.5 + $tt * 0.5;
            $wWithout = $o1 * 1.5 + $o2 * 0.75 + $o3 * 0.5;

            $grandTotals['one_on_one']   += $o1;
            $grandTotals['one_on_two']   += $o2;
            $grandTotals['one_on_three'] += $o3;
            $grandTotals['tutoring']     += $tt;
            $grandTotals['total']        += $total;
            $grandWeightedWith           += $wWith;
            $grandWeightedWithout        += $wWithout;

            $result[] = [
                'teacher_id'             => $tid,
                'teacher_name'           => $userNames[$tid] ?? ($teacherNames[$tid] ?? 'Unknown'),
                'one_on_one_hours'       => $o1,
                'one_on_two_hours'       => $o2,
                'one_on_three_hours'     => $o3,
                'tutoring_hours'         => $tt,
                'total_hours'            => round($total, 2),
                'subject_count_with'     => number_format($wWith    / 8, 2),
                'subject_count_without'  => number_format($wWithout / 8, 2),
                // Temp: share_pct uses same basis as subject_count_with (weighted ÷ 8).
                '_weighted_with'         => $wWith,
                'share_pct'              => 0, // filled below
            ];
        }

        // Fill share_pct = this teacher's weighted hours / month total weighted (same ratio as 科目數含輔導)
        foreach ($result as &$t) {
            $w = (float) ($t['_weighted_with'] ?? 0);
            unset($t['_weighted_with']);
            $t['share_pct'] = $grandWeightedWith > 0
                ? round(($w / $grandWeightedWith) * 100, 1)
                : 0;
        }
        unset($t);

        usort($result, fn($a, $b) => $b['total_hours'] <=> $a['total_hours']);

        // FR-008: assign rank (1-based) after sorting.
        foreach ($result as $idx => &$row) {
            $row['rank'] = $idx + 1;
        }
        unset($row);

        $totalTeachers = count($result);

        $response = [
            'teachers' => $result,
            'total_teachers' => $totalTeachers,
            'totals'   => [
                'one_on_one_hours'           => round($grandTotals['one_on_one'],   2),
                'one_on_two_hours'           => round($grandTotals['one_on_two'],   2),
                'one_on_three_hours'         => round($grandTotals['one_on_three'], 2),
                'tutoring_hours'             => round($grandTotals['tutoring'],     2),
                'total_hours'                => round($grandTotals['total'],        2),
                'weighted_with_tutoring'     => round($grandWeightedWith,           2),
                'weighted_without_tutoring'  => round($grandWeightedWithout,        2),
                'subject_count_with'         => round($grandWeightedWith    / 8,    2),
                'subject_count_without'      => round($grandWeightedWithout / 8,    2),
            ],
        ];

        if ($includeLevel) {
            $levelBreakdownByTeacher = [];
            $levelTotals = [];
            foreach ($levelBuckets as $tid => $levels) {
                foreach ($levels as $level => $bkt) {
                    $o1 = round($bkt['one_on_one'], 2);
                    $o2 = round($bkt['one_on_two'], 2);
                    $o3 = round($bkt['one_on_three'], 2);
                    $tt = round($bkt['tutoring'], 2);
                    $total = $o1 + $o2 + $o3 + $tt;
                    $wWith = $o1 * 1.5 + $o2 * 0.75 + $o3 * 0.5 + $tt * 0.5;
                    $wWithout = $o1 * 1.5 + $o2 * 0.75 + $o3 * 0.5;

                    $entry = [
                        'level' => $level,
                        'level_label' => $levelLabels[$level] ?? $level,
                        'one_on_one_hours' => $o1,
                        'one_on_two_hours' => $o2,
                        'one_on_three_hours' => $o3,
                        'tutoring_hours' => $tt,
                        'total_hours' => round($total, 2),
                        'subject_count_with' => round($wWith / 8, 2),
                        'subject_count_without' => round($wWithout / 8, 2),
                    ];
                    $levelBreakdownByTeacher[$tid][] = $entry;

                    if (!isset($levelTotals[$level])) {
                        $levelTotals[$level] = ['one_on_one' => 0, 'one_on_two' => 0, 'one_on_three' => 0, 'tutoring' => 0, 'total' => 0, 'w_with' => 0, 'w_without' => 0];
                    }
                    $levelTotals[$level]['one_on_one'] += $o1;
                    $levelTotals[$level]['one_on_two'] += $o2;
                    $levelTotals[$level]['one_on_three'] += $o3;
                    $levelTotals[$level]['tutoring'] += $tt;
                    $levelTotals[$level]['total'] += $total;
                    $levelTotals[$level]['w_with'] += $wWith;
                    $levelTotals[$level]['w_without'] += $wWithout;
                }
            }

            foreach ($response['teachers'] as &$teacher) {
                $teacher['level_breakdown'] = $levelBreakdownByTeacher[$teacher['teacher_id']] ?? [];
            }
            unset($teacher);

            $formattedLevelTotals = [];
            foreach ($levelTotals as $level => $lt) {
                $formattedLevelTotals[] = [
                    'level' => $level,
                    'level_label' => $levelLabels[$level] ?? $level,
                    'one_on_one_hours' => round($lt['one_on_one'], 2),
                    'one_on_two_hours' => round($lt['one_on_two'], 2),
                    'one_on_three_hours' => round($lt['one_on_three'], 2),
                    'tutoring_hours' => round($lt['tutoring'], 2),
                    'total_hours' => round($lt['total'], 2),
                    'subject_count_with' => round($lt['w_with'] / 8, 2),
                    'subject_count_without' => round($lt['w_without'] / 8, 2),
                ];
            }
            $response['level_breakdown_totals'] = $formattedLevelTotals;
        }

        // FR-008: for teacher role, redact other teachers' numeric columns and drop
        // branch-wide totals. Rank & name remain visible so teachers can see their
        // relative position without viewing colleagues' absolute numbers.
        $role = $request->attributes->get('auth_role');
        if ($role === 'teacher') {
            $authTeacherId = (int) ($request->attributes->get('auth_teacher_id') ?? 0);
            // Defensive: if auth_teacher_id could not be resolved, every row (including self)
            // would be redacted. Log a warning so this is detectable in production logs.
            if ($authTeacherId === 0) {
                \Illuminate\Support\Facades\Log::warning(
                    '[FinanceController::subjectUnits] role=teacher but auth_teacher_id resolved to 0; ' .
                    'all teacher rows will be redacted. Check AttachAuthUser middleware.'
                );
            }
            $redactedFields = [
                'one_on_one_hours',
                'one_on_two_hours',
                'one_on_three_hours',
                'tutoring_hours',
                'total_hours',
                'subject_count_with',
                'subject_count_without',
                'share_pct',
            ];
            $response['teachers'] = array_map(function ($t) use ($authTeacherId, $redactedFields) {
                $isSelf = $authTeacherId > 0 && (int) ($t['teacher_id'] ?? 0) === $authTeacherId;
                $t['is_self'] = $isSelf;
                if (!$isSelf) {
                    foreach ($redactedFields as $f) {
                        if (array_key_exists($f, $t)) {
                            $t[$f] = null;
                        }
                    }
                    if (array_key_exists('level_breakdown', $t)) {
                        $t['level_breakdown'] = [];
                    }
                }
                return $t;
            }, $response['teachers']);
            unset($response['totals'], $response['level_breakdown_totals']);
        }

        return response()->json($response);
    }

    /**
     * Contracted session duration in minutes.
     * Priority: per-weekday contract > SessionDuration default > null (unknown).
     * Returns null when no contract data is available (caller should then fall back to actual times).
     */
    private function contractedDurationMinutes($studentClass, ?string $sessionDate): ?int
    {
        if (!$studentClass) {
            return null;
        }
        if ($sessionDate) {
            try {
                $isoWeekday = \Carbon\Carbon::parse($sessionDate)->isoWeekday();
                $dur = (int) $studentClass->resolveSessionDurationForWeekday($isoWeekday);
                if ($dur >= 30) {
                    return $dur;
                }
            } catch (\Exception $ignored) {}
        }
        $dur = (int) ($studentClass->SessionDuration ?? 0);
        return $dur >= 30 ? $dur : null;
    }

    /**
     * Calculate teaching hours for payroll.
     * FR-005: Contracted duration takes priority over actual start/end times so that director's
     * annotation adjustments (e.g. 20:00 → 19:30) do not affect teacher pay.
     * Priority: per-weekday contracted duration > SessionDuration > actual times > 2h default.
     */
    private function calcHours(?string $startTime, ?string $endTime, $studentClass = null, ?string $sessionDate = null): float
    {
        // FR-005: contracted duration takes precedence — see AI_REGRESSION_LESSONS §2026-04-17.
        $contracted = $this->contractedDurationMinutes($studentClass, $sessionDate);
        if ($contracted !== null) {
            return $contracted / 60.0;
        }

        // Fallback: actual recorded start/end times
        if ($startTime && $endTime) {
            foreach (['H:i:s', 'H:i'] as $fmt) {
                $subLen = $fmt === 'H:i:s' ? 8 : 5;
                try {
                    $s = \Carbon\Carbon::createFromFormat($fmt, substr($startTime, 0, $subLen));
                    $e = \Carbon\Carbon::createFromFormat($fmt, substr($endTime,   0, $subLen));
                    if ($e > $s) {
                        return $e->diffInMinutes($s) / 60.0;
                    }
                } catch (\Exception $ignored) {}
            }
        }
        return 2.0; // last-resort default
    }

    /**
     * GET /api/v1/finance/branch-monthly-tuition
     * Per-student, per-course monthly tuition report for a branch.
     *
     * Query params: branch_id (required), year, month, page, per_page
     */
    public function branchMonthlyTuition(Request $request)
    {
        $campusIds      = $this->getCampusIds($request);
        $branchFiltered = !empty($campusIds);
        $studentIds     = $branchFiltered ? Student::whereIn('CampusID', $campusIds)->pluck('id')->all() : [];

        if ($branchFiltered && empty($studentIds)) {
            return response()->json([
                'data'    => [],
                'summary' => ['total_students' => 0, 'total_sessions' => 0, 'total_tuition' => 0],
                'meta'    => ['current_page' => 1, 'last_page' => 1, 'per_page' => 50, 'total' => 0],
            ]);
        }

        $year  = (int) $request->input('year', date('Y'));
        $month = (int) $request->input('month', date('n'));
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate   = date('Y-m-t', strtotime($startDate));

        $attendedStatuses = ['attended', 'completed', 'late'];

        $sessionCounts = DB::table('ClassSession')
            ->whereBetween('SessionDate', [$startDate, $endDate])
            ->whereIn('Status', $attendedStatuses)
            ->select('StudentClassID', DB::raw('COUNT(*) as cnt'))
            ->groupBy('StudentClassID');

        // FR-002: Remove Stop=0 snapshot filter. INNER JOIN sc_counts already ensures only courses
        // with attended sessions in the selected month appear. Filtering by current Stop flag would
        // exclude stopped courses that had sessions in the queried historical month — a semantic
        // error of "filtering historical facts by current state". See AI_REGRESSION_LESSONS §2026-04-17.
        $query = StudentClass::query()
            ->joinSub($sessionCounts, 'sc_counts', function ($join) {
                $join->on('StudentClass.ID', '=', 'sc_counts.StudentClassID');
            })
            ->with(['student', 'teacher']);

        if (!empty($studentIds)) {
            $query->whereIn('StudentClass.StudentID', $studentIds);
        }

        $query->select('StudentClass.*', 'sc_counts.cnt as monthly_session_count');

        $allRows = (clone $query)->get();

        // ── Monthly billing packages: aggregate members into a single row ──
        // Fetch active monthly billing packages for this branch, always show even with 0 sessions.
        $monthlyPkgQuery = CoursePackage::query()
            ->where('billing_mode', CoursePackage::BILLING_MODE_MONTHLY)
            ->where('stop', false);
        if (!empty($studentIds)) {
            $monthlyPkgQuery->whereIn('student_id', $studentIds);
        }
        $monthlyPkgs = $monthlyPkgQuery->with('student')->get()->keyBy('id');

        // IDs of StudentClass records that are monthly-package members
        $monthlyMemberIds = collect();
        if ($monthlyPkgs->isNotEmpty()) {
            $monthlyMemberIds = StudentClass::whereIn('PackageID', $monthlyPkgs->keys())
                ->pluck('ID');
        }

        // Session counts per StudentClass for monthly package members in this month
        $memberSessionCounts = [];
        if ($monthlyMemberIds->isNotEmpty()) {
            $memberSessionCounts = DB::table('ClassSession')
                ->whereBetween('SessionDate', [$startDate, $endDate])
                ->whereIn('Status', $attendedStatuses)
                ->whereIn('StudentClassID', $monthlyMemberIds)
                ->select('StudentClassID', DB::raw('COUNT(*) as cnt'))
                ->groupBy('StudentClassID')
                ->pluck('cnt', 'StudentClassID')
                ->map(fn ($v) => (int) $v)
                ->toArray();
        }

        // Build package-level session totals (sum across all members per package)
        $pkgSessionTotals = [];
        if ($monthlyPkgs->isNotEmpty()) {
            $membersByPkg = StudentClass::whereIn('PackageID', $monthlyPkgs->keys())
                ->select('ID', 'PackageID')
                ->get()
                ->groupBy('PackageID');
            foreach ($monthlyPkgs as $pkgId => $pkg) {
                $members = $membersByPkg->get($pkgId, collect());
                $total = $members->sum(fn ($m) => $memberSessionCounts[(int) $m->ID] ?? 0);
                $pkgSessionTotals[$pkgId] = $total;
            }
        }

        // Exclude monthly-package members from regular course rows
        $regularRows = $allRows->filter(
            fn ($c) => !$monthlyMemberIds->contains($c->ID)
        );

        $uniqueStudents = $regularRows->pluck('StudentID')
            ->merge($monthlyPkgs->pluck('student_id'))
            ->unique()->count();
        $totalSessions  = $regularRows->sum('monthly_session_count')
            + array_sum($pkgSessionTotals);

        $totalTuition = $regularRows->sum(function ($c) {
            $rate = $this->resolveRate($c);
            return $c->monthly_session_count * $rate;
        }) + $monthlyPkgs->sum(function ($pkg) use ($pkgSessionTotals) {
            $sessions = $pkgSessionTotals[$pkg->id] ?? 0;
            return $sessions * (float) $pkg->rate;
        });

        $perPage = min((int) $request->input('per_page', 50), 200);
        $page    = max((int) $request->input('page', 1), 1);
        $total   = $regularRows->count() + $monthlyPkgs->count();
        $lastPage = max(1, (int) ceil($total / $perPage));

        $paged = $regularRows->sortBy([
            fn ($a, $b) => strcmp($a->student->name ?? '', $b->student->name ?? ''),
            fn ($a, $b) => strcmp($a->displaySubjectName(), $b->displaySubjectName()),
        ])->slice(($page - 1) * $perPage, $perPage)->values();

        $pagedClassIds = $paged->pluck('ID')->map(fn ($id) => (int) $id)->all();
        $paidAtMap = AlertController::lastPaidAtByStudentClassIds($pagedClassIds);

        $data = $paged->map(function ($c) use ($paidAtMap) {
            $rate = $this->resolveRate($c);
            $sessions = (int) $c->monthly_session_count;
            return [
                'row_type'           => 'course',
                'student_class_id'   => (int) $c->ID,
                'student_id'         => (int) $c->StudentID,
                'student_name'       => $c->student->name ?? 'Unknown',
                'subject'            => $c->displaySubjectName(),
                'teacher_name'       => $c->teacher->Name ?? 'Unknown',
                'class_type'         => $c->ClassType ?? 'one_on_one',
                'schedule_mode'      => $c->ScheduleMode ?? 'count',
                'rate'               => round($rate, 2),
                'monthly_sessions'   => $sessions,
                'monthly_tuition'    => round($sessions * $rate, 2),
                'remaining_sessions' => max(0, (int) ($c->RemainingSessions ?? 0)),
                'paid'               => (bool) $c->Paid,
                'paid_at'            => $c->PayDate ? substr($c->PayDate, 0, 10) : null,
                'last_paid_at'       => ($c->PayDate ? substr($c->PayDate, 0, 10) : null) ?? ($paidAtMap[(int) $c->ID] ?? null),
                'is_stopped'         => (bool) $c->Stop,
            ];
        })->values()->all();

        // Append monthly billing package rows
        $pkgRows = $monthlyPkgs->map(function ($pkg) use ($pkgSessionTotals) {
            $sessions = $pkgSessionTotals[$pkg->id] ?? 0;
            $rate     = (float) $pkg->rate;
            return [
                'row_type'           => 'package',
                'package_id'         => $pkg->id,
                'student_id'         => (int) $pkg->student_id,
                'student_name'       => $pkg->student->name ?? 'Unknown',
                'package_name'       => $pkg->name,
                'billing_mode'       => CoursePackage::BILLING_MODE_MONTHLY,
                'settlement_day'     => $pkg->settlement_day !== null ? (int) $pkg->settlement_day : null,
                'rate'               => round($rate, 2),
                'monthly_sessions'   => $sessions,
                'monthly_tuition'    => round($sessions * $rate, 2),
                'paid'               => (bool) $pkg->paid,
                'paid_at'            => $pkg->paid_at ? substr($pkg->paid_at, 0, 10) : null,
                'is_stopped'         => (bool) $pkg->stop,
            ];
        })->values()->all();

        return response()->json([
            'data'    => array_merge(array_values($data), $pkgRows),
            'summary' => [
                'total_students'  => $uniqueStudents,
                'total_sessions'  => $totalSessions,
                'total_tuition'   => round($totalTuition, 2),
            ],
            'meta' => [
                'current_page' => $page,
                'last_page'    => $lastPage,
                'per_page'     => $perPage,
                'total'        => $total,
            ],
        ]);
    }

    /**
     * GET /api/v1/finance/branch-monthly-tuition/export
     * Export monthly tuition as UTF-8 CSV.
     */
    public function branchMonthlyTuitionExport(Request $request): StreamedResponse
    {
        $campusIds      = $this->getCampusIds($request);
        $branchFiltered = !empty($campusIds);
        $studentIds     = $branchFiltered ? Student::whereIn('CampusID', $campusIds)->pluck('id')->all() : [];

        $year  = (int) $request->input('year', date('Y'));
        $month = (int) $request->input('month', date('n'));
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate   = date('Y-m-t', strtotime($startDate));

        $attendedStatuses = ['attended', 'completed', 'late'];

        $sessionCounts = DB::table('ClassSession')
            ->whereBetween('SessionDate', [$startDate, $endDate])
            ->whereIn('Status', $attendedStatuses)
            ->select('StudentClassID', DB::raw('COUNT(*) as cnt'))
            ->groupBy('StudentClassID');

        // FR-002: Remove Stop=0 snapshot filter — same rationale as branchMonthlyTuition.
        $query = StudentClass::query()
            ->joinSub($sessionCounts, 'sc_counts', function ($join) {
                $join->on('StudentClass.ID', '=', 'sc_counts.StudentClassID');
            })
            ->with(['student', 'teacher']);

        if (!empty($studentIds)) {
            $query->whereIn('StudentClass.StudentID', $studentIds);
        }

        $query->select('StudentClass.*', 'sc_counts.cnt as monthly_session_count');

        $allRows = $query->get()->sortBy([
            fn ($a, $b) => strcmp($a->student->name ?? '', $b->student->name ?? ''),
            fn ($a, $b) => strcmp($a->displaySubjectName(), $b->displaySubjectName()),
        ])->values();

        $branchIds = $campusIds ?: [];
        $branchName = !empty($branchIds)
            ? (DB::table('Campus')->where('id', $branchIds[0])->value('name') ?? 'all')
            : 'all';
        $filename = "當月學收_{$branchName}_{$year}{$month}.csv";

        return response()->streamDownload(function () use ($allRows) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
            // FR-003: Added 課程狀態 column to distinguish active vs stopped courses.
            fputcsv($out, ['學生', '科目', '老師', '班型', '月堂數', '費率', '月學收', '繳費日期', '課程狀態']);
            foreach ($allRows as $c) {
                $rate = $this->resolveRate($c);
                $sessions = (int) $c->monthly_session_count;
                $classTypeLabels = [
                    'one_on_one' => '一對一', 'one_on_two' => '一對二',
                    'one_on_three' => '一對三', 'tutoring' => '輔導', 'trial' => '試聽',
                ];
                fputcsv($out, [
                    $c->student->name ?? 'Unknown',
                    $c->displaySubjectName(),
                    $c->teacher->Name ?? 'Unknown',
                    $classTypeLabels[$c->ClassType ?? ''] ?? ($c->ClassType ?? ''),
                    $sessions,
                    $rate,
                    round($sessions * $rate, 2),
                    $c->PayDate ? substr($c->PayDate, 0, 10) : '',
                    $c->Stop ? '已結課' : '進行中',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function resolveRate(StudentClass $c): float
    {
        $rate = (float) ($c->Rate ?? 0);
        if ($rate > 0) {
            return $rate;
        }
        $charge = (float) ($c->Charge ?? 0);
        $count  = (int) ($c->SessionCount ?? 0);
        if ($charge > 0 && $count > 0) {
            return $charge / $count;
        }
        return 0;
    }

    // ── Parttime Payroll ────────────────────────────────────────────

    /**
     * GET /api/v1/finance/parttime-payroll
     * Summary + per-teacher salary for part-time teachers in a given month.
     */
    public function parttimePayroll(Request $request)
    {
        if (!config('payroll.enabled', true)) {
            return response()->json(['error' => 'Payroll feature is disabled'], 503);
        }

        $month = $request->input('month');
        if (!$month || !preg_match('/^\d{4}-\d{2}$/', $month)) {
            return response()->json(['error' => 'month parameter required (YYYY-MM)'], 422);
        }

        $campusIds = $this->getCampusIds($request);
        $branchId  = $request->filled('branch_id') ? (int) $request->input('branch_id') : ($campusIds[0] ?? null);

        $lockRow  = $branchId ? PayrollMonthStatus::where('branch_id', $branchId)->where('month', $month)->first() : null;
        $ruleCtx  = $this->resolvePayrollRule($branchId, $lockRow);

        $rows = $this->buildParttimePayrollData($month, $campusIds, $ruleCtx);

        if ($request->filled('search')) {
            $q = mb_strtolower($request->input('search'));
            $rows = array_filter($rows, fn ($r) => str_contains(mb_strtolower($r['teacher_name']), $q));
            $rows = array_values($rows);
        }

        $sort = $request->input('sort', 'salary_desc');
        usort($rows, match ($sort) {
            'salary_asc'  => fn ($a, $b) => $a['total_salary'] <=> $b['total_salary'],
            'hours_desc'  => fn ($a, $b) => $b['total_hours'] <=> $a['total_hours'],
            'name_asc'    => fn ($a, $b) => strcmp($a['teacher_name'], $b['teacher_name']),
            default       => fn ($a, $b) => $b['total_salary'] <=> $a['total_salary'],
        });

        $totalSalary = array_sum(array_column($rows, 'total_salary'));
        $totalHours  = round(array_sum(array_column($rows, 'total_hours')), 2);
        $teacherCount = count($rows);

        $branchName = $branchId ? (DB::table('Campus')->where('id', $branchId)->value('name') ?? '') : '';

        return response()->json([
            'summary' => [
                'total_salary'    => $totalSalary,
                'total_hours'     => $totalHours,
                'teacher_count'   => $teacherCount,
                'avg_hourly_rate' => $totalHours > 0 ? round($totalSalary / $totalHours) : 0,
                'month'           => $month,
                'branch_id'       => $branchId,
                'branch_name'     => $branchName,
                'lock_status'     => $lockRow->status ?? 'draft',
                'locked_at'       => $lockRow->locked_at ?? null,
                'locked_by'       => $lockRow->locked_by ?? null,
                'rule_version_id' => $ruleCtx['rule_id'] ?? null,
            ],
            'teachers' => $rows,
        ]);
    }

    /**
     * GET /api/v1/finance/parttime-payroll/{teacherId}/sessions
     */
    public function parttimePayrollSessions(Request $request, int $teacherId)
    {
        if (!config('payroll.enabled', true)) {
            return response()->json(['error' => 'Payroll feature is disabled'], 503);
        }

        $month = $request->input('month');
        if (!$month || !preg_match('/^\d{4}-\d{2}$/', $month)) {
            return response()->json(['error' => 'month parameter required (YYYY-MM)'], 422);
        }

        $campusIds  = $this->getCampusIds($request);
        $startDate  = $month . '-01';
        $endDate    = date('Y-m-t', strtotime($startDate));
        $maxPerPage = config('payroll.max_per_page', 200);
        $perPage    = min((int) $request->input('per_page', 50), $maxPerPage);
        $page       = max((int) $request->input('page', 1), 1);

        $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : ($campusIds[0] ?? null);
        $lockRow  = $branchId ? PayrollMonthStatus::where('branch_id', $branchId)->where('month', $month)->first() : null;
        $ruleCtx  = $this->resolvePayrollRule($branchId, $lockRow);

        $query = $this->parttimeBaseQuery($campusIds, $startDate, $endDate)
            ->where('LearningRecord.TeacherID', $teacherId);

        $total    = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));

        $allTeacherRecords = (clone $this->parttimeBaseQuery($campusIds, $startDate, $endDate))
            ->where('LearningRecord.TeacherID', $teacherId)
            ->get();
        $rateMap  = $this->buildRateMap($allTeacherRecords, $ruleCtx);
        $bonusMap = $this->buildConcurrencyBonusMap($allTeacherRecords, $rateMap, $ruleCtx);

        $records = $query->orderBy('LearningRecord.SessionDate')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $teacherName = DB::table('User')->where('id', $teacherId)->value('Name')
            ?? DB::table('Teacher')->where('id', $teacherId)->value('T_Name')
            ?? 'Unknown';

        $sessions   = [];
        $sumSalary  = 0;
        $sumHours   = 0.0;

        foreach ($records as $r) {
            $row = $this->buildSessionRow($r, $ruleCtx, $bonusMap);
            $sessions[] = $row;
            $sumSalary += $row['session_salary'];
            $sumHours  += $row['hours'];
        }

        if ($total > $perPage) {
            $fullRows = $this->buildParttimePayrollData($month, $campusIds, $ruleCtx);
            $teacherRow = collect($fullRows)->firstWhere('teacher_id', $teacherId);
            $sumSalary = $teacherRow['total_salary'] ?? $sumSalary;
            $sumHours  = $teacherRow['total_hours'] ?? $sumHours;
        }

        return response()->json([
            'teacher' => [
                'teacher_id'    => $teacherId,
                'teacher_name'  => $teacherName,
                'total_salary'  => $sumSalary,
                'total_hours'   => round($sumHours, 2),
                'session_count' => $total,
            ],
            'sessions' => $sessions,
            'meta' => [
                'current_page' => $page,
                'last_page'    => $lastPage,
                'per_page'     => $perPage,
                'total'        => $total,
            ],
        ]);
    }

    /**
     * GET /api/v1/finance/parttime-payroll/export
     */
    public function parttimePayrollExport(Request $request): StreamedResponse
    {
        if (!config('payroll.enabled', true)) {
            abort(503, 'Payroll feature is disabled');
        }

        $month = $request->input('month');
        if (!$month || !preg_match('/^\d{4}-\d{2}$/', $month)) {
            abort(422, 'month parameter required (YYYY-MM)');
        }

        $campusIds = $this->getCampusIds($request);
        $branchId  = $request->filled('branch_id') ? (int) $request->input('branch_id') : ($campusIds[0] ?? null);
        $branchName = $branchId ? (DB::table('Campus')->where('id', $branchId)->value('name') ?? 'all') : 'all';

        $startDate = $month . '-01';
        $endDate   = date('Y-m-t', strtotime($startDate));

        $lockRow   = $branchId ? PayrollMonthStatus::where('branch_id', $branchId)->where('month', $month)->first() : null;
        $ruleCtx   = $this->resolvePayrollRule($branchId, $lockRow);

        $totalRows = $this->parttimeBaseQuery($campusIds, $startDate, $endDate)->count();
        $maxExport = config('payroll.max_export_rows', 5000);
        if ($totalRows > $maxExport) {
            abort(422, "Too many rows ({$totalRows}). Max export is {$maxExport}. Please narrow your scope.");
        }

        $teacherRows = $this->buildParttimePayrollData($month, $campusIds, $ruleCtx);

        $allRecords = $this->parttimeBaseQuery($campusIds, $startDate, $endDate)->get();
        $rateMap  = $this->buildRateMap($allRecords, $ruleCtx);
        $bonusMap = $this->buildConcurrencyBonusMap($allRecords, $rateMap, $ruleCtx);

        $filename = "兼職薪資_{$branchName}_{$month}.csv";

        return response()->streamDownload(function () use ($teacherRows, $campusIds, $startDate, $endDate, $ruleCtx, $bonusMap) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($out, ['老師姓名', '總時數', '高中時數', '國中時數', '國小時數', '輔導時數', '應付薪資', '堂次數', '費率來源']);
            foreach ($teacherRows as $t) {
                fputcsv($out, [
                    $t['teacher_name'], $t['total_hours'], $t['high_hours'],
                    $t['junior_hours'], $t['elementary_hours'], $t['tutoring_hours'],
                    $t['total_salary'], $t['session_count'],
                    ($t['rule_source'] ?? 'branch_default') === 'teacher_override' ? '個別費率' : '分校預設',
                ]);
            }

            fputcsv($out, []);
            fputcsv($out, ['日期', '老師', '學生', '科目', '學段', '課型', '時數', '基礎時薪', '人數加成', '併堂加給', '實際時薪', '堂次薪資', '費率來源']);

            $this->parttimeBaseQuery($campusIds, $startDate, $endDate)
                ->orderBy('LearningRecord.id')
                ->chunk(200, function ($records) use ($out, $ruleCtx, $bonusMap) {
                    foreach ($records as $r) {
                        $row = $this->buildSessionRow($r, $ruleCtx, $bonusMap);
                        fputcsv($out, [
                            $row['session_date'], $row['teacher_name'] ?? '', $row['student_name'],
                            $row['subject'], $row['level_label'], $row['class_type'],
                            $row['hours'], $row['base_rate'], $row['headcount_bonus'],
                            $row['concurrency_bonus_amount'], $row['effective_rate'], $row['session_salary'],
                            ($row['rule_source'] ?? 'branch_default') === 'teacher_override' ? '個別費率' : '分校預設',
                        ]);
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * POST /api/v1/finance/parttime-payroll/lock
     */
    public function parttimePayrollLock(Request $request)
    {
        $month    = $request->input('month');
        $branchId = (int) $request->input('branch_id');
        if (!$month || !$branchId) {
            return response()->json(['error' => 'month and branch_id required'], 422);
        }

        $row = PayrollMonthStatus::firstOrCreate(
            ['branch_id' => $branchId, 'month' => $month],
            ['status' => 'draft']
        );

        if ($row->status === 'locked') {
            return response()->json(['error' => 'Already locked'], 422);
        }

        $authUser = $request->attributes->get('auth_user');
        $userId   = $authUser ? (int) $authUser->id : null;

        DB::transaction(function () use ($row, $branchId, $month, $userId) {
            $currentRule = PayrollBranchRule::latestForBranch($branchId);
            $teacherMap  = PayrollTeacherBranchRule::latestMapForBranch($branchId);

            $teacherSnapshots = [];
            foreach ($teacherMap as $tid => $rule) {
                $teacherSnapshots[(string) $tid] = $rule->id;
            }

            $row->update([
                'status'                 => 'locked',
                'locked_by'              => $userId,
                'locked_at'              => now(),
                'rule_version_id'        => $currentRule?->id,
                'teacher_rule_snapshots' => !empty($teacherSnapshots) ? $teacherSnapshots : null,
            ]);

            PayrollAuditLog::create([
                'branch_id'  => $branchId,
                'month'      => $month,
                'action'     => 'lock',
                'user_id'    => $userId,
                'created_at' => now(),
            ]);
        });

        return response()->json(['ok' => true, 'status' => 'locked']);
    }

    /**
     * POST /api/v1/finance/parttime-payroll/reopen (super_admin only)
     */
    public function parttimePayrollReopen(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        if ($role !== 'super_admin') {
            return response()->json(['error' => 'Only super_admin can reopen'], 403);
        }

        $month    = $request->input('month');
        $branchId = (int) $request->input('branch_id');
        $reason   = $request->input('reason', '');
        if (!$month || !$branchId) {
            return response()->json(['error' => 'month and branch_id required'], 422);
        }

        $row = PayrollMonthStatus::where('branch_id', $branchId)->where('month', $month)->first();
        if (!$row || $row->status !== 'locked') {
            return response()->json(['error' => 'Not locked'], 422);
        }

        $authUser = $request->attributes->get('auth_user');
        $userId   = $authUser ? (int) $authUser->id : null;
        $row->update(['status' => 'draft', 'locked_by' => null, 'locked_at' => null, 'rule_version_id' => null, 'teacher_rule_snapshots' => null]);

        PayrollAuditLog::create([
            'branch_id'  => $branchId,
            'month'      => $month,
            'action'     => 'reopen',
            'user_id'    => $userId,
            'reason'     => $reason,
            'created_at' => now(),
        ]);

        return response()->json(['ok' => true, 'status' => 'draft']);
    }

    // ── Parttime Payroll helpers ─────────────────────────────────────

    private function parttimeBaseQuery(array $campusIds, string $startDate, string $endDate)
    {
        $branchFiltered = !empty($campusIds);
        $studentIds = $branchFiltered ? Student::whereIn('CampusID', $campusIds)->pluck('id')->all() : [];
        $classIds   = !empty($studentIds) ? StudentClass::whereIn('StudentID', $studentIds)->pluck('ID')->all() : [];

        $partTimeTeacherIds = DB::table('User')
            ->where('employment_type', 'part_time')
            ->whereIn('type', ['T', 'D'])
            ->pluck('id')->all();

        $query = LearningRecord::query()
            ->select('LearningRecord.*')
            ->active()
            ->where('LearningRecord.Status', 'approved')
            ->whereBetween('LearningRecord.SessionDate', [$startDate, $endDate])
            ->whereIn('LearningRecord.TeacherID', $partTimeTeacherIds)
            ->with('studentClass');

        if ($branchFiltered && empty($classIds)) {
            $query->whereRaw('1=0');
        } elseif (!empty($classIds)) {
            $query->whereIn('LearningRecord.StudentClassID', $classIds);
        }

        $query->whereHas('studentClass', function ($q) {
            $q->where(function ($w) {
                $w->where('ClassType', '!=', 'trial')->orWhereNull('ClassType');
            });
        });

        return $query;
    }

    private function buildParttimePayrollData(string $month, array $campusIds, array $ruleCtx = []): array
    {
        $startDate = $month . '-01';
        $endDate   = date('Y-m-t', strtotime($startDate));

        $records = $this->parttimeBaseQuery($campusIds, $startDate, $endDate)->get();
        $rateMap  = $this->buildRateMap($records, $ruleCtx);
        $bonusMap = $this->buildConcurrencyBonusMap($records, $rateMap, $ruleCtx);

        $userNames    = DB::table('User')->pluck('Name', 'id')->toArray();
        $teacherNames = DB::table('Teacher')->pluck('T_Name', 'id')->toArray();

        $teacherOverrides = $ruleCtx['teacher_overrides'] ?? [];

        $buckets = [];
        foreach ($records as $r) {
            $tid = $r->TeacherID;
            if (!isset($buckets[$tid])) {
                $hasOverride = isset($teacherOverrides[$tid]);
                $buckets[$tid] = [
                    'teacher_id'       => $tid,
                    'teacher_name'     => $userNames[$tid] ?? ($teacherNames[$tid] ?? 'Unknown'),
                    'high_hours'       => 0, 'junior_hours' => 0,
                    'elementary_hours' => 0, 'tutoring_hours' => 0,
                    'total_hours'      => 0, 'total_salary' => 0,
                    'session_count'    => 0,
                    'rule_source'      => $hasOverride ? 'teacher_override' : 'branch_default',
                ];
            }

            $row = $this->buildSessionRow($r, $ruleCtx, $bonusMap);
            $level = $row['level'];
            $hours = $row['hours'];

            $levelKey = match ($level) {
                'high'       => 'high_hours',
                'junior'     => 'junior_hours',
                'elementary' => 'elementary_hours',
                default      => 'tutoring_hours',
            };
            if ($row['class_type'] === 'tutoring') {
                $levelKey = 'tutoring_hours';
            }

            $buckets[$tid][$levelKey]      += $hours;
            $buckets[$tid]['total_hours']   += $hours;
            $buckets[$tid]['total_salary']  += $row['session_salary'];
            $buckets[$tid]['session_count'] += 1;
        }

        foreach ($buckets as &$b) {
            $b['high_hours']       = round($b['high_hours'], 2);
            $b['junior_hours']     = round($b['junior_hours'], 2);
            $b['elementary_hours'] = round($b['elementary_hours'], 2);
            $b['tutoring_hours']   = round($b['tutoring_hours'], 2);
            $b['total_hours']      = round($b['total_hours'], 2);
            $b['total_salary']     = (int) round($b['total_salary']);
        }
        unset($b);

        return array_values($buckets);
    }

    private function buildSessionRow(LearningRecord $r, array $ruleCtx = [], array $bonusMap = []): array
    {
        $sc        = $r->studentClass;
        $classType = $sc->ClassType ?? 'one_on_one';
        $gradeId   = (int) ($sc->GradeID ?? 0);
        $hours     = round($this->calcHours($r->StartTime, $r->EndTime, $sc, $r->SessionDate ? substr((string) $r->SessionDate, 0, 10) : null), 2);

        $levelMap = config('payroll.grade_level_map', []);
        $level    = $levelMap[$gradeId] ?? 'unknown';
        if ($classType === 'tutoring') {
            $level = 'tutoring';
        }
        if ($level === 'unknown') {
            $level = 'elementary';
        }

        $teacherOverrides = $ruleCtx['teacher_overrides'] ?? [];
        $tid = (int) $r->TeacherID;
        $ruleSource = 'branch_default';
        if (isset($teacherOverrides[$tid])) {
            $effectiveCtx = $teacherOverrides[$tid];
            $ruleSource = 'teacher_override';
        } else {
            $effectiveCtx = $ruleCtx;
        }

        $levelLabels = ['elementary' => '國小', 'junior' => '國中', 'high' => '高中', 'tutoring' => '輔導'];
        $baseRates   = $effectiveCtx['base_rates'] ?? config('payroll.base_rates', []);

        $baseRate = $baseRates[$level] ?? 300;
        $headcountBonus = 0;
        $effectiveRate         = $baseRate + $headcountBonus;
        $baseSalary            = (int) round($effectiveRate * $hours);
        $concurrencyBonus      = $bonusMap[$r->id] ?? 0;
        $sessionSalary         = max(0, $baseSalary + $concurrencyBonus);

        $studentName = $sc->student->name ?? 'Unknown';

        return [
            'learning_record_id'       => $r->id,
            'session_date'             => $r->SessionDate ? substr((string) $r->SessionDate, 0, 10) : '',
            'student_name'             => $studentName,
            'subject'                  => $this->normalizeSubjectLabel($r->Subject ?: $sc->displaySubjectName()),
            'level'                    => $level,
            'level_label'              => $levelLabels[$level] ?? $level,
            'class_type'               => $classType,
            'student_count'            => match ($classType) { 'one_on_two' => 2, 'one_on_three' => 3, 'tutoring' => 0, default => 1 },
            'start_time'               => $r->StartTime ? substr((string) $r->StartTime, 0, 5) : null,
            'end_time'                 => $r->EndTime ? substr((string) $r->EndTime, 0, 5) : null,
            'hours'                    => $hours,
            'base_rate'                => $baseRate,
            'headcount_bonus'          => $headcountBonus,
            'effective_rate'           => $effectiveRate,
            'concurrency_bonus_amount' => $concurrencyBonus,
            'session_salary'           => $sessionSalary,
            'teacher_name'             => DB::table('User')->where('id', $r->TeacherID)->value('Name'),
            'rule_source'              => $ruleSource,
        ];
    }

    private static function normalizeSubjectLabel(string $subject): string
    {
        static $map = [
            'English'   => '英文',
            'Chinese'   => '國文',
            'Math'      => '數學',
            'Physics'   => '物理',
            'Chemistry' => '化學',
            'Science'   => '自然',
            'Social'    => '社會',
        ];
        return $map[$subject] ?? $subject;
    }

    /**
     * Build rateMap for buildConcurrencyBonusMap: [lr_id => ['base_rate', 'level_weight']].
     */
    private function buildRateMap($records, array $ruleCtx = []): array
    {
        $gradeLevelMap = config('payroll.grade_level_map', []);
        $levelWeights  = config('payroll.level_weights', [
            'high' => 4, 'junior' => 3, 'elementary' => 2, 'tutoring' => 1,
        ]);
        $defaultRates      = config('payroll.base_rates', []);
        $teacherOverrides  = $ruleCtx['teacher_overrides'] ?? [];

        $map = [];
        foreach ($records as $r) {
            $sc        = $r->studentClass;
            $classType = $sc->ClassType ?? 'one_on_one';
            $gradeId   = (int) ($sc->GradeID ?? 0);
            $level     = $gradeLevelMap[$gradeId] ?? 'elementary';
            if ($classType === 'tutoring') $level = 'tutoring';

            $tid = (int) $r->TeacherID;
            $effectiveCtx = $teacherOverrides[$tid] ?? $ruleCtx;
            $baseRates = $effectiveCtx['base_rates'] ?? $defaultRates;

            $map[$r->id] = [
                'base_rate'    => $baseRates[$level] ?? 300,
                'level_weight' => $levelWeights[$level] ?? 1,
            ];
        }
        return $map;
    }

    /**
     * Build a map of LR id → net concurrency adjustment for all records.
     *
     * ── 2026-04-18 rewrite: time-slice model (PRD-F, plan-id TBA) ──
     * User-confirmed rule (取代先前 v1.5 CONCURRENCY_START_TOLERANCE_MINUTES=15):
     *   1. 依老師 × 日期分組，沿時間軸做 sweep-line 切割成連續段（breakpoints = 所有
     *      LR 的 start/end 分鐘）。
     *   2. 每一段內：n = 該段 active LR 數；max_base = 活躍 LR 中最高的 base_rate；
     *      segment_rate_per_hour = max_base + headcount_bonus × (n - 1)。
     *   3. segment_pay = segment_rate_per_hour × (segEnd - segStart) / 60（pro-rata，
     *      不足 30 分的尾巴按真實分鐘比例計）。
     *   4. Primary attribution：將 segment_pay 全額歸給「該段 base_rate 最高、lr_id 最
     *      小」的 LR；其他 LR 得到 0 歸屬 — UI 上顯示成「800 / 0」讓主任一眼看出是並堂。
     *   5. bonusMap[lr_id] = attributed_pay - baseline_base_salary（即現有 controller
     *      仍由 buildSessionRow 計算 base_rate × hours，再加本函式回傳的 delta）。
     *
     *   headcount_bonus 預設 50（每多一人、每小時），可由各分校主任於
     *   PayrollBranchRule 設定；個別老師 override 取自 teacher_overrides。
     *
     *   取消 CONCURRENCY_START_TOLERANCE_MINUTES：錯開 30 分鐘的兩堂現在會按真實重疊時
     *   段算並堂（主任 2026-04-18 晚間確認要改）。
     *
     * @param  \Illuminate\Support\Collection  $records
     * @param  array  $rateMap  [lr_id => ['base_rate' => int, 'level_weight' => int]]
     */
    private function buildConcurrencyBonusMap($records, array $rateMap = [], array $ruleCtx = []): array
    {
        $defaultBonus    = config('payroll.concurrency_bonus_per_student', 50);
        $gradeLevelMap   = config('payroll.grade_level_map', []);
        $defaultRates    = config('payroll.base_rates', []);
        $teacherOverrides = $ruleCtx['teacher_overrides'] ?? [];
        $branchBonus     = $ruleCtx['headcount_bonus'] ?? $defaultBonus;

        $bonusMap = [];
        $grouped = [];
        foreach ($records as $r) {
            $key = $r->TeacherID . '_' . substr((string) $r->SessionDate, 0, 10);
            $grouped[$key][] = $r;
        }

        foreach ($grouped as $dayRecords) {
            $intervals = [];
            foreach ($dayRecords as $r) {
                $startMin = $this->timeToMinutes($r->StartTime);
                if ($startMin === null) continue;

                $sessionDate = $r->SessionDate ? substr((string) $r->SessionDate, 0, 10) : null;
                $sc = $r->studentClass;
                $contracted = $this->contractedDurationMinutes($sc, $sessionDate);
                if ($contracted !== null) {
                    $endMin = $startMin + $contracted;
                } else {
                    $endMin = $this->timeToMinutes($r->EndTime);
                    if ($endMin === null || $endMin <= $startMin) continue;
                }

                if (isset($rateMap[$r->id])) {
                    $baseRate = $rateMap[$r->id]['base_rate'];
                } else {
                    $classType = $sc->ClassType ?? 'one_on_one';
                    $gradeId   = (int) ($sc->GradeID ?? 0);
                    $level     = $gradeLevelMap[$gradeId] ?? 'elementary';
                    if ($classType === 'tutoring') $level = 'tutoring';
                    $baseRate = $defaultRates[$level] ?? 300;
                }

                $tid = (int) $r->TeacherID;
                $teacherBonus = isset($teacherOverrides[$tid]['headcount_bonus'])
                    ? (int) $teacherOverrides[$tid]['headcount_bonus']
                    : (int) $branchBonus;

                $intervals[] = [
                    'lr_id'      => $r->id,
                    'start'      => $startMin,
                    'end'        => $endMin,
                    'base_rate'  => (int) $baseRate,
                    'bonus'      => $teacherBonus,
                    'hours'      => ($endMin - $startMin) / 60.0,
                ];
            }

            if (empty($intervals)) continue;

            $points = [];
            foreach ($intervals as $iv) {
                $points[] = $iv['start'];
                $points[] = $iv['end'];
            }
            $points = array_values(array_unique($points));
            sort($points);

            $attributed = [];
            foreach ($intervals as $iv) {
                $attributed[$iv['lr_id']] = 0.0;
            }

            for ($i = 0, $cnt = count($points) - 1; $i < $cnt; $i++) {
                $a = $points[$i];
                $b = $points[$i + 1];
                if ($b <= $a) continue;

                $active = array_values(array_filter(
                    $intervals,
                    fn ($iv) => $iv['start'] <= $a && $iv['end'] >= $b
                ));
                if (empty($active)) continue;

                $n       = count($active);
                $maxBase = max(array_column($active, 'base_rate'));
                $bonus   = (int) max(array_column($active, 'bonus'));

                $topIds = array_column(
                    array_filter($active, fn ($iv) => $iv['base_rate'] >= $maxBase),
                    'lr_id'
                );
                $primaryId = min($topIds);

                $ratePerHour = $maxBase + $bonus * ($n - 1);
                $segPay      = $ratePerHour * ($b - $a) / 60.0;

                $attributed[$primaryId] = ($attributed[$primaryId] ?? 0.0) + $segPay;
            }

            foreach ($intervals as $iv) {
                $baseline = $iv['base_rate'] * $iv['hours'];
                $delta    = ($attributed[$iv['lr_id']] ?? 0.0) - $baseline;
                if (abs($delta) > 0.001) {
                    $bonusMap[$iv['lr_id']] = (int) round($delta);
                }
            }
        }

        return $bonusMap;
    }

    private function timeToMinutes(?string $time): ?int
    {
        if (!$time) {
            return null;
        }
        foreach (['H:i:s', 'H:i'] as $fmt) {
            $subLen = $fmt === 'H:i:s' ? 8 : 5;
            try {
                $t = \Carbon\Carbon::createFromFormat($fmt, substr($time, 0, $subLen));
                return $t->hour * 60 + $t->minute;
            } catch (\Exception $ignored) {}
        }
        return null;
    }

    // ── Payroll Rules ─────────────────────────────────────────────

    /**
     * GET /api/v1/finance/parttime-payroll/rules
     */
    public function parttimePayrollRules(Request $request)
    {
        if (!config('payroll.enabled', true)) {
            return response()->json(['error' => 'Payroll feature is disabled'], 503);
        }

        $campusIds = $this->getCampusIds($request);
        $branchId  = $request->filled('branch_id') ? (int) $request->input('branch_id') : ($campusIds[0] ?? null);
        if (!$branchId) {
            return response()->json(['error' => 'branch_id required'], 422);
        }

        $role = $request->attributes->get('auth_role');
        if ($role !== 'super_admin' && !empty($campusIds) && !in_array($branchId, $campusIds, true)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $rule = PayrollBranchRule::latestForBranch($branchId);
        $defaults = config('payroll.base_rates', []);

        return response()->json([
            'branch_id'        => $branchId,
            'rule_version_id'  => $rule?->id,
            'base_rates'       => $rule ? $rule->base_rates : $defaults,
            'headcount_bonus'  => $rule ? $rule->headcount_bonus : config('payroll.headcount_bonus', 50),
            'created_by'       => $rule?->created_by,
            'created_at'       => $rule?->created_at?->toIso8601String(),
            'created_by_name'  => $rule?->created_by ? (DB::table('User')->where('id', $rule->created_by)->value('Name') ?? '') : null,
            'defaults'         => [
                'base_rates'      => $defaults,
                'headcount_bonus' => config('payroll.headcount_bonus', 50),
            ],
        ]);
    }

    /**
     * PUT /api/v1/finance/parttime-payroll/rules
     */
    public function parttimePayrollRulesUpdate(Request $request)
    {
        if (!config('payroll.enabled', true)) {
            return response()->json(['error' => 'Payroll feature is disabled'], 503);
        }

        $campusIds = $this->getCampusIds($request);
        $branchId  = (int) $request->input('branch_id');
        if (!$branchId) {
            return response()->json(['error' => 'branch_id required'], 422);
        }

        $role = $request->attributes->get('auth_role');
        if ($role !== 'super_admin' && !empty($campusIds) && !in_array($branchId, $campusIds, true)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $baseRates = $request->input('base_rates');
        if (!is_array($baseRates)) {
            return response()->json(['error' => 'base_rates must be an object'], 422);
        }

        $required = ['high', 'junior', 'elementary', 'tutoring'];
        foreach ($required as $key) {
            if (!isset($baseRates[$key]) || !is_numeric($baseRates[$key])) {
                return response()->json(['error' => "base_rates.{$key} must be a number"], 422);
            }
            $val = (int) $baseRates[$key];
            if ($val < 100 || $val > 2000) {
                return response()->json(['error' => "base_rates.{$key} must be between 100 and 2000"], 422);
            }
        }

        $headcountBonus = $request->input('headcount_bonus');
        if (!is_numeric($headcountBonus)) {
            return response()->json(['error' => 'headcount_bonus must be a number'], 422);
        }
        $headcountBonus = (int) $headcountBonus;
        if ($headcountBonus < 0 || $headcountBonus > 500) {
            return response()->json(['error' => 'headcount_bonus must be between 0 and 500'], 422);
        }

        $authUser = $request->attributes->get('auth_user');
        $userId   = $authUser ? (int) $authUser->id : null;

        $cleanRates = [
            'high'       => (int) $baseRates['high'],
            'junior'     => (int) $baseRates['junior'],
            'elementary' => (int) $baseRates['elementary'],
            'tutoring'   => (int) $baseRates['tutoring'],
        ];

        $newRule = PayrollBranchRule::create([
            'branch_id'       => $branchId,
            'base_rates'      => $cleanRates,
            'headcount_bonus' => $headcountBonus,
            'created_by'      => $userId,
            'created_at'      => now(),
        ]);

        PayrollAuditLog::create([
            'branch_id'  => $branchId,
            'month'      => now()->format('Y-m'),
            'action'     => 'rule_update',
            'user_id'    => $userId,
            'reason'     => json_encode(['rule_id' => $newRule->id, 'base_rates' => $cleanRates, 'headcount_bonus' => $headcountBonus]),
            'created_at' => now(),
        ]);

        return response()->json([
            'ok'              => true,
            'rule_version_id' => $newRule->id,
            'base_rates'      => $newRule->base_rates,
            'headcount_bonus' => $newRule->headcount_bonus,
        ]);
    }

    // ── Teacher-level Payroll Rules ──────────────────────────────────

    /**
     * GET /api/v1/finance/parttime-payroll/teacher-rules
     */
    public function parttimePayrollTeacherRules(Request $request)
    {
        if (!config('payroll.enabled', true)) {
            return response()->json(['error' => 'Payroll feature is disabled'], 503);
        }

        $campusIds = $this->getCampusIds($request);
        $branchId  = $request->filled('branch_id') ? (int) $request->input('branch_id') : ($campusIds[0] ?? null);
        if (!$branchId) {
            return response()->json(['error' => 'branch_id required'], 422);
        }

        $role = $request->attributes->get('auth_role');
        if ($role !== 'super_admin' && !empty($campusIds) && !in_array($branchId, $campusIds, true)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $teacherId = $request->filled('teacher_id') ? (int) $request->input('teacher_id') : null;

        if ($teacherId) {
            $rule = PayrollTeacherBranchRule::latestForTeacherBranch($teacherId, $branchId);
            $branchRule = PayrollBranchRule::latestForBranch($branchId);
            return response()->json([
                'teacher_user_id'  => $teacherId,
                'branch_id'        => $branchId,
                'has_override'     => (bool) $rule,
                'rule_version_id'  => $rule?->id,
                'base_rates'       => $rule ? $rule->base_rates : ($branchRule ? $branchRule->base_rates : config('payroll.base_rates', [])),
                'headcount_bonus'  => $rule ? $rule->headcount_bonus : ($branchRule ? $branchRule->headcount_bonus : config('payroll.headcount_bonus', 50)),
                'created_by'       => $rule?->created_by,
                'created_at'       => $rule?->created_at?->toIso8601String(),
                'branch_defaults'  => [
                    'base_rates'      => $branchRule ? $branchRule->base_rates : config('payroll.base_rates', []),
                    'headcount_bonus' => $branchRule ? $branchRule->headcount_bonus : config('payroll.headcount_bonus', 50),
                ],
            ]);
        }

        $map = PayrollTeacherBranchRule::latestMapForBranch($branchId);
        $result = [];
        foreach ($map as $tid => $rule) {
            $result[] = [
                'teacher_user_id'  => $tid,
                'branch_id'        => $branchId,
                'rule_version_id'  => $rule->id,
                'base_rates'       => $rule->base_rates,
                'headcount_bonus'  => $rule->headcount_bonus,
                'created_at'       => $rule->created_at?->toIso8601String(),
            ];
        }

        return response()->json(['teacher_rules' => $result]);
    }

    /**
     * PUT /api/v1/finance/parttime-payroll/teacher-rules
     */
    public function parttimePayrollTeacherRulesUpdate(Request $request)
    {
        if (!config('payroll.enabled', true)) {
            return response()->json(['error' => 'Payroll feature is disabled'], 503);
        }

        $campusIds = $this->getCampusIds($request);
        $branchId  = (int) $request->input('branch_id');
        $teacherId = (int) $request->input('teacher_id');
        if (!$branchId || !$teacherId) {
            return response()->json(['error' => 'branch_id and teacher_id required'], 422);
        }

        $role = $request->attributes->get('auth_role');
        if ($role !== 'super_admin' && !empty($campusIds) && !in_array($branchId, $campusIds, true)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $teacher = DB::table('User')->where('id', $teacherId)->first();
        if (!$teacher || ($teacher->employment_type ?? 'full_time') !== 'part_time') {
            return response()->json(['error' => 'Teacher must be part_time employment type'], 422);
        }

        $baseRates = $request->input('base_rates');
        if (!is_array($baseRates)) {
            return response()->json(['error' => 'base_rates must be an object'], 422);
        }
        $required = ['high', 'junior', 'elementary', 'tutoring'];
        foreach ($required as $key) {
            if (!isset($baseRates[$key]) || !is_numeric($baseRates[$key])) {
                return response()->json(['error' => "base_rates.{$key} must be a number"], 422);
            }
            $val = (int) $baseRates[$key];
            if ($val < 100 || $val > 2000) {
                return response()->json(['error' => "base_rates.{$key} must be between 100 and 2000"], 422);
            }
        }

        $headcountBonus = $request->input('headcount_bonus');
        if (!is_numeric($headcountBonus)) {
            return response()->json(['error' => 'headcount_bonus must be a number'], 422);
        }
        $headcountBonus = (int) $headcountBonus;
        if ($headcountBonus < 0 || $headcountBonus > 500) {
            return response()->json(['error' => 'headcount_bonus must be between 0 and 500'], 422);
        }

        $authUser = $request->attributes->get('auth_user');
        $userId   = $authUser ? (int) $authUser->id : null;

        $cleanRates = [
            'high'       => (int) $baseRates['high'],
            'junior'     => (int) $baseRates['junior'],
            'elementary' => (int) $baseRates['elementary'],
            'tutoring'   => (int) $baseRates['tutoring'],
        ];

        $newRule = PayrollTeacherBranchRule::create([
            'teacher_user_id' => $teacherId,
            'branch_id'       => $branchId,
            'base_rates'      => $cleanRates,
            'headcount_bonus' => $headcountBonus,
            'created_by'      => $userId,
            'created_at'      => now(),
        ]);

        PayrollAuditLog::create([
            'branch_id'  => $branchId,
            'month'      => now()->format('Y-m'),
            'action'     => 'teacher_rule_update',
            'user_id'    => $userId,
            'reason'     => json_encode(['teacher_id' => $teacherId, 'rule_id' => $newRule->id, 'base_rates' => $cleanRates, 'headcount_bonus' => $headcountBonus]),
            'created_at' => now(),
        ]);

        return response()->json([
            'ok'              => true,
            'rule_version_id' => $newRule->id,
            'base_rates'      => $newRule->base_rates,
            'headcount_bonus' => $newRule->headcount_bonus,
        ]);
    }

    /**
     * DELETE /api/v1/finance/parttime-payroll/teacher-rules
     * Revert a teacher to use the branch default by soft-deleting their override.
     * Implemented as: no physical delete — we record the revert in audit log,
     * and latestForTeacherBranch will no longer find an active rule after a
     * "sentinel" row with null base_rates. Simpler: just check if latest is sentinel.
     * Even simpler for append-only: we don't actually delete. We just need a way to
     * mark "no override". We'll use a convention: delete all rows for this teacher+branch.
     */
    public function parttimePayrollTeacherRulesDelete(Request $request)
    {
        if (!config('payroll.enabled', true)) {
            return response()->json(['error' => 'Payroll feature is disabled'], 503);
        }

        $campusIds = $this->getCampusIds($request);
        $branchId  = (int) $request->input('branch_id');
        $teacherId = (int) $request->input('teacher_id');
        if (!$branchId || !$teacherId) {
            return response()->json(['error' => 'branch_id and teacher_id required'], 422);
        }

        $role = $request->attributes->get('auth_role');
        if ($role !== 'super_admin' && !empty($campusIds) && !in_array($branchId, $campusIds, true)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $deleted = PayrollTeacherBranchRule::where('teacher_user_id', $teacherId)
            ->where('branch_id', $branchId)
            ->delete();

        $authUser = $request->attributes->get('auth_user');
        $userId   = $authUser ? (int) $authUser->id : null;

        PayrollAuditLog::create([
            'branch_id'  => $branchId,
            'month'      => now()->format('Y-m'),
            'action'     => 'teacher_rule_revert',
            'user_id'    => $userId,
            'reason'     => json_encode(['teacher_id' => $teacherId, 'deleted_count' => $deleted]),
            'created_at' => now(),
        ]);

        return response()->json(['ok' => true, 'reverted' => true]);
    }

    /**
     * Resolve rule context for payroll calculation.
     * Returns ['base_rates' => ..., 'headcount_bonus' => ..., 'rule_id' => ..., 'teacher_overrides' => [tid => [...]]].
     * Locked months use snapshot rule_version_id + teacher_rule_snapshots; draft months use latest.
     */
    private function resolvePayrollRule(?int $branchId, ?PayrollMonthStatus $lockRow): array
    {
        if (!$branchId) {
            return [];
        }

        if ($lockRow && $lockRow->status === 'locked') {
            $branchCtx = $lockRow->rule_version_id
                ? PayrollBranchRule::resolveForLockedMonth($branchId, $lockRow->rule_version_id)
                : PayrollBranchRule::resolveForBranch($branchId);

            $teacherOverrides = [];
            $snapshots = $lockRow->teacher_rule_snapshots ?? [];
            foreach ($snapshots as $tid => $ruleId) {
                $rule = PayrollTeacherBranchRule::find($ruleId);
                if ($rule) {
                    $teacherOverrides[(int) $tid] = [
                        'base_rates'      => $rule->base_rates,
                        'headcount_bonus' => $rule->headcount_bonus,
                        'rule_id'         => $rule->id,
                    ];
                }
            }
            $branchCtx['teacher_overrides'] = $teacherOverrides;
            return $branchCtx;
        }

        $branchCtx = PayrollBranchRule::resolveForBranch($branchId);
        $teacherMap = PayrollTeacherBranchRule::latestMapForBranch($branchId);
        $teacherOverrides = [];
        foreach ($teacherMap as $tid => $rule) {
            $teacherOverrides[$tid] = [
                'base_rates'      => $rule->base_rates,
                'headcount_bonus' => $rule->headcount_bonus,
                'rule_id'         => $rule->id,
            ];
        }
        $branchCtx['teacher_overrides'] = $teacherOverrides;
        return $branchCtx;
    }

    /**
     * GET /api/v1/finance/duplicate-courses?branch_id=
     * List students with multiple active (Stop=0) courses for the same subject.
     */
    public function duplicateCourses(Request $request)
    {
        $campusIds = $this->getCampusIds($request);

        $query = StudentClass::where('Stop', 0);
        if (!empty($campusIds)) {
            $studentIds = Student::whereIn('CampusID', $campusIds)->pluck('id');
            $query->whereIn('StudentID', $studentIds);
        }

        $rows = $query->get()->groupBy(function ($sc) {
            return $sc->StudentID . '|' . $sc->SubjectID;
        })->filter(function ($group) {
            return $group->count() > 1;
        });

        $results = [];
        foreach ($rows as $key => $group) {
            [$studentId, $subjectId] = explode('|', $key);
            $student = Student::find($studentId);
            $subjectName = DB::table('Subject')->where('id', $subjectId)->value('Subject_Name') ?? '';

            $courses = $group->map(function ($sc) {
                return [
                    'course_id' => $sc->ID,
                    'remaining_sessions' => (int) ($sc->RemainingSessions ?? 0),
                    'used_sessions' => (int) ($sc->UsedSessions ?? 0),
                    'session_count' => (int) ($sc->SessionCount ?? 0),
                    'class_type' => $sc->ClassType ?? 'one_on_one',
                    'teacher_id' => (int) $sc->TeacherID,
                    'paid' => (int) ($sc->Paid ?? 0),
                    'created_at' => $sc->created_at ? $sc->created_at->toDateTimeString() : null,
                ];
            })->values();

            $results[] = [
                'student_id' => (int) $studentId,
                'student_name' => $student->name ?? '',
                'subject_id' => (int) $subjectId,
                'subject_name' => $subjectName,
                'courses' => $courses,
            ];
        }

        return response()->json(['duplicates' => $results, 'count' => count($results)]);
    }

    /**
     * GET /api/v1/finance/gl-export
     * General ledger export as CSV for accountants.
     */
    public function glExport(Request $request)
    {
        $campusIds = $this->getCampusIds($request);
        $start = $request->input('start', now()->startOfMonth()->toDateString());
        $end = $request->input('end', now()->endOfMonth()->toDateString());

        $query = StudentClass::with('student')
            ->where('Stop', 0)
            ->where('Charge', '>', 0);

        if (!empty($campusIds)) {
            $query->whereHas('student', fn ($q) => $q->whereIn('CampusID', $campusIds));
        }

        if ($request->filled('start')) {
            $query->where('StartDate', '>=', $start);
        }
        if ($request->filled('end')) {
            $query->where('StartDate', '<=', $end);
        }

        $courses = $query->orderBy('StartDate')->get();

        $rows = [];
        foreach ($courses as $course) {
            $charge = (int) ($course->Charge ?? 0);
            $paid = (int) ($course->Pay ?? 0);
            $studentName = $course->student->name ?? '';
            $startDate = $course->StartDate ? substr($course->StartDate, 0, 10) : '';

            if ($charge > 0) {
                $rows[] = [
                    'date' => $startDate,
                    'account_code' => '1101',
                    'account_name' => '應收學費',
                    'debit' => $charge,
                    'credit' => 0,
                    'memo' => $studentName . ' 課程費用',
                    'student' => $studentName,
                ];
            }
            if ($paid > 0) {
                $rows[] = [
                    'date' => $course->PayDate ? substr($course->PayDate, 0, 10) : $startDate,
                    'account_code' => '4101',
                    'account_name' => '學費收入',
                    'debit' => 0,
                    'credit' => $paid,
                    'memo' => $studentName . ' 繳費',
                    'student' => $studentName,
                ];
            }
        }

        if ($request->input('format') === 'csv') {
            $callback = function () use ($rows) {
                $out = fopen('php://output', 'w');
                fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
                fputcsv($out, ['日期', '科目代碼', '科目名稱', '借方', '貸方', '摘要', '學生']);
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row['date'], $row['account_code'], $row['account_name'],
                        $row['debit'] ?: '', $row['credit'] ?: '',
                        $row['memo'], $row['student'],
                    ]);
                }
                fclose($out);
            };

            return new \Symfony\Component\HttpFoundation\StreamedResponse($callback, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="gl_export_' . $start . '_' . $end . '.csv"',
            ]);
        }

        return response()->json([
            'start' => $start,
            'end' => $end,
            'entries' => $rows,
            'totals' => [
                'debit' => array_sum(array_column($rows, 'debit')),
                'credit' => array_sum(array_column($rows, 'credit')),
            ],
        ]);
    }

    /**
     * GET /api/v1/finance/ar-aging
     * Accounts Receivable aging analysis (30/60/90+ day buckets).
     */
    public function arAging(Request $request)
    {
        $campusIds = $this->getCampusIds($request);
        $asOf = $request->filled('as_of')
            ? \Carbon\Carbon::parse($request->input('as_of'))
            : \Carbon\Carbon::today();

        $query = StudentClass::with('student')
            ->where('Stop', 0)
            ->whereRaw('CAST(Charge AS SIGNED) > CAST(COALESCE(Pay, 0) AS SIGNED)');

        if (!empty($campusIds)) {
            $query->whereHas('student', fn ($q) => $q->whereIn('CampusID', $campusIds));
        }

        $courses = $query->get();

        $students = [];
        foreach ($courses as $course) {
            $studentId = (int) $course->StudentID;
            $charge = (int) ($course->Charge ?? 0);
            $paid = (int) ($course->Pay ?? 0);
            $outstanding = max(0, $charge - $paid);
            if ($outstanding <= 0) {
                continue;
            }

            $startDate = $course->StartDate
                ? \Carbon\Carbon::parse($course->StartDate)
                : $asOf;
            $daysOverdue = max(0, $startDate->diffInDays($asOf));

            if (!isset($students[$studentId])) {
                $students[$studentId] = [
                    'student_id' => $studentId,
                    'name' => (string) ($course->student->name ?? ''),
                    'current' => 0,
                    'thirty' => 0,
                    'sixty' => 0,
                    'ninety_plus' => 0,
                    'total' => 0,
                ];
            }

            if ($daysOverdue < 30) {
                $students[$studentId]['current'] += $outstanding;
            } elseif ($daysOverdue < 60) {
                $students[$studentId]['thirty'] += $outstanding;
            } elseif ($daysOverdue < 90) {
                $students[$studentId]['sixty'] += $outstanding;
            } else {
                $students[$studentId]['ninety_plus'] += $outstanding;
            }
            $students[$studentId]['total'] += $outstanding;
        }

        $rows = array_values($students);
        usort($rows, fn ($a, $b) => $b['total'] - $a['total']);

        $totals = [
            'current' => array_sum(array_column($rows, 'current')),
            'thirty' => array_sum(array_column($rows, 'thirty')),
            'sixty' => array_sum(array_column($rows, 'sixty')),
            'ninety_plus' => array_sum(array_column($rows, 'ninety_plus')),
            'grand_total' => array_sum(array_column($rows, 'total')),
        ];

        return response()->json([
            'as_of' => $asOf->toDateString(),
            'buckets' => ['current', '30', '60', '90+'],
            'students' => $rows,
            'totals' => $totals,
        ]);
    }

    /**
     * GET /api/v1/finance/consolidated-summary
     * Multi-branch revenue consolidation (super_admin only).
     */
    public function consolidatedSummary(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        $year = (int) $request->input('year', now()->year);
        $month = (int) $request->input('month', now()->month);

        $startOfMonth = \Carbon\Carbon::create($year, $month, 1)->startOfMonth();
        $endOfMonth = $startOfMonth->copy()->endOfMonth();

        $campuses = \App\Models\Campus::all();
        $branches = [];

        foreach ($campuses as $campus) {
            $studentIds = Student::where('CampusID', $campus->id)->pluck('id')->all();
            if (empty($studentIds)) {
                $branches[] = [
                    'branch_id' => (int) $campus->id,
                    'name' => (string) $campus->name,
                    'receivable' => 0,
                    'collected' => 0,
                    'rate' => 0,
                    'yoy_change' => null,
                ];
                continue;
            }

            $receivable = (int) StudentClass::whereIn('StudentID', $studentIds)
                ->where('Stop', 0)
                ->sum('Charge');
            $collected = (int) StudentClass::whereIn('StudentID', $studentIds)
                ->where('Stop', 0)
                ->sum('Pay');

            $rate = $receivable > 0 ? round(($collected / $receivable) * 100, 1) : 0;

            if ($role === 'super_admin') {
                $branches[] = [
                    'branch_id' => (int) $campus->id,
                    'name' => (string) $campus->name,
                    'receivable' => $receivable,
                    'collected' => $collected,
                    'rate' => $rate,
                    'yoy_change' => null,
                ];
            }
        }

        if ($role !== 'super_admin') {
            $campusIds = $request->attributes->get('auth_campus_ids', []);
            $branches = array_values(array_filter($branches, fn ($b) => in_array($b['branch_id'], $campusIds, true)));
        }

        $totalReceivable = array_sum(array_column($branches, 'receivable'));
        $totalCollected = array_sum(array_column($branches, 'collected'));

        return response()->json([
            'year' => $year,
            'month' => $month,
            'branches' => $branches,
            'totals' => [
                'receivable' => $totalReceivable,
                'collected' => $totalCollected,
                'rate' => $totalReceivable > 0 ? round(($totalCollected / $totalReceivable) * 100, 1) : 0,
            ],
        ]);
    }

    private function getCampusIds(Request $request): array
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);

        if ($request->filled('branch_id')) {
            $bid = (int) $request->input('branch_id');
            if ($role !== 'super_admin' && !empty($campusIds) && !in_array($bid, $campusIds, true)) {
                return $campusIds;
            }
            return [$bid];
        }

        return $campusIds;
    }
}
