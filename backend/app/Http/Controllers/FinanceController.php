<?php

namespace App\Http\Controllers;

use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinanceController extends Controller
{
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

        $approvedRecords = empty($classIds) ? 0 : LearningRecord::whereIn('StudentClassID', $classIds)
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

        $query = LearningRecord::where('Status', 'approved');
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
            return response()->json([
                'teachers' => [],
                'totals'   => [
                    'one_on_one_hours' => 0, 'one_on_two_hours' => 0,
                    'one_on_three_hours' => 0, 'tutoring_hours' => 0,
                    'total_hours' => 0,
                    'weighted_with_tutoring' => 0, 'weighted_without_tutoring' => 0,
                    'subject_count_with' => 0, 'subject_count_without' => 0,
                ],
            ]);
        }

        $query = LearningRecord::where('Status', 'approved')
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

        foreach ($records as $r) {
            $tid = $r->TeacherID;
            if (!isset($buckets[$tid])) {
                $buckets[$tid] = ['one_on_one' => 0, 'one_on_two' => 0, 'one_on_three' => 0, 'tutoring' => 0];
            }
            $hours     = $this->calcHours($r->StartTime, $r->EndTime, $r->studentClass);
            $classType = $r->studentClass->ClassType ?? 'one_on_one';
            $key       = in_array($classType, ['one_on_one', 'one_on_two', 'one_on_three', 'tutoring'])
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
                'share_pct'              => 0, // filled below
            ];
        }

        // Fill share_pct
        $grandTotal = $grandTotals['total'];
        foreach ($result as &$t) {
            $t['share_pct'] = $grandTotal > 0
                ? round(($t['total_hours'] / $grandTotal) * 100, 1)
                : 0;
        }
        unset($t);

        usort($result, fn($a, $b) => $b['total_hours'] <=> $a['total_hours']);

        $response = [
            'teachers' => $result,
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

        return response()->json($response);
    }

    /**
     * Calculate teaching hours from a learning record's times (or fallback to StudentClass duration).
     */
    private function calcHours(?string $startTime, ?string $endTime, $studentClass = null): float
    {
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
        if ($studentClass && !empty($studentClass->SessionDuration) && $studentClass->SessionDuration > 0) {
            return $studentClass->SessionDuration / 60.0;
        }
        return 2.0; // default fallback: 2h per session
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
