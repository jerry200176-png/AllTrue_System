<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LearningRecord;
use App\Models\PackageSessionLedger;
use App\Models\PaymentReport;
use App\Models\SessionDeductionLedger;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\Subject;
use App\Services\ClassSessionMaterializationService;
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

        // ── RISK-003：批次預載所有 members + scheduled_count + next_sessions + has_schedule，避免 N+1 ──
        $packageIds = $packages->pluck('id')->all();
        $allMembers = empty($packageIds)
            ? collect()
            : StudentClass::whereIn('PackageID', $packageIds)
                ->select(['ID', 'PackageID', 'SubjectID', 'TeacherID', 'ClassType', 'Stop', 'week'])
                ->get();

        $memberIds = $allMembers->pluck('ID')->map(fn ($v) => (int) $v)->all();
        $membersByPackage = $allMembers->groupBy('PackageID');

        // scheduled_count（排除 cancelled/leave/leave_adjusted）
        $scheduledCountMap = [];
        if (!empty($memberIds)) {
            $rows = ClassSession::whereIn('StudentClassID', $memberIds)
                ->whereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted'])
                ->select('StudentClassID', DB::raw('COUNT(*) as cnt'))
                ->groupBy('StudentClassID')
                ->get();
            foreach ($rows as $r) {
                $scheduledCountMap[(int) $r->StudentClassID] = (int) $r->cnt;
            }
        }

        // next_sessions（未來最多 3 堂 scheduled 日期）
        $nextSessionsMap = [];
        if (!empty($memberIds)) {
            $today = Carbon::now()->toDateString();
            $futureRows = ClassSession::whereIn('StudentClassID', $memberIds)
                ->where('Status', 'scheduled')
                ->where('SessionDate', '>=', $today)
                ->orderBy('SessionDate')
                ->orderBy('StartTime')
                ->get(['StudentClassID', 'SessionDate']);
            $grouped = $futureRows->groupBy('StudentClassID');
            foreach ($grouped as $scid => $items) {
                $nextSessionsMap[(int) $scid] = $items
                    ->take(3)
                    ->map(fn ($r) => substr((string) $r->SessionDate, 0, 10))
                    ->values()
                    ->all();
            }
        }

        // 科目/老師名稱批次預載
        $teacherIds = $allMembers->pluck('TeacherID')->map(fn ($v) => (int) $v)->unique()->filter()->all();
        $teacherNameMap = empty($teacherIds)
            ? []
            : DB::table('User')->whereIn('id', $teacherIds)->pluck('Name', 'id')->all();

        $subjectNameByMember = [];
        foreach ($allMembers as $m) {
            $subjectNameByMember[(int) $m->ID] = $m->displaySubjectName();
        }

        $result = $packages->map(function (CoursePackage $pkg) use (
            $membersByPackage,
            $scheduledCountMap,
            $nextSessionsMap,
            $teacherNameMap,
            $subjectNameByMember
        ) {
            $members = $membersByPackage[$pkg->id] ?? collect();

            return [
                'id'                 => $pkg->id,
                'student_id'         => $pkg->student_id,
                'student_name'       => $pkg->student?->name ?? '',
                'campus_id'          => $pkg->campus_id,
                'campus_name'        => $pkg->campus?->name ?? '',
                'name'               => $pkg->name,
                'billing_mode'       => $pkg->billing_mode,
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
                'members'            => $members->map(function ($m) use (
                    $scheduledCountMap,
                    $nextSessionsMap,
                    $teacherNameMap,
                    $subjectNameByMember
                ) {
                    $id = (int) $m->ID;
                    $week = (int) ($m->week ?? 0);
                    return [
                        'student_class_id' => $id,
                        'subject'          => $subjectNameByMember[$id] ?? '',
                        'teacher_name'     => $teacherNameMap[(int) $m->TeacherID] ?? '',
                        'class_type'       => $m->ClassType,
                        'stop'             => (bool) $m->Stop,
                        'scheduled_count'  => $scheduledCountMap[$id] ?? 0,
                        'has_schedule'     => $week >= 1 && $week <= 7,
                        'next_sessions'    => $nextSessionsMap[$id] ?? [],
                    ];
                })->values(),
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
            'subjects.*.days_of_week'    => 'nullable|array|max:6',
            'subjects.*.days_of_week.*'  => 'integer|min:1|max:7',
            'subjects.*.day_time_slots'  => 'nullable|array|max:6',
            'subjects.*.day_time_slots.*.weekday'   => 'nullable|integer|min:1|max:7',
            'subjects.*.day_time_slots.*.start_time' => 'nullable|date_format:H:i',
            'subjects.*.start_time'      => 'nullable|date_format:H:i',
            'subjects.*.duration_hours'  => 'nullable|numeric|min:0.5|max:8',
            'end_date'                   => 'nullable|date',
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
        $pkgEndDate = $data['end_date'] ?? null;
        if ($pkgEndDate) {
            $maxEnd = Carbon::today()->addDays(730)->toDateString();
            if ($pkgEndDate > $maxEnd) {
                return response()->json([
                    'message' => '結束日不可超過 2 年（730 天）',
                    'errors' => ['end_date' => ['結束日距今不可超過 730 天。']],
                ], 422);
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

        return DB::transaction(function () use ($data, $branchId, $isMonthly, $totalSessions, $pkgEndDate) {
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
            $membersScheduled = [];
            $scController = app()->make(\App\Http\Controllers\StudentClassController::class);
            $materializer = app(ClassSessionMaterializationService::class);

            foreach ($data['subjects'] as $subjectSpec) {
                $subjectId = (int) $subjectSpec['subject_id'];
                $teacherId = (int) $subjectSpec['teacher_id'];
                $durationHours = (float) ($subjectSpec['duration_hours'] ?? 2);
                $durationMinutes = (int) round($durationHours * 60);

                $startDate = !empty($subjectSpec['start_date'])
                    ? $subjectSpec['start_date']
                    : Carbon::today()->toDateString();
                $startTimeStr = $subjectSpec['start_time'] ?? '16:00';

                $scheduleFields = [];
                $hasSchedule = false;
                $scheduleSlots = [];

                $dayTimeSlots = $subjectSpec['day_time_slots'] ?? [];
                $daysOfWeek = $subjectSpec['days_of_week'] ?? [];

                if (!empty($dayTimeSlots)) {
                    foreach ($dayTimeSlots as $slot) {
                        $wd = (int) ($slot['weekday'] ?? 0);
                        if ($wd < 1 || $wd > 7) {
                            continue;
                        }
                        $t = (string) ($slot['start_time'] ?? $slot['time'] ?? $startTimeStr);
                        $scheduleSlots[] = ['weekday' => $wd, 'time' => $t];
                    }
                } elseif (!empty($daysOfWeek)) {
                    foreach ($daysOfWeek as $dow) {
                        $wd = (int) $dow;
                        if ($wd < 1 || $wd > 7) {
                            continue;
                        }
                        $scheduleSlots[] = ['weekday' => $wd, 'time' => $startTimeStr];
                    }
                }

                if (count($scheduleSlots) > 6) {
                    Log::warning('CoursePackage createMultiSubject schedule slots truncated to 6', [
                        'subject_id' => $subjectId,
                        'original'   => count($scheduleSlots),
                    ]);
                    $scheduleSlots = array_slice($scheduleSlots, 0, 6);
                }

                if (!empty($scheduleSlots)) {
                    $hasSchedule = true;
                    if (count($scheduleSlots) === 1) {
                        $scheduleFields['week'] = $scheduleSlots[0]['weekday'];
                        $scheduleFields['time'] = $scheduleSlots[0]['time'];
                    } else {
                        foreach ($scheduleSlots as $idx => $slot) {
                            $n = $idx + 1;
                            $scheduleFields["week{$n}"] = $slot['weekday'];
                            $scheduleFields["time{$n}"] = $slot['time'];
                        }
                    }
                }

                $createPayload = [
                    'StudentID'         => (int) $data['student_id'],
                    'GradeID'           => 1,
                    'SubjectID'         => $subjectId,
                    'TeacherID'         => $teacherId,
                    'by1'               => 1,
                    'Period'            => 4,
                    'StartDate'         => $startDate,
                    'TotalHours'        => 0,
                    'room_id'           => null,
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
                ];
                if ($isMonthly && $pkgEndDate) {
                    $createPayload['EndDate'] = $pkgEndDate;
                }
                $createPayload = array_merge($createPayload, $scheduleFields);

                $sc = StudentClass::create($createPayload);

                $confirmedDates = $subjectSpec['confirmed_dates'] ?? [];
                foreach ($confirmedDates as $cDate) {
                    $endTime = Carbon::createFromFormat('H:i', $startTimeStr)
                        ->addMinutes($durationMinutes)
                        ->format('H:i');

                    $session = app(ClassSessionMaterializationService::class)->upsertSlot([
                        'StudentClassID' => $sc->ID,
                        'SessionDate'    => $cDate,
                        'StartTime'      => $startTimeStr,
                        'EndTime'        => $endTime,
                        'Status'         => 'attended',
                    ])['session'];

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

                $scheduledCount = 0;
                $firstSessionDate = null;

                if ($isMonthly && $hasSchedule && $pkgEndDate) {
                    $sessions = $scController->buildSessionsFromWeeklySchedule(
                        (int) $sc->ID,
                        $startDate,
                        $pkgEndDate,
                        $scheduleSlots,
                        $durationMinutes
                    );
                    if (!empty($sessions)) {
                        foreach ($sessions as $sessionSlot) {
                            $materializer->upsertSlot($sessionSlot);
                        }
                        Log::info('monthly_recurring_package: auto-generated sessions', [
                            'package_id'  => $pkg->id,
                            'subject_id'  => $subjectId,
                            'start_date'  => $startDate,
                            'end_date'    => $pkgEndDate,
                            'count'       => count($sessions),
                        ]);
                    }
                } elseif (!$isMonthly && $hasSchedule && $totalSessions > 0) {
                    $member = $sc->fresh();
                    $scController->extendSessionsIfNeeded($member, $totalSessions);
                }

                // 統計補排後實際 ClassSession 數量與首堂日期
                $sessionAgg = ClassSession::where('StudentClassID', $sc->ID)
                    ->whereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted'])
                    ->orderBy('SessionDate')
                    ->orderBy('StartTime')
                    ->get(['SessionDate']);
                $scheduledCount = $sessionAgg->count();
                if ($scheduledCount > 0) {
                    $firstSessionDate = substr((string) $sessionAgg->first()->SessionDate, 0, 10);
                }

                $createdMembers[] = [
                    'student_class_id' => $sc->ID,
                    'subject_id'       => $subjectId,
                    'subject_name'     => $subjectName,
                    'teacher_id'       => $teacherId,
                    'confirmed_count'  => count($confirmedDates),
                ];

                $membersScheduled[] = [
                    'student_class_id'   => (int) $sc->ID,
                    'subject'            => $subjectName,
                    'scheduled_count'    => $scheduledCount,
                    'first_session_date' => $firstSessionDate,
                    'has_schedule'       => $hasSchedule,
                ];
            }

            $pkg->recomputeCounters();

            Log::info('CoursePackage createMultiSubject', [
                'package_id'       => $pkg->id,
                'campus_id'        => $pkg->campus_id,
                'total_sessions'   => $pkg->total_sessions,
                'billing_mode'     => $pkg->billing_mode,
                'member_count'     => count($createdMembers),
                'members_scheduled' => array_map(fn ($m) => [
                    'student_class_id' => $m['student_class_id'],
                    'scheduled_count'  => $m['scheduled_count'],
                    'has_schedule'     => $m['has_schedule'],
                ], $membersScheduled),
            ]);

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
                'members'           => $createdMembers,
                'members_scheduled' => $membersScheduled,
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

        $pkg = CoursePackage::find($id);
        if (!$pkg) {
            return response()->json(['message' => 'Package not found'], 404);
        }

        if ($role !== 'super_admin') {
            $campusIds = $request->attributes->get('auth_campus_ids', []);
            if (!empty($campusIds) && !in_array((int) $pkg->campus_id, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
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
     * POST /api/v1/course-packages/{id}/rebuild-ledger
     *
     * FR-005 — Rebuild package_session_ledger from attended ClassSession records.
     * Use when a package's remaining_sessions is visibly wrong (e.g. group-class
     * over-deduction before the FR-003 dedup was shipped) and cannot be fixed by
     * /recompute alone (which only re-reads existing ledger rows).
     *
     * Body:
     *   { "dry_run": true|false }   optional; default false
     *
     * Auth: director / admin / super_admin only, and the package must belong to a
     * campus the caller has access to.
     */
    public function rebuildLedger(Request $request, int $id): JsonResponse
    {
        $role = $request->attributes->get('auth_role');
        if (!in_array($role, ['director', 'admin', 'super_admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $pkg = CoursePackage::find($id);
        if (!$pkg) {
            return response()->json(['message' => 'Package not found'], 404);
        }

        if ($role !== 'super_admin') {
            $campusIds = $request->attributes->get('auth_campus_ids', []);
            if (!empty($campusIds) && !in_array((int) $pkg->campus_id, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $data = $request->validate([
            'dry_run' => 'nullable|boolean',
        ]);
        $dryRun = (bool) ($data['dry_run'] ?? false);

        $result = PackageDeductionService::rebuildLedgerFromSessions($id, $dryRun);
        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 404);
        }

        Log::info('CoursePackage rebuildLedger', [
            'package_id' => $id,
            'campus_id'  => $pkg->campus_id,
            'dry_run'    => $dryRun,
            'by_user'    => $request->attributes->get('auth_user')?->id ?? 0,
            'result'     => $result,
        ]);

        $message = $dryRun ? 'Dry-run 完成（未寫入）' : 'Ledger 已重建';
        return response()->json(array_merge(['message' => $message], $result));
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

        if ($role !== 'super_admin') {
            $campusIds = $request->attributes->get('auth_campus_ids', []);
            if (!empty($campusIds) && !in_array((int) $pkg->campus_id, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $ids = array_map('intval', $data['student_class_ids']);
        $ids = array_values(array_unique(array_filter($ids, fn ($value) => $value > 0)));
        $courses = StudentClass::whereIn('ID', $ids)->get();
        $report = [];
        $blocked = [];

        $foundIds = $courses->pluck('ID')->map(fn ($value) => (int) $value)->all();
        $missingIds = array_values(array_diff($ids, $foundIds));
        foreach ($missingIds as $missingId) {
            $blocked[] = [
                'student_class_id' => $missingId,
                'reason' => 'course_not_found',
                'message' => '找不到課程',
            ];
        }

        foreach ($courses as $sc) {
            $currentPackage = $sc->PackageID;
            $studentCampusId = (int) (Student::where('id', (int) $sc->StudentID)->value('CampusID') ?? 0);
            $reasons = [];

            if ((int) $sc->StudentID !== (int) $pkg->student_id) {
                $reasons[] = 'different_student';
            }
            if ($studentCampusId !== (int) $pkg->campus_id) {
                $reasons[] = 'different_campus';
            }
            if ((string) ($sc->ScheduleMode ?? 'count') !== 'count') {
                $reasons[] = 'not_count_mode';
            }
            if ((int) ($sc->Stop ?? 0) !== 0) {
                $reasons[] = 'course_inactive';
            }
            if (!empty($currentPackage) && (int) $currentPackage > 0 && (int) $currentPackage !== (int) $pkg->id) {
                $reasons[] = 'different_package';
            }

            // The legacy migration endpoint must share the conversion safety
            // gate.  It may only bind a genuinely unused, unbilled course;
            // existing history is never made safe by calling dry_run=false.
            // Do not inspect another student's/campus's history after the
            // identity gate has already failed.
            if (empty($reasons)) {
                $conversionInspection = $this->inspectPackageConversion($sc, false);
                foreach ($conversionInspection['blocking_reasons'] as $reason) {
                    $reasons[] = $reason['code'];
                }
            }
            $reasons = array_values(array_unique($reasons));

            $report[] = [
                'student_class_id'    => $sc->ID,
                'subject'             => $sc->displaySubjectName(),
                'current_package_id'  => $currentPackage,
                'will_bind'           => empty($reasons) && (empty($currentPackage) || (int) $currentPackage === 0),
                'current_remaining'   => (int) ($sc->RemainingSessions ?? 0),
                'reasons'             => $reasons,
            ];

            if (!empty($reasons)) {
                $blocked[] = [
                    'student_class_id' => (int) $sc->ID,
                    'subject' => $sc->displaySubjectName(),
                    'reasons' => $reasons,
                ];
            }
        }

        if (!empty($blocked)) {
            return response()->json([
                'message' => '部分課程不可綁定到此方案',
                'dry_run' => $dryRun,
                'package' => [
                    'id' => $pkg->id,
                    'name' => $pkg->name,
                    'remaining' => $pkg->remaining_sessions,
                ],
                'report' => $report,
                'blocked' => $blocked,
            ], 422);
        }

        if ($dryRun) {
            return response()->json([
                'dry_run' => true,
                'package' => ['id' => $pkg->id, 'name' => $pkg->name, 'remaining' => $pkg->remaining_sessions],
                'report'  => $report,
                'blocked' => [],
            ]);
        }

        DB::transaction(function () use ($courses, $pkg) {
            foreach ($courses as $sc) {
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

    /** Build the shared safety evidence used by both preview and legacy binding. */
    private function inspectPackageConversion(StudentClass $studentClass, bool $includePackageMembership = true): array
    {
        $classId = (int) $studentClass->ID;
        $attendanceCount = StudentSignIn::where('StudentClassID', $classId)->count();
        $learningRecordCount = LearningRecord::where('StudentClassID', $classId)->count();
        $deductionLedgerCount = SessionDeductionLedger::where('student_class_id', $classId)->count();
        $classSessionCount = ClassSession::where('StudentClassID', $classId)
            ->where(function ($query) {
                $query->whereNull('Status')
                    ->orWhereRaw("LOWER(Status) NOT IN ('cancelled', 'voided')");
            })
            ->count();
        $classSessionTotal = ClassSession::where('StudentClassID', $classId)->count();
        $directInvoiceIds = Invoice::where('StudentClassID', $classId)->pluck('id');
        $itemInvoiceIds = InvoiceItem::where('StudentClassID', $classId)->pluck('InvoiceID');
        $invoiceIds = $directInvoiceIds->merge($itemInvoiceIds)->unique()->values();
        $invoiceRows = $invoiceIds->isEmpty()
            ? collect()
            : Invoice::withCount('payments')
                ->whereIn('id', $invoiceIds)
                ->get(['id', 'Status', 'TotalAmount', 'PaidAmount']);
        $invoiceItemCount = InvoiceItem::where('StudentClassID', $classId)->count();
        $paymentReportCount = PaymentReport::where('StudentClassID', $classId)->count();
        $packageLedgerCount = PackageSessionLedger::where('student_class_id', $classId)->count();
        $studentCampusId = (int) (Student::where('id', (int) $studentClass->StudentID)->value('CampusID') ?? 0);

        $reasons = [];
        $addReason = static function (array &$target, string $code, string $message): void {
            $target[] = ['code' => $code, 'message' => $message];
        };

        if ($includePackageMembership && (int) ($studentClass->PackageID ?? 0) > 0) {
            $addReason($reasons, 'already_in_package', '這門課已屬於其他共用方案。');
        }
        if ((string) ($studentClass->ScheduleMode ?? 'count') !== 'count') {
            $addReason($reasons, 'not_count_mode', '只有單科堂數制可以進入此預檢。');
        }
        if ((int) ($studentClass->Stop ?? 0) !== 0) {
            $addReason($reasons, 'course_inactive', '課程目前已停用，不能轉換。');
        }
        if ($studentClass->isUsageSettlementLocked()) {
            $addReason($reasons, 'usage_settled', '課程已結算鎖定，必須保留原合約。');
        }
        if ((int) ($studentClass->UsedSessions ?? 0) > 0
            || $attendanceCount > 0
            || $deductionLedgerCount > 0
            || $packageLedgerCount > 0) {
            $addReason($reasons, 'usage_history_exists', '已有上課、點名或扣堂歷史，不能直接改寫合約歸屬。');
        }
        if ($classSessionCount > 0) {
            $addReason($reasons, 'class_sessions_exist', '已有排課堂次，請保留原課程並建立新的多科方案。');
        }
        if ($learningRecordCount > 0) {
            $addReason($reasons, 'learning_records_exist', '已有學習紀錄，必須保留原合約。');
        }
        if ($invoiceRows->isNotEmpty()) {
            $addReason($reasons, 'invoice_exists', '已有帳單資料，不能直接轉換。');
        }
        if ($paymentReportCount > 0) {
            $addReason($reasons, 'payment_report_exists', '已有繳費回報，必須先完成帳務處理。');
        }
        if ((int) ($studentClass->Paid ?? 0) !== 0
            || !empty($studentClass->PayDate)
            || (float) ($studentClass->Pay ?? 0) > 0) {
            $addReason($reasons, 'payment_state_exists', '課程已有付款狀態，不能直接轉換。');
        }
        if ((int) ($studentClass->SessionCount ?? 0) <= 0
            || (int) ($studentClass->RemainingSessions ?? 0) <= 0) {
            $addReason($reasons, 'no_remaining_entitlement', '沒有可轉入方案池的剩餘堂數。');
        }

        return [
            'student_campus_id' => $studentCampusId,
            'blocking_reasons' => $reasons,
            'evidence' => [
                'attendance_records' => $attendanceCount,
                'class_session_history' => $classSessionCount,
                'class_sessions_total' => $classSessionTotal,
                'learning_records' => $learningRecordCount,
                'deduction_ledger_entries' => $deductionLedgerCount,
                'package_ledger_entries' => $packageLedgerCount,
                'invoices' => $invoiceRows->count(),
                'invoice_items' => $invoiceItemCount,
                'invoice_payments' => (int) $invoiceRows->sum('payments_count'),
                'payment_reports' => $paymentReportCount,
                'legacy_payment_state' => (int) ($studentClass->Paid ?? 0) !== 0
                    || !empty($studentClass->PayDate)
                    || (float) ($studentClass->Pay ?? 0) > 0,
            ],
        ];
    }

    /**
     * GET /api/v1/student-classes/{studentClass}/package-conversion-preview
     *
     * Read-only safety gate for the proposed single-course → shared-package
     * workflow.  This endpoint deliberately does not bind, copy, or mutate any
     * contract/ledger row.  A clean result means the future conversion command
     * may be considered; it is not itself an authorization to perform it.
     */
    public function conversionPreview(Request $request, StudentClass $studentClass): JsonResponse
    {
        $role = $request->attributes->get('auth_role');
        if (!in_array($role, ['director', 'admin', 'super_admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $studentCampusId = (int) (Student::where('id', (int) $studentClass->StudentID)->value('CampusID') ?? 0);
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        if (!empty($campusIds)
            && !in_array($studentCampusId, array_map('intval', (array) $campusIds), true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $inspection = $this->inspectPackageConversion($studentClass);
        $studentCampusId = $inspection['student_campus_id'];
        $classId = (int) $studentClass->ID;
        $reasons = $inspection['blocking_reasons'];
        $evidence = $inspection['evidence'];
        $canConvert = empty($reasons);
        return response()->json([
            'read_only' => true,
            'mode' => 'single_course_to_shared_package_preview',
            'can_convert' => $canConvert,
            'recommendation' => $canConvert ? 'conversion_ready_for_review' : 'create_new_package',
            'student_class' => [
                'id' => $classId,
                'student_id' => (int) $studentClass->StudentID,
                'campus_id' => $studentCampusId,
                'schedule_mode' => (string) ($studentClass->ScheduleMode ?? 'count'),
                'session_count' => (int) ($studentClass->SessionCount ?? 0),
                'remaining_sessions' => (int) ($studentClass->RemainingSessions ?? 0),
                'used_sessions' => (int) ($studentClass->UsedSessions ?? 0),
            ],
            'evidence' => $evidence,
            'blocking_reasons' => $reasons,
            'next_step' => $canConvert
                ? '由受控轉換流程建立多科方案，並保留原始課程識別與稽核紀錄。'
                : '請保留原單科合約，另建立多科合併方案；不要使用 bind-courses 直接改綁。',
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
            'total_sessions' => 'nullable|integer|min:1|max:999',
        ]);

        // ── total_sessions guard：月結方案不支援修改總堂數 (RISK-005) ──
        $wantTotalChange = array_key_exists('total_sessions', $data)
            && $data['total_sessions'] !== null
            && (int) $data['total_sessions'] !== (int) $pkg->total_sessions;

        if ($wantTotalChange && (string) ($pkg->billing_mode ?? 'count') !== 'count') {
            return response()->json(['message' => '月結方案不支援修改總堂數'], 422);
        }

        if ($wantTotalChange) {
            $newTotal = (int) $data['total_sessions'];
            $usedSessions = (int) ($pkg->used_sessions ?? 0);
            if ($newTotal < $usedSessions) {
                return response()->json([
                    'message' => "新總堂數不可小於已使用堂數（{$usedSessions} 堂）",
                ], 422);
            }
        }

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

        // ── total_sessions 同步：僅當值有變動時執行 ──
        $cancelledSessions = [];
        $extendedCount = 0;
        if ($wantTotalChange) {
            $newTotal = (int) $data['total_sessions'];
            $oldTotal = (int) $pkg->total_sessions;

            DB::transaction(function () use ($pkg, $newTotal, &$cancelledSessions, &$extendedCount) {
                // RISK-002 守護：bulk update 僅更新 SessionCount 與 PackageTotalSessions，
                // 絕不碰 Charge / Rate / RemainingSessions（這些由 preserved_delta 與 ledger 保護）
                StudentClass::where('PackageID', $pkg->id)
                    ->update([
                        'SessionCount'         => $newTotal,
                        'PackageTotalSessions' => $newTotal,
                    ]);

                $members = StudentClass::where('PackageID', $pkg->id)->get();
                $scController = app()->make(\App\Http\Controllers\StudentClassController::class);

                foreach ($members as $member) {
                    if ((string) ($member->ScheduleMode ?? 'count') !== 'count') {
                        continue;
                    }

                    // 先快照「被取消的 scheduled 排課 id list」：在呼叫 cancelExcessScheduledSessions
                    // 之前找出所有 non-terminal sessions 超過 newTotal 的 scheduled 列。
                    $beforeAll = ClassSession::where('StudentClassID', $member->ID)
                        ->whereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted'])
                        ->orderBy('SessionDate')
                        ->orderBy('StartTime')
                        ->orderBy('id')
                        ->get();
                    $candidateCancelIds = [];
                    if ($beforeAll->count() > $newTotal) {
                        foreach ($beforeAll->slice($newTotal) as $excess) {
                            if ($excess->Status === 'scheduled') {
                                $candidateCancelIds[] = (int) $excess->id;
                            }
                        }
                    }

                    $beforeActive = $beforeAll->count();

                    $scController->cancelExcessScheduledSessions((int) $member->ID, $newTotal);
                    $scController->extendSessionsIfNeeded($member, $newTotal);

                    $afterActive = ClassSession::where('StudentClassID', $member->ID)
                        ->whereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted'])
                        ->count();
                    if ($afterActive > $beforeActive) {
                        $extendedCount += ($afterActive - $beforeActive);
                    }

                    foreach ($candidateCancelIds as $sid) {
                        $cancelledSessions[] = [
                            'student_class_id' => (int) $member->ID,
                            'session_id'       => $sid,
                        ];
                    }
                }

                // remaining_sessions 冪等公式（NFR-003）：任何時候重算結果都一致
                $pkg->total_sessions     = $newTotal;
                $pkg->remaining_sessions = max(0, $newTotal - (int) $pkg->used_sessions);
                $pkg->save();
            });

            // in-app #208: the bulk update above only cascades SessionCount /
            // PackageTotalSessions to members (RISK-002 guard — never blind-write
            // RemainingSessions there). Without this, each member's RemainingSessions
            // silently keeps its pre-edit value until someone separately calls
            // /recompute. Reuse the existing ledger-based reconciliation instead of
            // writing new remaining-session logic.
            PackageDeductionService::fullRecompute($pkg->id);
            $pkg->refresh();

            Log::info('CoursePackage totalSessions changed', [
                'package_id'       => $pkg->id,
                'campus_id'        => $pkg->campus_id,
                'before'           => $oldTotal,
                'after'            => $newTotal,
                'member_count'     => StudentClass::where('PackageID', $pkg->id)->count(),
                'extended_count'   => $extendedCount,
                'cancelled_count'  => count($cancelledSessions),
                'by_user'          => $request->attributes->get('auth_user')?->id ?? 0,
            ]);
        }

        return response()->json([
            'message'            => '已更新',
            'package'            => $pkg,
            'cancelled_sessions' => $cancelledSessions,
            'extended_count'     => $extendedCount,
        ]);
    }
}
