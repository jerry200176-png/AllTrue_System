<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\LearningRecord;
use App\Models\PackageSessionLedger;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\Subject;
use App\Services\EnrollmentService;
use App\Services\FrontendSubjectIdResolver;
use App\Services\PackageDeductionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CoursePackageController extends Controller
{
    /**
     * GET /api/v1/course-packages
     * List packages for a branch (optionally filtered by student).
     */
    public function index(Request $request): JsonResponse
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);

        $query = CoursePackage::query()->with(['student', 'campus']);

        if ($request->filled('branch_id')) {
            $bid = (int) $request->input('branch_id');
            if ($role !== 'super_admin' && !empty($campusIds) && !in_array($bid, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $query->where('campus_id', $bid);
        } elseif (!empty($campusIds)) {
            $query->whereIn('campus_id', $campusIds);
        }

        if ($request->filled('student_id')) {
            $query->where('student_id', (int) $request->input('student_id'));
        }

        if ($request->filled('active_only')) {
            $query->where('stop', false)->where('enabled', true);
        }

        $packages = $query->orderByDesc('id')->get();

        $result = $packages->map(function (CoursePackage $pkg) {
            $members = StudentClass::where('PackageID', $pkg->id)
                ->select(['ID', 'SubjectID', 'TeacherID', 'ClassType', 'Stop'])
                ->get();

            $subjectNames = [];
            $teacherNames = [];
            foreach ($members as $m) {
                $subjectNames[$m->ID] = $m->displaySubjectName();
                $teacherNames[$m->ID] = DB::table('User')
                    ->where('id', (int) $m->TeacherID)
                    ->value('Name') ?? '';
            }

            return [
                'id'                 => $pkg->id,
                'student_id'         => $pkg->student_id,
                'student_name'       => $pkg->student?->name ?? '',
                'campus_id'          => $pkg->campus_id,
                'campus_name'        => $pkg->campus?->name ?? '',
                'name'               => $pkg->name,
                'total_sessions'     => $pkg->total_sessions,
                'remaining_sessions' => $pkg->remaining_sessions,
                'used_sessions'      => $pkg->used_sessions,
                'rate'               => $pkg->rate,
                'rate_unit'          => $pkg->rate_unit,
                'class_type'         => $pkg->class_type,
                'paid'               => $pkg->paid,
                'paid_at'            => $pkg->paid_at,
                'stop'               => $pkg->stop,
                'closed_reason'      => $pkg->closed_reason,
                'enabled'            => $pkg->enabled,
                'members'            => $members->map(fn ($m) => [
                    'student_class_id' => $m->ID,
                    'subject'          => $subjectNames[$m->ID] ?? '',
                    'teacher_name'     => $teacherNames[$m->ID] ?? '',
                    'class_type'       => $m->ClassType,
                    'stop'             => (bool) $m->Stop,
                ]),
                'created_at' => $pkg->created_at?->toIso8601String(),
            ];
        });

        return response()->json($result);
    }

    /**
     * GET /api/v1/course-packages/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $pkg = CoursePackage::find($id);
        if (!$pkg) {
            return response()->json(['message' => '找不到方案'], 404);
        }

        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        if ($role !== 'super_admin' && !empty($campusIds) && !in_array((int) $pkg->campus_id, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $members = StudentClass::where('PackageID', $pkg->id)->get();
        $perSubjectUsed = PackageSessionLedger::where('package_id', $pkg->id)
            ->where('delta', -1)
            ->groupBy('student_class_id')
            ->selectRaw('student_class_id, COUNT(*) as used')
            ->pluck('used', 'student_class_id');

        $memberDetails = $members->map(function ($m) use ($perSubjectUsed) {
            return [
                'student_class_id'   => $m->ID,
                'subject'            => $m->displaySubjectName(),
                'teacher_id'         => (int) $m->TeacherID,
                'teacher_name'       => DB::table('User')->where('id', (int) $m->TeacherID)->value('Name') ?? '',
                'class_type'         => $m->ClassType,
                'stop'               => (bool) $m->Stop,
                'subject_used'       => (int) ($perSubjectUsed[$m->ID] ?? 0),
            ];
        });

        return response()->json([
            'id'                 => $pkg->id,
            'student_id'         => $pkg->student_id,
            'campus_id'          => $pkg->campus_id,
            'name'               => $pkg->name,
            'total_sessions'     => $pkg->total_sessions,
            'remaining_sessions' => $pkg->remaining_sessions,
            'used_sessions'      => $pkg->used_sessions,
            'paid'               => $pkg->paid,
            'paid_at'            => $pkg->paid_at,
            'stop'               => $pkg->stop,
            'closed_reason'      => $pkg->closed_reason,
            'rate'               => $pkg->rate,
            'rate_unit'          => $pkg->rate_unit,
            'class_type'         => $pkg->class_type,
            'members'            => $memberDetails,
            'ledger_net'         => PackageSessionLedger::netDelta($pkg->id),
        ]);
    }

    /**
     * POST /api/v1/course-packages/create-multi-subject
     * Create a package and its member StudentClass rows in one transaction.
     */
    public function createMultiSubject(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id'       => 'required|integer|exists:Student,id',
            'branch_id'        => 'required|integer',
            'name'             => 'required|string|max:128',
            'payment_type'     => 'nullable|in:session,monthly',
            'total_sessions'   => 'nullable|integer|min:1|max:999',
            'settlement_day'   => 'nullable|integer|min:1|max:31',
            'rate'             => 'required|numeric|min:0',
            'rate_unit'        => 'nullable|in:session,hour',
            'class_type'       => 'nullable|in:one_on_one,one_on_two,one_on_three,tutoring,trial',
            'paid_at'          => 'nullable|date',
            'subjects'         => 'required|array|min:1|max:10',
            'subjects.*.subject_id'      => 'nullable|integer',
            'subjects.*.subject_name'    => 'nullable|string|max:64',
            'subjects.*.teacher_id'      => 'required|integer',
            'subjects.*.start_date'      => 'nullable|date',
            'subjects.*.confirmed_dates'   => 'nullable|array',
            'subjects.*.confirmed_dates.*' => 'date',
            'subjects.*.days_of_week'    => 'nullable|array',
            'subjects.*.day_time_slots'  => 'nullable|array',
            'subjects.*.start_time'      => 'nullable|date_format:H:i',
            'subjects.*.duration_hours'  => 'nullable|numeric|min:0.5|max:8',
        ]);

        $isMonthly = ($data['payment_type'] ?? 'session') === 'monthly';
        if (!$isMonthly && empty($data['total_sessions'])) {
            return response()->json(['message' => '堂數制方案必須填寫總堂數（total_sessions）'], 422);
        }
        if ($isMonthly) {
            if (count($data['subjects']) < 2) {
                return response()->json(['message' => '多科月結方案至少需要 2 個科目'], 422);
            }
            if (empty($data['rate']) || (float) $data['rate'] <= 0) {
                return response()->json(['message' => '月結方案必須設定每堂費率（rate > 0）'], 422);
            }
            if (empty($data['settlement_day'])) {
                return response()->json(['message' => '月結方案必須填寫結算日（settlement_day）'], 422);
            }
        }
        $totalSessions = $isMonthly ? 0 : (int) $data['total_sessions'];

        foreach ($data['subjects'] as $i => $spec) {
            if (empty($spec['subject_id']) && empty($spec['subject_name'])) {
                return response()->json([
                    'message' => "科目 #{$i} 須提供 subject_id 或 subject_name",
                ], 422);
            }
            if (empty($spec['subject_id']) && !empty($spec['subject_name'])) {
                $resolved = FrontendSubjectIdResolver::resolve($spec['subject_name']);
                if ($resolved === null) {
                    return response()->json([
                        'message' => "無法解析科目「{$spec['subject_name']}」",
                    ], 422);
                }
                $data['subjects'][$i]['subject_id'] = $resolved;
            }
        }

        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        $branchId = (int) $data['branch_id'];

        if ($role !== 'super_admin' && !empty($campusIds) && !in_array($branchId, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $studentCampusId = (int) (Student::where('id', (int) $data['student_id'])->value('CampusID') ?? 0);
        if ($studentCampusId > 0 && $studentCampusId !== $branchId) {
            return response()->json(['message' => '學生不屬於該分校'], 422);
        }

        return DB::transaction(function () use ($data, $branchId, $isMonthly, $totalSessions) {
            $pkg = CoursePackage::create([
                'student_id'         => (int) $data['student_id'],
                'campus_id'          => $branchId,
                'name'               => $data['name'],
                'billing_mode'       => $isMonthly ? 'date' : 'count',
                'settlement_day'     => $isMonthly ? ($data['settlement_day'] ?? null) : null,
                'total_sessions'     => $totalSessions,
                'remaining_sessions' => $totalSessions,
                'used_sessions'      => 0,
                'rate'               => (float) $data['rate'],
                'rate_unit'          => $data['rate_unit'] ?? 'session',
                'class_type'         => $data['class_type'] ?? 'one_on_one',
                'paid'               => !empty($data['paid_at']),
                'paid_at'            => $data['paid_at'] ?? null,
                'stop'               => false,
                'enabled'            => true,
            ]);

            $createdMembers = [];

            foreach ($data['subjects'] as $subjectSpec) {
                $subjectId = (int) $subjectSpec['subject_id'];
                $teacherId = (int) $subjectSpec['teacher_id'];
                $durationHours = (float) ($subjectSpec['duration_hours'] ?? 2);
                $durationMinutes = (int) round($durationHours * 60);

                $weekSlots = [];
                if (!empty($subjectSpec['days_of_week'])) {
                    $startTime = $subjectSpec['start_time'] ?? '16:00';
                    foreach ($subjectSpec['days_of_week'] as $dow) {
                        $weekSlots[] = ['weekday' => ((int) $dow + 6) % 7, 'time' => $startTime];
                    }
                }

                $startDate = !empty($subjectSpec['start_date'])
                    ? $subjectSpec['start_date']
                    : Carbon::today()->toDateString();
                $startTimeStr = $subjectSpec['start_time'] ?? '16:00';

                $sc = StudentClass::create([
                    'StudentID'         => (int) $data['student_id'],
                    'GradeID'           => 1,
                    'SubjectID'         => $subjectId,
                    'TeacherID'         => $teacherId,
                    'by1'               => 1,
                    'Period'            => 4,
                    'StartDate'         => $startDate,
                    'TotalHours'        => 0,
                    'RoomID'            => 0,
                    'ScheduleMode'      => $isMonthly ? 'date' : 'count',
                    'SessionCount'      => $totalSessions,
                    'RemainingSessions' => $totalSessions,
                    'UsedSessions'      => 0,
                    'SessionDuration'   => $durationMinutes,
                    'ClassType'         => $data['class_type'] ?? 'one_on_one',
                    'Rate'              => (float) $data['rate'],
                    'rate_unit'         => $data['rate_unit'] ?? 'session',
                    'settlement_day'    => $isMonthly ? ($data['settlement_day'] ?? null) : null,
                    'Charge'            => 0,
                    'Pay'               => 0,
                    'Paid'              => !empty($data['paid_at']) ? 1 : 0,
                    'PayDate'           => $data['paid_at'] ?? null,
                    'Stop'              => 0,
                    'PackageID'            => $pkg->id,
                    'PackageTotalSessions' => $totalSessions,
                    'PackageName'          => $data['name'],
                ]);

                $confirmedDates = $subjectSpec['confirmed_dates'] ?? [];
                foreach ($confirmedDates as $cDate) {
                    $endTime = Carbon::createFromFormat('H:i', $startTimeStr)
                        ->addMinutes($durationMinutes)
                        ->format('H:i');

                    $session = ClassSession::create([
                        'StudentClassID' => $sc->ID,
                        'SessionDate'    => $cDate,
                        'StartTime'      => $startTimeStr,
                        'EndTime'        => $endTime,
                        'Status'         => 'attended',
                    ]);

                    PackageDeductionService::deductForSession(
                        $pkg->id,
                        $sc->ID,
                        $session->id,
                        'attended',
                        null
                    );
                }

                $subjectName = DB::table('Subject')->where('id', $subjectId)->value('Subject_Name')
                    ?? DB::table('BaseData')->where('Name', '課程')->where('id', $subjectId)->value('Val')
                    ?? '課程';

                $createdMembers[] = [
                    'student_class_id' => $sc->ID,
                    'subject_id'       => $subjectId,
                    'subject_name'     => $subjectName,
                    'teacher_id'       => $teacherId,
                    'confirmed_count'  => count($confirmedDates),
                ];
            }

            $pkg->recomputeCounters();

            return response()->json([
                'message'    => '方案已建立',
                'package_id' => $pkg->id,
                'package'    => [
                    'id'                 => $pkg->id,
                    'name'               => $pkg->name,
                    'total_sessions'     => $pkg->total_sessions,
                    'remaining_sessions' => $pkg->remaining_sessions,
                    'used_sessions'      => $pkg->used_sessions,
                ],
                'members' => $createdMembers,
            ], 201);
        });
    }

    /**
     * POST /api/v1/course-packages/{id}/recompute
     * Recompute package counters from ledger (admin repair tool).
     */
    public function recompute(Request $request, int $id): JsonResponse
    {
        $role = $request->attributes->get('auth_role');
        if (!in_array($role, ['director', 'admin', 'super_admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $result = PackageDeductionService::fullRecompute($id);
        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 404);
        }

        Log::info('CoursePackage recompute', [
            'package_id' => $id,
            'by_user'    => $request->attributes->get('auth_user')?->id ?? 0,
            'result'     => $result,
        ]);

        return response()->json(array_merge(['message' => '重算完成'], $result));
    }

    /**
     * POST /api/v1/course-packages/{id}/bind-courses
     * Bind existing StudentClass rows into a package (migration tool).
     */
    public function bindCourses(Request $request, int $id): JsonResponse
    {
        $role = $request->attributes->get('auth_role');
        if (!in_array($role, ['director', 'admin', 'super_admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'student_class_ids'   => 'required|array|min:1|max:10',
            'student_class_ids.*' => 'integer',
            'dry_run'             => 'nullable|boolean',
        ]);

        $dryRun = (bool) ($data['dry_run'] ?? false);
        $pkg = CoursePackage::find($id);
        if (!$pkg) {
            return response()->json(['message' => '找不到方案'], 404);
        }

        $ids = array_map('intval', $data['student_class_ids']);
        $courses = StudentClass::whereIn('ID', $ids)->get();
        $report = [];

        foreach ($courses as $sc) {
            $currentPackage = $sc->PackageID;
            $report[] = [
                'student_class_id'    => $sc->ID,
                'subject'             => $sc->displaySubjectName(),
                'current_package_id'  => $currentPackage,
                'will_bind'           => empty($currentPackage) || (int) $currentPackage === 0,
                'current_remaining'   => (int) ($sc->RemainingSessions ?? 0),
            ];
        }

        if ($dryRun) {
            return response()->json([
                'dry_run' => true,
                'package' => ['id' => $pkg->id, 'name' => $pkg->name, 'remaining' => $pkg->remaining_sessions],
                'report'  => $report,
            ]);
        }

        DB::transaction(function () use ($courses, $pkg) {
            foreach ($courses as $sc) {
                if (!empty($sc->PackageID) && (int) $sc->PackageID > 0 && (int) $sc->PackageID !== $pkg->id) {
                    continue;
                }
                $sc->PackageID = $pkg->id;
                $sc->PackageTotalSessions = $pkg->total_sessions;
                $sc->PackageName = $pkg->name;
                $sc->save();
            }
        });

        Log::info('CoursePackage bind-courses', [
            'package_id'        => $id,
            'student_class_ids' => $ids,
            'by_user'           => $request->attributes->get('auth_user')?->id ?? 0,
        ]);

        return response()->json([
            'message' => '已綁定',
            'report'  => $report,
        ]);
    }

    /**
     * PUT /api/v1/course-packages/{id}
     * Update package metadata (name, paid status, stop).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $pkg = CoursePackage::find($id);
        if (!$pkg) {
            return response()->json(['message' => '找不到方案'], 404);
        }

        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        if ($role !== 'super_admin' && !empty($campusIds) && !in_array((int) $pkg->campus_id, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'name'           => 'nullable|string|max:128',
            'paid'           => 'nullable|boolean',
            'paid_at'        => 'nullable|date',
            'stop'           => 'nullable|boolean',
            'closed_reason'  => 'nullable|string|max:32',
            'settlement_day' => 'nullable|integer|min:1|max:31',
            'rate'           => 'nullable|numeric|min:0',
        ]);

        if (array_key_exists('name', $data) && $data['name'] !== null) {
            $pkg->name = $data['name'];
        }
        if (array_key_exists('paid', $data) && $data['paid'] !== null) {
            $pkg->paid = $data['paid'];
        }
        if (array_key_exists('paid_at', $data) && $data['paid_at'] !== null) {
            $pkg->paid_at = $data['paid_at'];
        }
        if (array_key_exists('stop', $data) && $data['stop'] !== null) {
            $pkg->stop = $data['stop'];
        }
        if (array_key_exists('closed_reason', $data) && $data['closed_reason'] !== null) {
            $pkg->closed_reason = $data['closed_reason'];
        }

        $cascadeToMembers = [];
        if (array_key_exists('settlement_day', $data) && $data['settlement_day'] !== null) {
            $pkg->settlement_day = (int) $data['settlement_day'];
            $cascadeToMembers['settlement_day'] = (int) $data['settlement_day'];
        }
        if (array_key_exists('rate', $data) && $data['rate'] !== null) {
            $pkg->rate = (float) $data['rate'];
            $cascadeToMembers['Rate'] = (float) $data['rate'];
        }

        $pkg->save();

        if (!empty($cascadeToMembers)) {
            StudentClass::where('PackageID', $pkg->id)->update($cascadeToMembers);
        }

        return response()->json(['message' => '已更新', 'package' => $pkg]);
    }
}
