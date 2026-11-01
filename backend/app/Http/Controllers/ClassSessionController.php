<?php

namespace App\Http\Controllers;

use App\Exceptions\SlotOccupiedException;
use App\Models\ClassSession;
use App\Models\CourseContractGroupMember;
use App\Models\LearningRecord;
use App\Models\LearningRecordTeacherChange;
use App\Models\Notification;
use App\Models\Schedule;
use App\Models\ScheduleAuditLog;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\ClassSessionMaterializationService;
use App\Services\ContractScheduleMatcher;
use App\Services\CourseLeaveCascadeService;
use App\Services\EnrollmentService;
use App\Services\LearningRecordBackfillService;
use App\Services\LearningRecordResurrectionPolicy;
use App\Services\OccurrenceAssignmentService;
use App\Services\PackageDeductionService;
use App\Services\ScheduleGuardService;
use App\Services\SessionDeductionService;
use App\Services\SessionProjectionReadService;
use App\Services\SubstituteService;
use App\Services\Scheduling\ClassSessionContractReassigner;
use App\Support\TeacherProfileDirectory;
use App\Support\AttendanceStatus;
use App\Support\DeadlockRetry;
use App\Support\LearningRecordMutableOwnership;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ClassSessionController extends Controller
{
    /** LIST API (`index`) — pagination allowed; not for calendar projection. */
    private const LIST_MAX_PER_PAGE = 2000;

    /** PROJECTION API max inclusive date span (calendar prefetch window + buffer). */
    private const PROJECTION_MAX_RANGE_DAYS = 120;

    /** PROJECTION API hard row cap — reject with 413 instead of silent truncation. */
    private const PROJECTION_MAX_ROWS = 15000;

    public function batchStore(Request $request)
    {
        $role = (string) $request->attributes->get('auth_role');
        if ($request->has('discount') && !in_array($role, ['director', 'admin', 'super_admin'], true)) {
            return response()->json(['message' => 'Only financial-authorized staff may set transaction discounts.'], 403);
        }
        $data = $request->validate([
            'student_id' => 'required|integer|exists:Student,id',
            'teacher_id' => 'required|integer|exists:User,id',
            'subject' => 'required|string|max:64',
            'class_type' => 'required|in:one_on_one,one_on_two,one_on_three,tutoring,trial',
            // in-app #426: which regular class type a trial is for (trial only).
            'trial_target_class_type' => 'nullable|in:' . implode(',', StudentClass::TRIAL_TARGET_CLASS_TYPES) . '|prohibited_unless:class_type,trial',
            'total_classes' => 'nullable|integer|min:1|max:500',
            'confirmed_dates' => 'present|array|max:500',
            'confirmed_dates.*' => 'date',
            'future_dates' => 'present|array|max:500',
            'future_dates.*' => 'required|date',
            'session_plan' => 'nullable|array|max:500',
            'session_plan.*.session_date' => 'required_with:session_plan|date',
            'session_plan.*.start_time' => 'required_with:session_plan|date_format:H:i',
            'session_plan.*.kind' => 'required_with:session_plan|in:confirmed,future',
            'session_plan.*.subject' => 'nullable|string|max:64',
            'days_of_week' => 'nullable|array',
            'days_of_week.*' => 'integer|min:1|max:7',
            'start_time' => 'required_without:day_time_slots|date_format:H:i',
            'day_time_slots' => 'nullable|array|max:56',
            'day_time_slots.*.day' => 'required_with:day_time_slots|integer|min:1|max:7',
            'day_time_slots.*.start_time' => 'required_with:day_time_slots|date_format:H:i',
            'day_time_slots.*.duration_minutes' => 'nullable|integer|min:30|max:480',
            'day_time_slots.*.subject' => 'nullable|string|max:64',
            'day_time_slots.*.teacher_id' => 'nullable|integer|exists:User,id',
            'allow_multi_teacher' => 'nullable|boolean',
            'duration_minutes' => 'required|integer|min:30|max:480',
            // RFC non-standard duration: per-course billing contract. The UI may step in
            // 15/30-minute increments, but any legal integer minute value is accepted.
            'deduction_basis' => 'nullable|in:fixed_session,actual_duration',
            'standard_lesson_minutes' => 'nullable|integer|min:30|max:480|required_if:deduction_basis,actual_duration',
            'overage_confirmed' => 'nullable|boolean',
            'rate_unit' => 'nullable|in:session,hour',
            // Tutoring is free and has no receivable; paid class types retain the
            // required amount contract. EnrollmentService is the final authority.
            'price_per_session' => 'nullable|required_unless:class_type,tutoring|numeric|min:0',
            'payment_type' => 'required|in:session,monthly',
            'scheduling_policy' => 'nullable|in:auto_recurrence,manual_occurrence',
            'settlement_day' => 'nullable|integer|min:1|max:31',
            'monthly_sessions' => 'nullable|integer|min:1|max:500',
            'room_id' => 'nullable|integer|exists:rooms,id',
            'memo' => 'nullable|string|max:' . StudentClass::MEMO_MAX_LENGTH,
            'paid_at' => 'nullable|date',
            'branch_id' => 'nullable|integer|min:1',
            'mode' => 'nullable|in:create,backfill',
            'force' => 'nullable|boolean',
            'force_reason' => 'nullable|string|in:create_trial,renewal_next_term,independent_parallel',
            'force_note' => 'nullable|string|max:200',
            'existing_contract_ids' => 'nullable|array|max:50',
            'existing_contract_ids.*' => 'integer|min:1',
            'course_start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'discount' => 'nullable|array',
            'discount.type' => 'required_with:discount|in:NONE,FIXED_AMOUNT,PERCENTAGE',
            'discount.value' => 'required_with:discount|string',
            'discount.reason' => 'nullable|string|max:500',
        ]);

        return app(EnrollmentService::class)->store($request, $data);
    }

    public function index(Request $request)
    {
        if ($response = $this->authorizeAndMaterializeClassSessionIndex($request, true)) {
            return $response;
        }

        $query = $this->buildClassSessionIndexQuery($request);
        $perPage = min(max((int) $request->input('per_page', 1000), 1), self::LIST_MAX_PER_PAGE);
        $rows = $query
            ->orderBy('cs.SessionDate', 'asc')
            ->orderBy('cs.StartTime', 'asc')
            ->orderBy('cs.id', 'asc')
            ->paginate($perPage);

        $rows->getCollection()->transform(fn ($row) => $this->transformClassSessionIndexRow($row));

        $byClass = $this->buildByClassMapFromItems($rows->items());

        if (config('perfflags.log_session_count_mismatch')) {
            $this->logSessionCountMismatches($byClass, $request);
        }

        $rangeStart = $request->filled('start') ? (string) $request->input('start') : null;
        $rangeEnd = $request->filled('end') ? (string) $request->input('end') : null;
        $requestedClassIds = [];
        if ($request->filled('student_class_id')) {
            $requestedClassIds[] = (int) $request->input('student_class_id');
        }
        if ($request->filled('student_class_ids')) {
            $requestedClassIds = array_merge($requestedClassIds, $this->normalizeIds($request->input('student_class_ids')));
        }
        $projectedByClass = $this->buildProjectedByClassForIndex($byClass, $rangeStart, $rangeEnd, $requestedClassIds);

        return response()->json([
            'materialized' => [
                'data' => $rows->items(),
                'by_class' => $byClass,
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
            'projected' => [
                'by_class' => $projectedByClass,
            ],
            // Legacy top-level aliases — materialized rows only (never mixed with projected).
            'data' => $rows->items(),
            'by_class' => $byClass,
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
        ]);
    }

    private function authorizeAndMaterializeClassSessionIndex(Request $request, bool $validateListDates = false): ?\Illuminate\Http\JsonResponse
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        $teacherId = (int) ($request->attributes->get('auth_teacher_id') ?? 0);

        if ($role === 'teacher' && $teacherId <= 0) {
            return response()->json(['message' => 'Teacher not linked'], 403);
        }

        $requestedCampus = (int) ($request->input('branch_id') ?? $request->input('campus_id') ?? 0);
        if ($requestedCampus > 0) {
            if ($role !== 'super_admin' && !empty($campusIds) && !in_array($requestedCampus, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden: branch not accessible'], 403);
            }
            $campusIds = [$requestedCampus];
        }

        if ($validateListDates) {
            // Keep authorization errors first, then reject bad dates before materialization.
            $request->validate([
                'start' => 'nullable|date_format:Y-m-d',
                'end' => 'nullable|date_format:Y-m-d',
            ]);
        }

        if ($role === 'teacher') {
            $this->autoMaterializeTeacherMonthlySessionsForRange($request, $teacherId, $campusIds);
        }
        $this->autoMaterializeCountSessionsForRange($request, (string) $role, $teacherId, $campusIds);
        $this->autoMaterializeScheduledExceptionsForRange($request, $campusIds);

        return null;
    }

    /**
     * Repair the read boundary between schedules and ClassSession for normal
     * scheduled exceptions. Older substitute/reschedule writes could leave the
     * schedule row present while the class-session projection was missing, so
     * the capacity view showed two students but the teacher calendar showed one
     * (#233). Makeup rows (type=extra) intentionally remain schedule-only per
     * the R13 contract.
     *
     * This is deliberately limited to a same-day read and is idempotent through
     * ClassSessionMaterializationService::upsertSlot.
     *
     * @param array<int> $campusIds
     */
    private function autoMaterializeScheduledExceptionsForRange(Request $request, array $campusIds): void
    {
        if (!$request->filled('start') || !$request->filled('end')) {
            return;
        }

        try {
            $start = Carbon::parse((string) $request->input('start'))->toDateString();
            $end = Carbon::parse((string) $request->input('end'))->toDateString();
        } catch (\Throwable $e) {
            return;
        }

        if ($start !== $end) {
            return;
        }

        $schedules = Schedule::query()
            ->whereDate('schedule_date', $start)
            ->where('status', 'scheduled')
            ->where(function ($query) {
                $query->whereNull('type')->orWhere('type', '!=', 'extra');
            })
            ->whereNotNull('student_course_id')
            ->when(!empty($campusIds), fn ($query) => $query->whereIn('branch_id', $campusIds))
            ->get();

        // #319 perf: this runs on EVERY same-day read (Teacher Home polls it each
        // minute) over all schedules of the day. Batch-load courses, anchor dates and
        // already-materialized slots so steady state is O(1) queries, not ~5 per row.
        $courseIds = $schedules->pluck('student_course_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $courses = $courseIds === []
            ? collect()
            : StudentClass::query()->whereIn('ID', $courseIds)->get()->keyBy(fn ($c) => (int) $c->getAttribute('ID'));
        $anchorIds = $schedules->pluck('original_schedule_id')->map(fn ($v) => (int) $v)->filter(fn ($v) => $v > 0)->unique()->values()->all();
        $anchorDates = $anchorIds === [] ? collect() : Schedule::query()->whereIn('id', $anchorIds)->pluck('schedule_date', 'id');
        $existingSlots = [];
        if ($courseIds !== []) {
            ClassSession::query()
                ->whereIn('StudentClassID', $courseIds)
                ->whereDate('SessionDate', $start)
                ->get(['StudentClassID', 'StartTime'])
                ->each(function ($r) use (&$existingSlots) {
                    $existingSlots[(int) $r->StudentClassID . '|' . substr((string) $r->StartTime, 0, 5)] = true;
                });
        }

        foreach ($schedules as $schedule) {
            $course = $courses->get((int) $schedule->student_course_id);
            if (!$course || (int) ($course->Stop ?? 0) === 1) {
                continue;
            }

            // A cross-date reschedule is materialized by the reschedule flow;
            // repairing it here would create a duplicate ghost occurrence.
            $anchorId = (int) ($schedule->original_schedule_id ?? 0);
            if ($anchorId > 0) {
                $anchorDate = $anchorDates->get($anchorId);
                if ($anchorDate && Carbon::parse($anchorDate)->toDateString() !== $start) {
                    continue;
                }
            }

            if (isset($existingSlots[(int) $course->getAttribute('ID') . '|' . substr((string) ($schedule->start_time ?? ''), 0, 5)])) {
                continue; // upsertSlot would be a no-op (same course/date/HH:MM exists)
            }

            $startTime = substr((string) ($schedule->start_time ?? ''), 0, 8);
            $endTime = substr((string) ($schedule->end_time ?? ''), 0, 8);
            if ($startTime === '' || $endTime === '') {
                continue;
            }
            if (strlen($startTime) === 5) {
                $startTime .= ':00';
            }
            if (strlen($endTime) === 5) {
                $endTime .= ':00';
            }

            app(ClassSessionMaterializationService::class)->upsertSlot([
                'StudentClassID' => (int) $course->getAttribute('ID'),
                'SubjectID' => $course->getAttribute('SubjectID') ?: null,
                'SessionDate' => $start,
                'StartTime' => $startTime,
                'EndTime' => $endTime,
                'Status' => 'scheduled',
                'Note' => 'auto-materialized-from-schedule-read-repair',
            ]);
        }
    }

    private function buildClassSessionIndexQuery(Request $request)
    {
        return app(\App\Services\ClassSessionIndexReadService::class)->buildQuery($request);
    }

    private function fetchAllClassSessionIndexRows($query): array
    {
        $items = [];
        (clone $query)
            ->orderBy('cs.SessionDate', 'asc')
            ->orderBy('cs.StartTime', 'asc')
            ->orderBy('cs.id', 'asc')
            ->chunk(1000, function ($chunk) use (&$items) {
                foreach ($chunk as $row) {
                    $items[] = $this->transformClassSessionIndexRow($row);
                }
            });

        return $items;
    }

    /**
     * Calendar projection must use the same count-contract boundary as course
     * management. A materialized future reservation is not actionable once
     * attendance/ledger evidence has consumed the full contract (in-app #289).
     *
     * @param list<object> $items
     * @return list<object>
     */
    private function withoutContractCappedFutureReservations(array $items): array
    {
        $classIds = array_values(array_unique(array_filter(array_map(
            fn ($item) => (int) ($item->student_class_id ?? 0),
            $items
        ), fn (int $id) => $id > 0)));
        if ($classIds === []) {
            return $items;
        }

        $diagnostics = SessionDeductionService::batchExpectedUsedSessionDiagnostics($classIds);
        $today = Carbon::today()->toDateString();

        return array_values(array_filter($items, function ($item) use ($diagnostics, $today): bool {
            $classId = (int) ($item->student_class_id ?? 0);
            if (!$this->isCountContractCapped($diagnostics[$classId] ?? null)) {
                return true;
            }

            $status = strtolower((string) ($item->status ?? ''));
            $date = substr((string) ($item->session_date ?? ''), 0, 10);

            return $date === ''
                || $date <= $today
                || !in_array($status, ['scheduled', 'rescheduled'], true);
        }));
    }

    /** @param array<string, mixed>|null $diagnostic */
    private function isCountContractCapped(?array $diagnostic): bool
    {
        return $diagnostic !== null
            && !empty($diagnostic['is_session_mode'])
            && (int) ($diagnostic['session_count'] ?? 0) > 0
            && (int) ($diagnostic['expected_used'] ?? 0) >= (int) ($diagnostic['session_count'] ?? 0);
    }

    private function buildByClassMapFromItems(iterable $items): array
    {
        $byClass = [];
        foreach ($items as $item) {
            $key = (string) $item->student_class_id;
            if (!isset($byClass[$key])) {
                $byClass[$key] = [];
            }
            $byClass[$key][] = $item;
        }

        return $byClass;
    }

    private function transformClassSessionIndexRow($row)
    {
        return app(\App\Services\ClassSessionIndexReadService::class)->transformRow($row);
    }

    /**
     * PROJECTION API — completeness-safe ClassSession read for calendar / analytics.
     * See docs/GUIDE_PROJECTION_INTEGRITY.md.
     */
    public function projection(Request $request)
    {
        if ($response = $this->authorizeAndMaterializeClassSessionIndex($request)) {
            return $response;
        }

        $request->validate([
            'start' => 'required|date',
            'end' => 'required|date|after_or_equal:start',
        ]);

        $start = Carbon::parse($request->input('start'))->toDateString();
        $end = Carbon::parse($request->input('end'))->toDateString();
        $rangeDays = Carbon::parse($start)->diffInDays(Carbon::parse($end));
        if ($rangeDays > self::PROJECTION_MAX_RANGE_DAYS) {
            return response()->json([
                'message' => 'Projection date range exceeds ' . self::PROJECTION_MAX_RANGE_DAYS . ' days',
            ], 422);
        }

        $role = (string) $request->attributes->get('auth_role');
        if ($role !== 'teacher' && (int) ($request->input('branch_id') ?? $request->input('campus_id') ?? 0) <= 0) {
            return response()->json(['message' => 'branch_id is required for calendar projection'], 422);
        }

        $query = $this->buildClassSessionIndexQuery($request);
        $items = $this->fetchAllClassSessionIndexRows($query);
        $items = $this->withoutContractCappedFutureReservations($items);

        if (count($items) > self::PROJECTION_MAX_ROWS) {
            return response()->json([
                'message' => 'Projection row count exceeds safe limit; narrow the date range',
                'total' => count($items),
                'limit' => self::PROJECTION_MAX_ROWS,
            ], 413);
        }

        $byClass = $this->buildByClassMapFromItems($items);
        $projectedByClass = $this->buildProjectedByClassForIndex(
            $byClass,
            $start,
            $end,
            [],
            true
        );

        return response()->json([
            'api_kind' => 'projection',
            'completeness' => 'full',
            'total' => count($items),
            'data' => $items,
            'by_class' => $byClass,
            'projected' => ['by_class' => $projectedByClass],
        ]);
    }

    /**
     * @param  array<string, list<object>>  $materializedByClass
     * @param  list<int>  $requestedClassIds  Explicitly requested course IDs (student_class_id(s)
     *         query params) that must still be considered for projection even when they have zero
     *         materialized ClassSession rows inside [rangeStart, rangeEnd] -- e.g. a brand-new
     *         recurring course whose first occurrence hasn't happened yet. Without this, a course
     *         is only ever a projection candidate when array_keys($materializedByClass) happens to
     *         include it, silently dropping every future occurrence for courses with no history in
     *         range (in-app #222).
     * @return array<string, list<array<string, mixed>>>
     */
    private function buildProjectedByClassForIndex(
        array $materializedByClass,
        ?string $rangeStart,
        ?string $rangeEnd,
        array $requestedClassIds = [],
        bool $includeTeacherCandidates = false
    ): array
    {
        return app(\App\Services\ClassSessionIndexProjectionService::class)->buildProjectedByClassForIndex(
            request(),
            $materializedByClass,
            $rangeStart,
            $rangeEnd,
            $requestedClassIds,
            $includeTeacherCandidates
        );
    }

    /**
     * Same-day class-sessions reads for count-mode contracts may have sparse
     * ClassSession rows (contract sequence ahead of materialized slots).
     * extendSessionsIfNeeded routes writes through ClassSessionMaterializationService.
     *
     * Shared-package members (PackageID > 0) are skipped: pool allocation across
     * subjects is a separate business rule (see #162 / 周宏謙 case).
     *
     * @param  array<int>  $campusIds
     */
    private function autoMaterializeCountSessionsForRange(Request $request, string $role, int $teacherId, array $campusIds): void
    {
        if (!$request->filled('start') || !$request->filled('end')) {
            return;
        }

        try {
            $start = Carbon::parse((string) $request->input('start'))->toDateString();
            $end = Carbon::parse((string) $request->input('end'))->toDateString();
        } catch (\Throwable $e) {
            return;
        }

        if ($start !== $end) {
            return;
        }

        $targetDate = $start;
        $isoWeekday = (int) Carbon::parse($targetDate)->dayOfWeekIso;

        $classes = StudentClass::query()
            ->join('Student as st', 'st.id', '=', 'StudentClass.StudentID')
            ->where('StudentClass.ScheduleMode', 'count')
            ->where(function ($q) {
                $q->whereNull('StudentClass.scheduling_policy')
                    ->orWhere('StudentClass.scheduling_policy', '!=', 'manual_occurrence');
            })
            ->where('StudentClass.SessionCount', '>', 0)
            ->where(function ($q) {
                $q->whereNull('StudentClass.PackageID')
                    ->orWhere('StudentClass.PackageID', '<=', 0);
            })
            ->where(function ($q) {
                $q->where('StudentClass.Stop', 0)->orWhereNull('StudentClass.Stop');
            })
            ->where(function ($q) use ($isoWeekday) {
                foreach (['week', 'week1', 'week2', 'week3', 'week4', 'week5', 'week6'] as $field) {
                    $q->orWhere("StudentClass.{$field}", $isoWeekday);
                }
            })
            ->where(function ($q) use ($targetDate) {
                $q->whereNull('StudentClass.StartDate')
                    ->orWhereDate('StudentClass.StartDate', '<=', $targetDate);
            })
            ->when($role === 'teacher', fn ($q) => $q->where('StudentClass.TeacherID', $teacherId))
            ->when(!empty($campusIds), fn ($q) => $q->whereIn('st.CampusID', $campusIds))
            ->select('StudentClass.*')
            ->get();

        // TD-018 Offender A pattern applied to count-mode: batch-preload existing
        // ClassSession rows for ALL candidate classes in ONE query instead of one
        // query per class inside extendSessionsIfNeeded (N+1, linear with contract count).
        $classIds = $classes->pluck('ID')->map(fn ($v) => (int) $v)->all();
        $existingSessionsByClass = [];
        if (!empty($classIds)) {
            ClassSession::whereIn('StudentClassID', $classIds)
                ->orderBy('SessionDate')
                ->orderBy('StartTime')
                ->orderBy('id')
                ->get()
                ->each(function ($row) use (&$existingSessionsByClass) {
                    $existingSessionsByClass[(int) $row->StudentClassID][] = $row;
                });
        }

        $contractSchedule = app(\App\Services\Scheduling\ContractSessionSchedule::class);
        foreach ($classes as $studentClass) {
            $preloaded = collect($existingSessionsByClass[(int) $studentClass->ID] ?? []);
            try {
                $contractSchedule->extendSessionsIfNeeded($studentClass, (int) $studentClass->SessionCount, $preloaded);
            } catch (SlotOccupiedException $e) {
                // This is a best-effort read-side projection. A legacy or
                // cross-course student overlap must not make the whole branch's
                // attendance list fail with 422; leave the conflicting slot
                // absent and let the existing data-quality guard remain intact.
                $this->logReadSideMaterializationConflict('count', $studentClass, $e);
            }
        }
    }

    /**
     * Teacher today list relies on ClassSession rows; monthly contracts may only
     * exist as projected slots. Materialize missing projected rows for same-day
     * teacher queries so pending attendance lists do not drop students.
     *
     * @param  array<int>  $campusIds
     */
    private function autoMaterializeTeacherMonthlySessionsForRange(Request $request, int $teacherId, array $campusIds): void
    {
        if ($teacherId <= 0 || !$request->filled('start') || !$request->filled('end')) {
            return;
        }

        try {
            $start = Carbon::parse((string) $request->input('start'))->toDateString();
            $end = Carbon::parse((string) $request->input('end'))->toDateString();
        } catch (\Throwable $e) {
            return;
        }

        // Keep scope tight for safety/perf: only same-day requests are auto-materialized.
        if ($start !== $end) {
            return;
        }

        $targetDate = $start;
        $isoWeekday = (int) Carbon::parse($targetDate)->dayOfWeekIso;

        $classes = StudentClass::query()
            ->join('Student as st', 'st.id', '=', 'StudentClass.StudentID')
            ->where('StudentClass.TeacherID', $teacherId)
            ->where('StudentClass.ScheduleMode', 'date')
            ->where(function ($q) {
                $q->where('StudentClass.Stop', 0)->orWhereNull('StudentClass.Stop');
            })
            ->when(!empty($campusIds), fn ($q) => $q->whereIn('st.CampusID', $campusIds))
            ->select('StudentClass.*')
            ->get();

        // #546/TD-018：批次預載「抑制例外（請假/調課等）」與「既有堂次」，
        // 取代原本逐 slot 兩次 exists()（N+1，會隨老師當日課數線性增長）。
        // 以 classId|HH:MM 為鍵；TIME 欄位比較本就忽略秒，與原 SQL 語意一致。
        $classIds = $classes->pluck('ID')->map(fn ($v) => (int) $v)->all();
        // #3901 (R136/R155): one row per lesson already on this date; see monthlyLessonRowsByClass().
        $rowsByClass = !empty($classIds) ? $this->monthlyLessonRowsByClass($classIds, $targetDate) : [];

        foreach ($classes as $studentClass) {
            $startDate = $studentClass->StartDate ? Carbon::parse($studentClass->StartDate)->toDateString() : null;
            $endDate = $studentClass->EndDate ? Carbon::parse($studentClass->EndDate)->toDateString() : null;
            if ($startDate && $targetDate < $startDate) {
                continue;
            }
            if ($endDate && $targetDate > $endDate) {
                continue;
            }

            $slots = $this->resolveProjectedMonthlySlotsForWeekday($studentClass, $isoWeekday);
            if (empty($slots)) {
                continue;
            }
            $slotStarts = array_values(array_unique(array_map(fn ($slot) => substr((string) $slot['start_time'], 0, 5), $slots)));
            $covered = $this->monthlyCoveredSlotStarts($slotStarts, $rowsByClass[(int) $studentClass->ID] ?? [], $targetDate);

            foreach ($slots as $slot) {
                $startHm = substr((string) ($slot['start_time'] ?? ''), 0, 5);
                if ($startHm === '' || isset($covered[$startHm])) {
                    continue;
                }

                try {
                    app(ClassSessionMaterializationService::class)->upsertSlot([
                        '_student_class'     => $studentClass,
                        'StudentClassID'      => (int) $studentClass->ID,
                        'SubjectID'           => $studentClass->SubjectID ?: null,
                        'SessionDate'         => $targetDate,
                        'StartTime'           => (string) $slot['start_time'],
                        'EndTime'             => (string) $slot['end_time'],
                        'Status'              => 'scheduled',
                        'Note'                => 'projected-monthly-materialized-auto',
                        'IsContractException' => 0,
                    ]);
                    // 同一請求內避免重複建立（多 slot 同時段時的防呆）。
                    $covered[$startHm] = true;
                } catch (SlotOccupiedException $e) {
                    $this->logReadSideMaterializationConflict('monthly', $studentClass, $e);
                }
            }
        }
    }

    /**
     * #3901 (R136/R155): the lessons each monthly course already has on the date, one row per lesson:
     * [current HH:MM, frozen original date, frozen original HH:MM]. An independently identified moved lesson (then put on
     * leave / cancelled) still stands for the contract slot it came from, so a read adds no replacement.
     * - A ClassSession is authoritative; a Schedule marker at its time is the same lesson (counted once).
     * - Frozen identity comes from the session, else from a Schedule row of the same lesson: its TD-076
     *   columns, else the original_schedule_id reschedule chain back to the slot it left. A chain that
     *   starts on another date is a moved-in lesson and stands for no slot of this date.
     * - A makeup (type=extra) has no series slot: each extra Schedule absorbs one identity-less session
     *   at its time. A session with frozen identity, or with a marker identity at its time, is never a makeup.
     * - Same-time sessions take the distinct marker identities one-for-one.
     * - A rescheduled marker that was itself a move target is an intermediate hop, not a lesson.
     * - Superseded schedules (TD-076 repair history) and internal reschedule placeholders are ignored.
     *
     * @param  list<int>  $classIds
     * @return array<int, list<array{0:string,1:?string,2:?string}>>
     */
    private function monthlyLessonRowsByClass(array $classIds, string $targetDate): array
    {
        $hm = fn ($t): string => substr((string) $t, 0, 5);
        $ymd = fn ($d): ?string => $d ? Carbon::parse($d)->toDateString() : null;
        $isExtra = fn (Schedule $r): bool => strtolower((string) $r->type) === 'extra';
        $schedules = Schedule::whereIn('student_course_id', $classIds)->whereDate('schedule_date', $targetDate)
            ->get(['id', 'student_course_id', 'start_time', 'status', 'type', 'original_schedule_id', 'original_schedule_date', 'original_start_time'])
            ->keyBy('id');
        $origin = function (Schedule $row) use ($schedules, $hm, $ymd, $targetDate): array {
            $cur = $row;
            for ($hops = 0; $hops < \App\Services\BackfillScheduleOccurrenceIdentityService::MAX_HOPS; $hops++) {
                if ($cur->original_start_time || (int) ($cur->original_schedule_id ?? 0) <= 0) {
                    break;
                }
                $parent = $schedules->get((int) $cur->original_schedule_id);
                if (!$parent instanceof Schedule) {
                    return ['', '--:--']; // moved in from another date: stands for no slot of this date
                }
                $cur = $parent;
            }
            // #3970: a row reached on the last hop gets the same terminal check as inside the loop. A row that
            // still has a parent is not the root, so a chain longer than the limit keeps no identity (Codex r4347251344).
            if ($cur->original_start_time) {
                return [$ymd($cur->original_schedule_date), $hm($cur->original_start_time)];
            }

            return $cur === $row || (int) ($cur->original_schedule_id ?? 0) > 0 ? [null, null] : [$targetDate, $hm($cur->start_time)];
        };
        $scheduleKey = fn (Schedule $r): string => (int) $r->student_course_id . '|' . $hm($r->start_time);
        $extraBudget = [];
        $sameLesson = [];
        foreach ($schedules as $r) {
            if (strtolower((string) $r->status) === Schedule::STATUS_SUPERSEDED) {
                continue; // TD-076 repair history: invisible to readers, never an identity or a makeup
            }
            if ($isExtra($r)) {
                $extraBudget[$scheduleKey($r)] = ($extraBudget[$scheduleKey($r)] ?? 0) + 1;
            } else {
                $sameLesson[$scheduleKey($r)][] = $r;
            }
        }
        // Distinct slot identities the markers at one time stand for. Same-time sessions consume them
        // one-for-one (two cancelled lessons from different slots cover both); a further session repeats the
        // last one. Identities no session consumed stay in $pending for their own marker rows below.
        $pending = [];
        /** @var array<string, array{0:?string,1:?string}> $last */
        $last = [];
        $hasOrigin = function (string $key) use (&$pending, &$last, $sameLesson, $origin): bool {
            if (!isset($pending[$key])) {
                $pending[$key] = [];
                foreach ($sameLesson[$key] ?? [] as $r) {
                    $found = $origin($r);
                    if ($found[1] !== null && !in_array($found, $pending[$key], true)) {
                        $pending[$key][] = $found;
                    }
                }
            }

            return $pending[$key] !== [] || isset($last[$key]);
        };
        $nextOrigin = function (string $key) use (&$pending, &$last, $hasOrigin): array {
            if (!$hasOrigin($key)) {
                return [null, null];
            }
            if ($pending[$key] !== []) {
                $last[$key] = array_shift($pending[$key]);
            }

            return $last[$key];
        };

        $rows = [];
        $taken = [];
        // Internal reschedule placeholders are bookkeeping, hidden from the index; they stand for no lesson.
        $sessions = ClassSession::whereIn('StudentClassID', $classIds)->whereDate('SessionDate', $targetDate)
            ->where(fn ($q) => $q->whereNull('Note')->orWhere('Note', 'NOT LIKE', '%cancelled-duplicate-reschedule-placeholder%'))
            ->orderBy('id')->get(['id', 'StudentClassID', 'StartTime', 'OriginalSessionDate', 'OriginalStartTimeHM']);
        foreach ($sessions as $s) {
            $classId = (int) $s->StudentClassID;
            $key = $classId . '|' . $hm($s->StartTime);
            if ($s->OriginalStartTimeHM) {
                $rows[$classId][] = [$hm($s->StartTime), $ymd($s->OriginalSessionDate), $hm($s->OriginalStartTimeHM)];
            } elseif (($extraBudget[$key] ?? 0) > 0 && !$hasOrigin($key)) {
                // The makeup's own session. A marker identity at this time means a regular lesson
                // moved here, so the identity wins over the makeup.
                $extraBudget[$key]--;

                continue;
            } else {
                $rows[$classId][] = [$hm($s->StartTime), ...$nextOrigin($key)];
            }
            $taken[$key] = true;
        }

        foreach ($sameLesson as $key => $markers) {
            $markers = array_values(array_filter($markers, fn (Schedule $r) => in_array(strtolower((string) $r->status), ['leave', 'leave_adjusted', 'excused', 'rescheduled', 'cancelled'], true)
                && !(strtolower((string) $r->status) === 'rescheduled' && (int) ($r->original_schedule_id ?? 0) > 0)));
            if ($markers === []) {
                continue;
            }
            $classId = (int) $markers[0]->student_course_id;
            $hasOrigin($key);
            $emitted = false;
            // Each lesson marker whose identity no session at this time took still stands for its own slot
            // (a partially materialized same-time group keeps every lesson it records).
            foreach ($markers as $r) {
                $found = $origin($r);
                $at = array_search($found, $pending[$key], true);
                if ($found[1] !== null && $at !== false) {
                    array_splice($pending[$key], (int) $at, 1);
                    $rows[$classId][] = [$hm($r->start_time), ...$found];
                    $emitted = true;
                }
            }
            if (!$emitted && !isset($taken[$key])) {
                $rows[$classId][] = [$hm($markers[0]->start_time), ...$nextOrigin($key)];
            }
        }

        return $rows;
    }

    /**
     * #3901: which contract slots of a monthly course already have a lesson on the date.
     *
     * @param  list<string>  $slotStarts  contract slot start times (HH:MM) of the weekday
     * @param  list<array{0:string,1:?string,2:?string}>  $rows  [current HH:MM, frozen original date, frozen original HH:MM] per lesson
     * @return array<string, true>
     */
    private function monthlyCoveredSlotStarts(array $slotStarts, array $rows, string $targetDate): array
    {
        $covered = [];
        foreach ($rows as [$current, $originDate, $originHm]) {
            if ($originHm !== null && $originDate === $targetDate) {
                $covered[$originHm] = true;
            }
            if (in_array($current, $slotStarts, true)) {
                $covered[$current] = true;
            }
        }
        // Legacy off-contract rows without independent identity are ambiguous: retain exact-time coverage.

        return $covered;
    }

    /**
     * Read-side projection conflicts are actionable data-quality signals, but
     * not request-level validation failures. Keep logs free of student PII.
     */
    private function logReadSideMaterializationConflict(
        string $source,
        StudentClass $studentClass,
        SlotOccupiedException $exception,
    ): void {
        Log::warning('class_session.read_materialization_conflict', [
            'source' => $source,
            'student_class_id' => (int) $studentClass->getAttribute('ID'),
            'session_date' => $exception->sessionDate,
            'start_time' => substr($exception->startTime, 0, 5),
            'conflict_session_id' => $exception->conflictSessionId,
            'conflict_status' => $exception->conflictStatus,
            'response_code' => $exception->responseCode,
        ]);
    }

    /**
     * Materialize one projected monthly schedule date so the normal single-session
     * edit flow can operate on a real ClassSession row.
     */
    public function ensureProjected(Request $request)
    {
        $data = $request->validate([
            'student_class_id' => 'required|integer|exists:StudentClass,ID',
            'session_date'     => 'required|date',
            'start_time'       => 'nullable|date_format:H:i',
            'branch_id'        => 'nullable|integer|min:1',
            'cancel'           => 'nullable|boolean',
        ]);

        $role = $request->attributes->get('auth_role');
        if (!in_array($role, ['director', 'super_admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $studentClass = StudentClass::where('ID', (int) $data['student_class_id'])->first();
        if (!$studentClass) {
            return response()->json(['message' => '找不到對應課程'], 404);
        }

        if ((string) ($studentClass->scheduling_policy ?? 'auto_recurrence') === 'manual_occurrence') {
            return response()->json([
                'message' => 'Manual occurrence courses only accept the manual-sessions API.',
                'code' => 'MANUAL_OCCURRENCE_API_REQUIRED',
            ], 422);
        }

        // in-app #340: a paused course (Stop=1, no closed_reason, not settled early) may still have a 預排
        // date cancelled one at a time. That writes a cancelled row only: no scheduled row, no deduction,
        // no billing field. Everything else on a stopped course stays refused.
        $cancelOnly = (bool) ($data['cancel'] ?? false);
        if ($cancelOnly && (int) ($studentClass->Stop ?? 0) !== 1) {
            // Active courses cancel through the normal edit flow (leave/tail side effects live there).
            return response()->json(['message' => '只有暫停中的課程可以直接取消預排日期'], 422);
        }
        if ((int) ($studentClass->Stop ?? 0) === 1) {
            $paused = trim((string) ($studentClass->closed_reason ?? '')) === '' && !$studentClass->isUsageSettlementLocked();
            if (!($cancelOnly && $paused)) {
                return response()->json(['message' => '課程已結案或停用，不能新增堂次'], 422);
            }
        }

        if ((string) ($studentClass->ScheduleMode ?? 'count') !== 'date') {
            return response()->json(['message' => '僅月結固定時段課程可建立推算堂次'], 422);
        }

        $studentCampusId = (int) (Student::where('id', (int) $studentClass->StudentID)->value('CampusID') ?? 0);
        $requestedBranchId = (int) ($data['branch_id'] ?? 0);
        if ($requestedBranchId > 0 && $studentCampusId > 0 && $requestedBranchId !== $studentCampusId) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        if ($role !== 'super_admin' && !empty($campusIds) && !in_array($studentCampusId, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $sessionDate = Carbon::parse($data['session_date'])->toDateString();
        $startDate = $studentClass->StartDate ? Carbon::parse($studentClass->StartDate)->toDateString() : null;
        $endDate = $studentClass->EndDate ? Carbon::parse($studentClass->EndDate)->toDateString() : null;
        if ($startDate && $sessionDate < $startDate) {
            return response()->json(['message' => '堂次日期早於課程開始日'], 422);
        }
        $scheduledException = $this->findScheduledExceptionSlot(
            $studentClass,
            $sessionDate,
            isset($data['start_time']) ? substr((string) $data['start_time'], 0, 5) : null
        );
        if ($endDate && $sessionDate > $endDate) {
            return response()->json(['message' => '堂次日期超過課程到期日'], 422);
        }

        // A persisted exception is the source of truth for this date.  It may
        // intentionally use a different time from the recurring contract.
        $slot = $scheduledException ?: $this->resolveProjectedMonthlySlot(
            $studentClass,
            (int) Carbon::parse($sessionDate)->dayOfWeekIso,
            isset($data['start_time']) ? substr((string) $data['start_time'], 0, 5) : null
        );
        if (!$slot) {
            return response()->json(['message' => '指定日期不符合此課程固定時段'], 422);
        }

        $result = app(ClassSessionMaterializationService::class)->upsertSlot([
            'StudentClassID'       => (int) $studentClass->ID,
            'SubjectID'            => $studentClass->SubjectID ?: null,
            'SessionDate'          => $sessionDate,
            'StartTime'            => $slot['start_time'],
            'EndTime'              => $slot['end_time'],
            'Status'               => $cancelOnly ? 'cancelled' : 'scheduled',
            'Note'                 => $cancelOnly ? 'projected-paused-cancelled' : 'projected-monthly-materialized',
            // A persisted schedule exception is a one-off occurrence even
            // when its stored slot shares the contract's clock time.
            'IsContractException'  => $scheduledException !== null ? 1 : 0,
        ]);
        $created = $result['created'];
        $session = $result['session'];

        if ($cancelOnly && !$created) {
            // A real row already holds this slot (maybe attended): never touch it from the projected-cancel path.
            return response()->json(['message' => '這個日期已有正式堂次，請重新整理後在該堂次上操作', 'code' => 'SESSION_ALREADY_EXISTS'], 409);
        }

        return response()->json([
            'message' => $cancelOnly ? '已取消這一堂預排' : ($created ? '已建立可編輯堂次' : '已取得既有堂次'),
            'created' => $created,
            'session' => $this->sessionPayload($session),
        ]);
    }

    /**
     * State-machine transitions for ClassSession.Status.
     * Key = current status, value = allowed next statuses.
     */
    private const STATUS_TRANSITIONS = [
        'scheduled'      => ['attended', 'late', 'absent', 'leave', 'cancelled'],
        'attended'       => ['leave', 'leave_adjusted', 'scheduled', 'absent', 'late', 'cancelled'],
        'completed'      => ['leave', 'leave_adjusted', 'scheduled', 'absent', 'late', 'cancelled'],
        'late'           => ['leave', 'leave_adjusted', 'scheduled', 'attended', 'absent', 'cancelled'],
        'absent'         => ['leave', 'leave_adjusted', 'scheduled', 'attended', 'late', 'cancelled'],
        'leave'          => ['scheduled', 'attended', 'late', 'absent', 'cancelled'],
        'leave_adjusted' => ['cancelled'],
        'cancelled'      => ['scheduled'],
    ];

    private const ATTENDED_STATUSES = ['attended', 'completed', 'late', 'absent'];
    private const LEAVE_ADJUSTED_REQUIRES = ['director', 'admin', 'super_admin'];

    /**
     * A legacy scheduled exception can be visible as a projected chip before
     * its ClassSession was materialized. Keep that chip actionable by resolving
     * its stored exception time, even when the date is outside the recurring
     * weekday contract.
     *
     * @return array{start_time:string,end_time:string}|null
     */
    private function findScheduledExceptionSlot(
        StudentClass $studentClass,
        string $sessionDate,
        ?string $requestedStart
    ): ?array {
        $query = Schedule::query()
            ->where('student_course_id', (int) $studentClass->getAttribute('ID'))
            ->whereDate('schedule_date', $sessionDate)
            ->where('status', 'scheduled')
            ->where(function ($query): void {
                $query->whereNull('type')->orWhere('type', '!=', 'extra');
            });

        $schedules = $query->orderBy('id')->get()
            ->filter(fn (Schedule $schedule): bool => $this->isMaterializableScheduledException($schedule, $sessionDate))
            ->values();
        if ($schedules->isEmpty()) {
            return null;
        }

        $requestedStart = $requestedStart ? substr($requestedStart, 0, 5) : null;
        if ($requestedStart) {
            $schedule = $schedules->first(function (Schedule $candidate) use ($requestedStart): bool {
                return substr((string) ($candidate->start_time ?? ''), 0, 5) === $requestedStart;
            });
            if ($schedule) {
                return $this->scheduleExceptionSlot($schedule);
            }
        }

        // Without an exact requested-time match, only a single eligible row is
        // safe to materialize. Multiple exceptions require an explicit slot.
        if ($schedules->count() !== 1) {
            return null;
        }

        $schedule = $schedules->first();

        return $this->scheduleExceptionSlot($schedule);
    }

    /**
     * @return array{start_time:string,end_time:string}|null
     */
    private function scheduleExceptionSlot(Schedule $schedule): ?array
    {
        $start = substr((string) ($schedule->start_time ?? ''), 0, 5);
        $end = substr((string) ($schedule->end_time ?? ''), 0, 5);
        if ($start === '' || $end === '') {
            return null;
        }

        return ['start_time' => $start, 'end_time' => $end];
    }

    private function isMaterializableScheduledException(
        Schedule $schedule,
        string $sessionDate,
        bool $allowUnverifiableAnchor = false
    ): bool
    {
        if ((string) ($schedule->status ?? '') !== 'scheduled') {
            return false;
        }

        if (strtolower(trim((string) ($schedule->type ?? ''))) === 'extra') {
            return false;
        }

        $anchorId = (int) ($schedule->original_schedule_id ?? 0);
        if ($anchorId <= 0) {
            return true;
        }

        $anchorDate = Schedule::query()->whereKey($anchorId)->value('schedule_date');
        if (!$anchorDate) {
            return $allowUnverifiableAnchor;
        }

        return Carbon::parse((string) $anchorDate)->toDateString() === $sessionDate;
    }

    /**
     * PATCH /api/v1/class-sessions/{id}
     * Single-session status update with state machine validation.
     */
    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'status'     => 'required|string|max:32',
            'start_time' => 'nullable|string|max:8',
            'end_time'   => 'nullable|string|max:8',
            'note'       => 'nullable|string|max:500',
            'reason'     => 'nullable|string|max:255',
        ]);

        $role = $request->attributes->get('auth_role');
        $authUser = $request->attributes->get('auth_user');
        $authUserId = (int) ($authUser->id ?? 0);
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);

        $session = ClassSession::find($id);
        if (!$session) {
            return response()->json(['message' => '找不到該堂次'], 404);
        }

        $studentClass = StudentClass::where('ID', $session->StudentClassID)->first();
        if (!$studentClass) {
            return response()->json(['message' => '找不到對應課程'], 404);
        }

        $studentCampusId = (int) (Student::where('id', (int) $studentClass->StudentID)->value('CampusID') ?? 0);
        if ($role !== 'super_admin' && !empty($campusIds) && !in_array($studentCampusId, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $currentStatus = strtolower(trim((string) ($session->Status ?? 'scheduled')));
        $newStatus = strtolower(trim((string) $data['status']));

        if ($currentStatus === $newStatus) {
            if (in_array($newStatus, ['cancelled', 'leave', 'leave_adjusted', 'excused'], true)) {
                $sameStatusReason = trim((string) ($data['reason'] ?? ''))
                    ?: ($newStatus === 'cancelled'
                        ? CourseLeaveCascadeService::VOID_REASON_CANCELLED
                        : '單堂標記請假');
                $this->voidAttendanceArtifacts(
                    $session,
                    $authUserId,
                    $sameStatusReason
                );
            }
            // One transaction: a retimed package lesson's ledger fix commits or rolls back with its new time (#4455). Decide
            // against the row locked here (lesson first, a locking read), not the model loaded before an overlapping edit.
            $session = DB::transaction(function () use ($session, $data) {
                /** @var ClassSession|null $locked */
                $locked = ClassSession::query()->whereKey($session->id)->lockForUpdate()->first();
                abort_if($locked === null, 404, '找不到該堂次');
                $this->applyTimeAndNoteUpdates($locked, $data);

                return $locked;
            }, DeadlockRetry::ATTEMPTS);
            $this->syncLearningRecordTime($session, $data);
            return $this->sessionUpdateResponse($session, '堂次已更新');
        }

        if ($studentClass->isUsageSettlementLocked()) {
            return response()->json([
                'message' => '此課程已提前結清並鎖定點名，不可再變更出勤狀態。如需調整請開新約。',
            ], 409);
        }

        // Validate state machine transition
        $allowed = self::STATUS_TRANSITIONS[$currentStatus] ?? [];
        if (!in_array($newStatus, $allowed, true)) {
            return response()->json([
                'message' => "狀態轉移不允許：{$currentStatus} → {$newStatus}",
                'allowed' => $allowed,
            ], 422);
        }

        // A leave changes the course's tail/end-date contract. It must be
        // undone through /schedules/undo-leave-by-session so the cascade,
        // linked schedule row, and leave-owned artifacts are reversed together.
        // Blocking direct leave→attended→scheduled also removes the old
        // workaround that could silently leave an extra tail behind.
        if (
            $currentStatus === 'leave'
            && in_array($newStatus, ['scheduled', 'attended', 'late', 'absent'], true)
        ) {
            return response()->json([
                'message' => '請先使用「取消請假」，系統會同步回復順延堂次；不可直接改成已上或未上。',
                'code' => 'leave_requires_cascade_undo',
            ], 422);
        }

        // leave_adjusted requires director+
        if ($newStatus === 'leave_adjusted') {
            if (!in_array($role, self::LEAVE_ADJUSTED_REQUIRES, true)) {
                return response()->json(['message' => '僅主任/管理員可執行補請假'], 403);
            }
        }

        if ($role === 'teacher') {
            $teacherAllowed = ['attended', 'late', 'absent', 'leave'];
            if (!in_array($newStatus, $teacherAllowed, true)) {
                return response()->json(['message' => '老師僅可標記出缺勤或請假狀態'], 403);
            }
        }

        try {
            return DB::transaction(function () use ($session, $studentClass, $currentStatus, $newStatus, $data, $authUserId, $role) {
                $session = ClassSession::where('id', $session->id)->lockForUpdate()->first();
                $reason = trim((string) ($data['reason'] ?? ''));

                $wasAttended = in_array($currentStatus, self::ATTENDED_STATUSES, true);
                $willAttend = in_array($newStatus, self::ATTENDED_STATUSES, true);

                // --- Transition: anything attended-like → leave/leave_adjusted ---
                if ($newStatus === 'leave_adjusted' || ($wasAttended && $newStatus === 'leave')) {
                    return $this->handleRetroLeaveTransition($session, $studentClass, $authUserId, $reason);
                }

                // --- Transition: scheduled → attended (mark attendance) ---
                if ($currentStatus === 'scheduled' && $willAttend) {
                    $session->Status = $newStatus;
                    $this->applyTimeAndNoteUpdates($session, $data);
                    $session->save();

                    if (in_array($newStatus, ['attended', 'late'], true)) {
                        SessionDeductionService::deductOnAttendance($studentClass, null, (int) $session->id);
                    }

                    // TD-005: sync active StudentSignIn.Status if one exists (e.g. RFID swipe already in)
                    $this->syncStudentSignInStatus($session->id, $newStatus);

                    // R55: attended→scheduled voids LR as 由已上調整狀態; coming back
                    // to attended/late must resurrect or teachers have nothing to fill.
                    $this->restoreVoidedLearningRecord($session);

                    return $this->sessionUpdateResponse($session, '狀態已更新為' . $newStatus);
                }

                // --- Transition: attended swap (e.g. attended → late) ---
                if ($wasAttended && $willAttend) {
                    $session->Status = $newStatus;
                    $this->applyTimeAndNoteUpdates($session, $data);
                    $session->save();
                    // TD-005: sync active StudentSignIn.Status so fetchRecords reflects true state
                    $this->syncStudentSignInStatus($session->id, $newStatus);
                    return $this->sessionUpdateResponse($session, '狀態已更新為' . $newStatus);
                }

                // --- Transition: attended-like → scheduled/cancelled ---
                if ($wasAttended && in_array($newStatus, ['scheduled', 'cancelled'], true)) {
                    $this->voidAttendanceArtifacts($session, $authUserId, $reason ?: '由已上調整狀態');
                    SessionDeductionService::reverseForSession(
                        (int) $studentClass->ID,
                        (int) $session->id,
                        'status_adjust',
                        $authUserId,
                        $reason ?: '狀態調整沖回'
                    );
                    $session->Status = $newStatus;
                    if ($newStatus === 'cancelled') {
                        $session->Note = $this->appendSessionNote($session->Note, 'cancelled-after-attended');
                    } else {
                        $session->Note = $this->appendSessionNote($session->Note, 'revert-to-scheduled');
                    }
                    $this->applyTimeAndNoteUpdates($session, $data);
                    $session->save();
                    if ($newStatus === 'cancelled') {
                        $this->cancelLinkedScheduleExceptions($session);
                    }
                    SessionDeductionService::recomputeCounters((int) $studentClass->ID);

                    $msg = '已更新為' . $newStatus . '，並完成堂數沖回';
                    if ($newStatus === 'cancelled') {
                        $extended = $this->tryExtendOnLeave($studentClass, $session);
                        if ($extended) {
                            $msg .= '，已自動補建一堂至 ' . substr((string) $extended->SessionDate, 0, 10);
                        }
                    }
                    return $this->sessionUpdateResponse($session, $msg);
                }

                // --- Transition: scheduled → leave ---
                if ($currentStatus === 'scheduled' && $newStatus === 'leave') {
                    $this->voidAttendanceArtifacts($session, $authUserId, $reason ?: '單堂標記請假');

                    $session->Status = 'leave';
                    $session->Note = $this->appendSessionNote($session->Note, 'leave');
                    $this->applyTimeAndNoteUpdates($session, $data);
                    $session->save();

                    $extended = $this->tryExtendOnLeave($studentClass, $session);
                    $msg = '已標記請假';
                    if ($extended) {
                        $msg .= '，並已自動順延一堂至 ' . substr((string) $extended->SessionDate, 0, 10);
                    }
                    return $this->sessionUpdateResponse($session, $msg);
                }

                // --- Generic safe transitions ---
                if ($newStatus === 'cancelled') {
                    $this->voidAttendanceArtifacts(
                        $session,
                        $authUserId,
                        $reason ?: CourseLeaveCascadeService::VOID_REASON_CANCELLED
                    );
                }
                $session->Status = $newStatus;
                $this->applyTimeAndNoteUpdates($session, $data);
                $session->save();
                if ($newStatus === 'cancelled') {
                    $this->cancelLinkedScheduleExceptions($session);
                }

                SessionDeductionService::syncCounters($studentClass);

                $msg = '狀態已更新為' . $newStatus;
                if ($newStatus === 'cancelled') {
                    $extended = $this->tryExtendOnLeave($studentClass, $session);
                    if ($extended) {
                        $msg .= '，已自動補建一堂至 ' . substr((string) $extended->SessionDate, 0, 10);
                    }
                }
                return $this->sessionUpdateResponse($session, $msg);
            }, DeadlockRetry::ATTEMPTS); // a deadlocked deduction re-runs the whole status change (#4470)
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function recovery(Request $request, int $id)
    {
        $found = $this->findAccessibleSession($request, $id);
        if ($found instanceof \Symfony\Component\HttpFoundation\Response) {
            return $found;
        }
        [$session] = $found;
        $audit = $this->latestCancellationAudit($session);
        if (!$audit) {
            return response()->json([
                'available' => false,
                'message' => '這堂課沒有可驗證的最近取消紀錄，系統不提供盲目復原。',
            ]);
        }

        return response()->json([
            'available' => true,
            'audit_id' => (int) $audit->getKey(),
            'previous_status' => strtolower((string) data_get($audit->getAttribute('old_data'), 'Status')),
        ]);
    }

    public function restore(Request $request, int $id)
    {
        $data = $request->validate([
            'expected_audit_id' => 'required|integer|min:1',
            'reason' => 'required|string|max:255',
        ]);
        $reason = trim((string) $data['reason']);
        if ($reason === '') {
            return response()->json(['message' => '復原原因不可為空白。'], 422);
        }

        $found = $this->findAccessibleSession($request, $id);
        if ($found instanceof \Symfony\Component\HttpFoundation\Response) {
            return $found;
        }
        [, $studentClass] = $found;
        if ($studentClass->isUsageSettlementLocked()) {
            return response()->json(['message' => '此課程已結算鎖定，請先由帳務流程解除鎖定後再復原。'], 422);
        }

        return DB::transaction(function () use ($id, $studentClass, $data, $reason, $request) {
            $session = ClassSession::query()->where('id', $id)->lockForUpdate()->first();
            $audit = $session ? $this->latestCancellationAudit($session) : null;
            if (!$session || !$audit || (int) $audit->getKey() !== (int) $data['expected_audit_id']) {
                return response()->json(['message' => '這堂課已有新變更，請重新整理後再確認。'], 409);
            }

            $previousStatus = strtolower((string) data_get($audit->getAttribute('old_data'), 'Status'));
            $conflict = ClassSession::query()
                ->where('StudentClassID', $session->getAttribute('StudentClassID'))
                ->whereDate('SessionDate', $session->getAttribute('SessionDate'))
                ->where('StartTime', $session->getAttribute('StartTime'))
                ->where('id', '!=', $session->getKey())
                ->where(function ($query) {
                    $query->whereNull('Status')->orWhere('Status', '!=', 'cancelled');
                })->exists();
            if ($conflict) {
                return response()->json(['message' => '取消期間同一日期／時段已有其他有效堂次，為避免重複排課，未復原。'], 422);
            }

            $before = $this->sessionSnapshot($session);
            $session->setAttribute('Status', $previousStatus);
            if ($previousStatus === 'scheduled') {
                app(ClassSessionMaterializationService::class)->assertStudentSlotAvailableForSession($session);
            }
            $session->setAttribute('Note', $this->appendSessionNote($session->getAttribute('Note'), 'recovered-cancelled'));
            $session->save();

            if (in_array($previousStatus, self::ATTENDED_STATUSES, true)) {
                $this->restoreCancelledAttendanceArtifacts($session);
                SessionDeductionService::deductOnAttendance($studentClass, null, (int) $session->getKey());
            }
            SessionDeductionService::recomputeCounters((int) $studentClass->getKey());

            $authUser = $request->attributes->get('auth_user');
            ScheduleAuditLog::query()->create([
                'session_id' => $session->getKey(),
                'action_type' => 'update',
                'description' => "復原課堂 #{$session->getKey()}：{$reason}",
                'operator_id' => (int) ($authUser->id ?? 0) ?: null,
                'branch_id' => (int) (Student::query()->where('id', $studentClass->getAttribute('StudentID'))->value('CampusID') ?: 0) ?: null,
                'old_data' => $before,
                'new_data' => $this->sessionSnapshot($session),
            ]);

            return response()->json([
                'message' => '已復原取消前狀態，排課與堂數已重新計算。',
                'session' => $this->sessionPayload($session),
            ]);
        }, DeadlockRetry::ATTEMPTS);
    }

    /** @return array{0: ClassSession, 1: StudentClass}|\Symfony\Component\HttpFoundation\Response */
    private function findAccessibleSession(Request $request, int $id)
    {
        $session = ClassSession::query()->where('id', $id)->first();
        if (!$session) {
            return response()->json(['message' => '找不到該堂次'], 404);
        }
        $studentClass = StudentClass::query()->where('ID', $session->getAttribute('StudentClassID'))->first();
        if (!$studentClass) {
            return response()->json(['message' => '找不到對應課程'], 404);
        }
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        $campusId = (int) (Student::query()->where('id', $studentClass->getAttribute('StudentID'))->value('CampusID') ?? 0);
        if (!empty($campusIds) && !in_array($campusId, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return [$session, $studentClass];
    }

    private function latestCancellationAudit(ClassSession $session): ?ScheduleAuditLog
    {
        $audit = ScheduleAuditLog::query()
            ->where('session_id', $session->getKey())
            ->where('action_type', 'update')
            ->latest('id')
            ->first();
        $oldStatus = strtolower((string) data_get($audit?->getAttribute('old_data'), 'Status'));
        $newStatus = strtolower((string) data_get($audit?->getAttribute('new_data'), 'Status'));
        if (!$audit || !$audit->getAttribute('operator_id') || strtolower((string) $session->getAttribute('Status')) !== 'cancelled'
            || $newStatus !== 'cancelled' || !in_array($oldStatus, ['scheduled', 'attended', 'completed', 'late', 'absent'], true)) {
            return null;
        }
        return $audit;
    }

    /** @return array<string, mixed> */
    private function sessionSnapshot(ClassSession $session): array
    {
        return $session->only([
            'id', 'StudentClassID', 'SubjectID', 'SessionDate', 'StartTime', 'EndTime',
            'Status', 'Note', 'IsContractException', 'session_charge',
        ]);
    }

    private function restoreCancelledAttendanceArtifacts(ClassSession $session): void
    {
        $reasons = [CourseLeaveCascadeService::VOID_REASON_CANCELLED, '由已上調整狀態'];
        LearningRecord::query()->where('ClassSessionID', $session->getKey())->whereNotNull('VoidedAt')
            ->lockForUpdate()->get()->each(function ($record) use ($reasons) {
                if (in_array((string) $record->VoidReason, $reasons, true)) {
                    $record->VoidedAt = null;
                    $record->VoidedByUserID = null;
                    $record->VoidReason = null;
                    $record->save();
                }
            });
        StudentSignIn::query()->where('ClassSessionID', $session->getKey())->whereNotNull('VoidedAt')
            ->lockForUpdate()->get()->each(function ($signIn) use ($reasons) {
                if (in_array((string) $signIn->VoidReason, $reasons, true)) {
                    $signIn->VoidedAt = null;
                    $signIn->VoidedByUserID = null;
                    $signIn->VoidReason = null;
                    $signIn->save();
                }
            });
    }

    private function handleRetroLeaveTransition(ClassSession $session, StudentClass $studentClass, int $authUserId, string $reason)
    {
        $this->voidAttendanceArtifacts($session, $authUserId, $reason ?: '補請假：已上課改請假');

        SessionDeductionService::reverseForSession(
            (int) $studentClass->ID,
            (int) $session->id,
            'retro_leave',
            $authUserId,
            $reason ?: '補請假沖回'
        );

        $session->Status = 'leave_adjusted';
        $session->Note = $this->appendSessionNote($session->Note, 'retro-leave');
        $session->save();

        SessionDeductionService::recomputeCounters((int) $studentClass->ID);

        $extended = $this->tryExtendOnLeave($studentClass, $session);
        $msg = '補請假完成：堂數已沖回';
        if ($extended) {
            $msg .= '，並已自動順延一堂至 ' . substr((string) $extended->SessionDate, 0, 10);
        }
        return $this->sessionUpdateResponse($session, $msg);
    }

    private function voidAttendanceArtifacts(ClassSession $session, int $authUserId, string $reason): void
    {
        StudentSignIn::where('ClassSessionID', $session->id)
            ->active()
            ->get()
            ->each(function ($si) use ($authUserId, $reason) {
                $si->VoidedAt = now();
                $si->VoidedByUserID = $authUserId ?: null;
                $si->VoidReason = $reason;
                $si->save();
            });

        LearningRecord::where('ClassSessionID', $session->id)
            ->active()
            ->get()
            ->each(function ($lr) use ($authUserId, $reason) {
                $lr->VoidedAt = now();
                $lr->VoidedByUserID = $authUserId ?: null;
                $lr->VoidReason = $reason;
                $lr->save();
            });
    }

    /**
     * When a session transitions from leave back to an attended-like status,
     * restore any previously voided LearningRecord so teachers can fill it in.
     *
     * R55 fix: this used to resurrect unconditionally on any leave->attended-like
     * transition, without checking WHY the LR was voided — a manually-voided
     * record (director decision, non-cascade VoidReason) sitting on a session
     * that happened to be 'leave' would have been silently brought back too.
     * Now shares the same eligibility check as LearningRecordController::store()'s
     * reactive resurrect path (LearningRecordResurrectionPolicy), so both places
     * agree on what counts as a safe-to-auto-restore system cascade void.
     */
    private function restoreVoidedLearningRecord(ClassSession $session): void
    {
        LearningRecordResurrectionPolicy::restoreEligibleForSession($session);
    }

    private function applyTimeAndNoteUpdates(ClassSession $session, array $data): void
    {
        $hasTimeChange = !empty($data['start_time']) || !empty($data['end_time']);
        $oldSessionCharge = $session->session_charge;

        // 保留舊時間以便精準匹配 schedules exception 列（避免多堂同日時誤傷他堂）
        $oldStartHm = substr((string) $session->StartTime, 0, 5);
        $oldEndHm   = substr((string) $session->EndTime, 0, 5);
        // FR-003 (#4455): a package lesson moving to another start time locks lesson → course → package before any read.
        $retimed = !empty($data['start_time']) && substr($data['start_time'], 0, 5) !== $oldStartHm;
        $statusBefore = (string) $session->getOriginal('Status');
        if ($retimed) {
            PackageDeductionService::lockForRetime((int) $session->id);
        }

        if (!empty($data['start_time'])) {
            $newStartHm = substr($data['start_time'], 0, 5);
            // #1161: block editing this session onto a slot already held by another
            // non-cancelled session on the same date (would 1062 under the unique index).
            if ($newStartHm !== $oldStartHm) {
                app(ClassSessionMaterializationService::class)->assertSlotAvailable(
                    (int) $session->StudentClassID,
                    $session->SessionDate,
                    $data['start_time'],
                    (int) $session->id
                );
            }
            $session->StartTime = $newStartHm;
        }
        if (!empty($data['end_time'])) {
            $session->EndTime = substr($data['end_time'], 0, 5);
        }
        if (array_key_exists('note', $data)) {
            $session->Note = $data['note'] ?? '';
        }

        if ($hasTimeChange) {
            $this->syncSessionChargeForTimeChange($session, $oldSessionCharge);
        }

        $session->save();

        // FR-003: a debited lesson moved to another start time leaves one debit per group slot (#4455).
        if ($retimed) {
            $authUser = request()->attributes->get('auth_user');
            PackageDeductionService::reslotRetimedSession((int) $session->id, $oldStartHm, $statusBefore, (int) ($authUser->id ?? 0) ?: null);
        }

        // ClassSession 是權威來源；同步更新對應 schedules exception 列的時間，
        // 避免「課程管理 18:30，行事曆還顯示 18:00」的顯示漂移。
        // R84: IsContractException itself is recomputed by ClassSessionObserver::saving()
        // from the $session->save() above (SessionDate/StartTime/EndTime dirty) —
        // no per-controller recompute needed here anymore (#556/TD-055).
        if ($hasTimeChange) {
            $this->syncScheduleExceptionTime($session, $oldStartHm, $oldEndHm);
        }
    }

    /**
     * 當 ClassSession 時間有異動時，同步更新對應 schedules exception 列的 start_time / end_time。
     *
     * 匹配策略：只更新 (student_course_id, schedule_date, OLD start_time, OLD end_time) 精準吻合
     * 的 schedules 列，避免同日多堂課時誤傷其他堂次。
     */
    private function syncScheduleExceptionTime(ClassSession $session, string $oldStartHm, string $oldEndHm): void
    {
        $newStart = substr((string) $session->StartTime, 0, 5);
        $newEnd   = substr((string) $session->EndTime, 0, 5);
        if ($newStart === $oldStartHm && $newEnd === $oldEndHm) {
            return;
        }
        if ($oldStartHm === '' || $oldEndHm === '') {
            return;
        }

        $scheduleDate = $session->SessionDate
            ? substr((string) $session->SessionDate, 0, 10)
            : null;
        if (!$scheduleDate) {
            return;
        }

        DB::table('schedules')
            ->where('student_course_id', (int) $session->StudentClassID)
            ->whereDate('schedule_date', $scheduleDate)
            ->whereIn('status', ['scheduled', 'rescheduled'])
            ->where('start_time', $oldStartHm)
            ->where('end_time', $oldEndHm)
            ->update([
                'start_time' => $newStart,
                'end_time'   => $newEnd,
                'updated_at' => now(),
            ]);
    }

    /**
     * Keep a schedule-backed projected exception from being recreated after
     * its ClassSession is cancelled through the normal session editor.
     */
    private function cancelLinkedScheduleExceptions(ClassSession $session): void
    {
        // This hook is reached for every ClassSession cancellation. Only a
        // projected monthly exception owns an unmaterialized schedule row;
        // ordinary contract sessions and manually-created substitute sessions
        // must not cancel their same-day schedule assignments.
        if (!$this->isProjectedMonthlyExceptionSession($session)) {
            return;
        }

        $date = $session->SessionDate ? substr((string) $session->SessionDate, 0, 10) : null;
        $start = substr((string) $session->StartTime, 0, 5);
        $end = substr((string) $session->EndTime, 0, 5);
        if (!$date || $start === '') {
            return;
        }

        $query = Schedule::query()
            ->where('student_course_id', (int) $session->StudentClassID)
            ->whereDate('schedule_date', $date)
            ->where('status', 'scheduled')
            ->where('start_time', 'like', $start . '%');

        if ($end !== '') {
            $query->where('end_time', 'like', $end . '%');
        }

        $schedules = $query->get()
            ->filter(fn (Schedule $schedule): bool => $this->isCancelableProjectedExceptionSchedule($schedule, $date, $session))
            ->values();
        if ($schedules->isEmpty()) {
            return;
        }

        Schedule::query()
            ->whereIn('id', $schedules->pluck('id')->all())
            ->update(['status' => 'cancelled', 'updated_at' => now()]);
    }

    private function isProjectedMonthlyExceptionSession(ClassSession $session): bool
    {
        $note = (string) ($session->Note ?? '');
        if (!str_contains($note, 'projected-monthly-materialized')) {
            return false;
        }

        if ((int) ($session->IsContractException ?? 0) === 1) {
            return true;
        }

        // Legacy projected rows were explicitly created with the old 0 flag.
        // Recover only the off-contract case; a normal projected occurrence
        // still must not cancel a same-day substitute schedule.
        $studentClass = StudentClass::query()
            ->where('ID', (int) $session->StudentClassID)
            ->first();
        if (!$studentClass || !$session->SessionDate) {
            return false;
        }

        return !app(ContractScheduleMatcher::class)->matchesContract(
            substr((string) $session->SessionDate, 0, 10),
            substr((string) $session->StartTime, 0, 5),
            $session->EndTime ? substr((string) $session->EndTime, 0, 5) : null,
            $studentClass
        );
    }

    private function isCancelableProjectedExceptionSchedule(
        Schedule $schedule,
        string $sessionDate,
        ClassSession $session
    ): bool
    {
        if (!$this->isMaterializableScheduledException($schedule, $sessionDate, true)) {
            return false;
        }

        $anchorId = (int) ($schedule->original_schedule_id ?? 0);
        if ($anchorId <= 0) {
            return true;
        }

        $anchorExists = Schedule::query()->whereKey($anchorId)->exists();
        if (!$anchorExists) {
            // An orphaned anchor cannot be restored by a schedule relationship.
            return true;
        }

        // Same-day substitute rows point at the original schedule but carry a
        // different teacher. They are an independent assignment and must
        // survive cancelling the projected contract exception. A same-day
        // reschedule destination keeps the contract teacher and is owned by
        // this projected occurrence, so it is cancelled with the occurrence.
        $contractTeacherId = (int) StudentClass::query()
            ->where('ID', (int) $session->StudentClassID)
            ->value('TeacherID');

        return $contractTeacherId <= 0
            || (int) ($schedule->teacher_id ?? 0) === $contractTeacherId;
    }

    /**
     * Recompute per-session charge based on actual duration vs contract's SessionDuration,
     * and sync the delta into StudentClass.Charge.
     *
     * Billing rules:
     *   session mode: session_charge = Rate × (actual_minutes / SessionDuration)
     *   hour mode:    session_charge = Rate × (actual_minutes / 60)
     *
     * StudentClass.Charge diff = new_session_charge - (old_session_charge || standard_charge)
     * Standard charge (used as baseline when session has no prior override):
     *   session mode: Rate (1 unit)
     *   hour mode:    Rate × (SessionDuration / 60)
     *
     * No-op when Rate/SessionDuration not configured or resulting actual minutes invalid.
     */
    private function syncSessionChargeForTimeChange(ClassSession $session, $oldSessionCharge): void
    {
        $sc = StudentClass::where('ID', $session->StudentClassID)->first();
        if (!$sc) {
            return;
        }

        $rate = (float) ($sc->Rate ?? 0);
        $rateUnit = strtolower(trim((string) ($sc->rate_unit ?? 'session')));
        if (!in_array($rateUnit, ['session', 'hour'], true)) {
            $rateUnit = 'session';
        }

        if ($rate <= 0) {
            return;
        }

        // 優先採用堂次當日的 per-day duration（duration1~duration6），
        // 避免「Mon 120min / Wed 90min」這種每日不同時長的課程被 SessionDuration 一概而論。
        $iso = 0;
        try {
            $iso = (int) \Carbon\Carbon::parse((string) $session->SessionDate)->isoWeekday();
        } catch (\Throwable $e) {
            $iso = 0;
        }
        $standardDuration = $iso > 0
            ? $sc->resolveSessionDurationForWeekday($iso)
            : (int) ($sc->SessionDuration ?? 0);

        if ($standardDuration <= 0) {
            return;
        }

        $actualMinutes = $this->minutesBetween((string) $session->StartTime, (string) $session->EndTime);
        if ($actualMinutes <= 0) {
            return;
        }

        // 按堂（session）：契約是「一堂多少錢」——時段調整只記錄用途，費用固定為 Rate。
        // 按時（hour）：依實際分鐘比例計費，並把差額同步到 StudentClass.Charge。
        // 禁止把兩種公式合併（見 docs/archive AI_REGRESSION §2026-04-17 單堂費用固定）。
        if ($rateUnit === 'hour') {
            $newSessionCharge = (int) round($rate * ($actualMinutes / 60));
        } else {
            $newSessionCharge = (int) round($rate);
        }

        $baseline = $oldSessionCharge !== null ? (int) $oldSessionCharge : null;
        if ($baseline === null) {
            if ($rateUnit === 'hour') {
                $baseline = (int) round($rate * ($standardDuration / 60));
            } else {
                $baseline = (int) round($rate);
            }
        }

        $delta = $newSessionCharge - $baseline;

        $session->session_charge = $newSessionCharge;

        // session mode：delta 只在舊 session_charge 偏離 Rate 時修正偏差，不因時長產生新差額。
        // hour mode：時長變動的差額必須寫回課程總費用（與前端預覽一致）。
        if ($delta !== 0) {
            $newCharge = max(0, (int) ($sc->Charge ?? 0) + $delta);
            $sc->Charge = $newCharge;
            $sc->save();
        }
    }

    /**
     * Compute minutes between two HH:mm strings. Returns 0 on invalid input.
     * Does not handle cross-midnight; lesson times are expected within a single day.
     */
    private function minutesBetween(string $start, string $end): int
    {
        $s = substr($start, 0, 5);
        $e = substr($end, 0, 5);
        if (!preg_match('/^\d{2}:\d{2}$/', $s) || !preg_match('/^\d{2}:\d{2}$/', $e)) {
            return 0;
        }
        [$sh, $sm] = array_map('intval', explode(':', $s));
        [$eh, $em] = array_map('intval', explode(':', $e));
        $startMin = $sh * 60 + $sm;
        $endMin = $eh * 60 + $em;
        $diff = $endMin - $startMin;
        return $diff > 0 ? $diff : 0;
    }

    /**
     * 當 end_time 有異動時，同步更新同一堂次所有未作廢的評量記錄 EndTime。
     * 讓家長看到的評量表時間與實際上課時間一致。
     * 不論評量狀態（pending/submitted/approved）皆更新，因為這是事實性的時間修正。
     */
    private function syncLearningRecordTime(ClassSession $session, array $data): void
    {
        if (empty($data['end_time'])) {
            return;
        }
        $newEndTime = substr($data['end_time'], 0, 5);
        LearningRecord::where('ClassSessionID', $session->id)
            ->whereNull('VoidedAt')
            ->update(['EndTime' => $newEndTime]);
    }

    /**
     * Delegates to CourseLeaveCascadeService::appendTailAfterLeave() — the single
     * authoritative "auto-extend after leave/cancel" writer. This method used to
     * reimplement the same billable-vs-purchased check independently; since that
     * copy didn't know about the other one (used by /schedules/leave-by-session and
     * /schedules/retro-leave), a course could accumulate more non-cancelled dated
     * sessions than it actually purchased when leave/retro-leave/cancel actions were
     * mixed across both entry points (in-app director report, 2026-08-06).
     */
    private function tryExtendOnLeave(StudentClass $studentClass, ClassSession $leaveSession): ?ClassSession
    {
        $leaveDate = $leaveSession->SessionDate ? substr((string) $leaveSession->SessionDate, 0, 10) : null;
        if (!$leaveDate) {
            return null;
        }
        [, , $newSession] = \App\Services\CourseLeaveCascadeService::appendTailAfterLeave(
            (int) $studentClass->ID,
            $leaveDate,
            $leaveSession
        );

        return $newSession;
    }

    /**
     * TD-005: 更新 ClassSession status 時同步 active StudentSignIn.Status。
     * 只更新未作廢（VoidedAt IS NULL）的記錄。
     * ClassSession status → StudentSignIn status 對照：
     *   attended/completed → present | late → late | absent → absent
     */
    private function syncStudentSignInStatus(int $classSessionId, string $csStatus): void
    {
        $siStatus = match ($csStatus) {
            'attended', 'completed' => 'present',
            'late'                  => 'late',
            'absent'                => 'absent',
            default                 => null,
        };

        if ($siStatus === null) {
            return;
        }

        StudentSignIn::where('ClassSessionID', $classSessionId)
            ->whereNull('VoidedAt')
            ->update(['Status' => $siStatus, 'MDT' => now()]);
    }

    private function sessionUpdateResponse(ClassSession $session, string $message)
    {
        $session->refresh();
        $course = StudentClass::query()->where('ID', (int) $session->StudentClassID)->first();
        $leaveOutcome = $course && in_array(strtolower((string) ($session->Status ?? '')), ['leave', 'leave_adjusted'], true)
            ? CourseLeaveCascadeService::leaveOutcomeForCourse($course)
            : [];
        if (in_array(strtolower((string) ($session->Status ?? '')), [
            'attended', 'late', 'completed', 'trial', 'tutoring_attend',
        ], true)) {
            app(LearningRecordBackfillService::class)->ensureRequiredForAttendanceSession($session);
        }
        return response()->json([
            'message' => $message,
            ...$leaveOutcome,
            'session' => $this->sessionPayload($session),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionPayload(ClassSession $session): array
    {
        $session->refresh();
        return [
            'id'               => (int) $session->id,
            'student_class_id' => (int) $session->StudentClassID,
            'session_date'     => $session->SessionDate ? substr((string) $session->SessionDate, 0, 10) : null,
            'start_time'       => $session->StartTime ? substr((string) $session->StartTime, 0, 5) : null,
            'end_time'         => $session->EndTime ? substr((string) $session->EndTime, 0, 5) : null,
            'status'           => (string) ($session->Status ?? ''),
            'note'             => $session->Note,
            'session_charge'   => $session->session_charge !== null ? (int) $session->session_charge : null,
            'learning_record_status' => 'missing',
        ];
    }

    /**
     * @return array{start_time:string,end_time:string}|null
     */
    private function resolveProjectedMonthlySlot(StudentClass $studentClass, int $isoWeekday, ?string $requestedStart): ?array
    {
        $slots = $this->resolveProjectedMonthlySlotsForWeekday($studentClass, $isoWeekday);
        if (empty($slots)) {
            return null;
        }
        if (!$requestedStart) {
            return $slots[0];
        }
        foreach ($slots as $slot) {
            if (substr((string) $slot['start_time'], 0, 5) === substr($requestedStart, 0, 5)) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * @return array<int, array{start_time:string,end_time:string}>
     */
    private function resolveProjectedMonthlySlotsForWeekday(StudentClass $studentClass, int $isoWeekday): array
    {
        $globalDuration = max(30, (int) ($studentClass->SessionDuration ?? 120));
        $candidates = [
            ['week', 'time', null],
            ['week1', 'time1', 'duration1'],
            ['week2', 'time2', 'duration2'],
            ['week3', 'time3', 'duration3'],
            ['week4', 'time4', 'duration4'],
            ['week5', 'time5', 'duration5'],
            ['week6', 'time6', 'duration6'],
        ];

        $slots = [];
        foreach ($candidates as [$weekField, $timeField, $durationField]) {
            $weekday = (int) ($studentClass->{$weekField} ?? 0);
            if ($weekday !== $isoWeekday) {
                continue;
            }
            $time = trim((string) ($studentClass->{$timeField} ?? ''));
            if ($time === '') {
                continue;
            }
            $start = $this->normalizeTime($time);
            $duration = $durationField ? (int) ($studentClass->{$durationField} ?? 0) : 0;
            if ($duration < 30) {
                $duration = $globalDuration;
            }

            $slots[] = [
                'start_time' => $start,
                'end_time'   => $this->computeEndTime($start, $duration),
            ];
        }

        usort($slots, fn ($a, $b) => strcmp($a['start_time'], $b['start_time']));

        return $slots;
    }

    private function appendSessionNote($existing, string $suffix): string
    {
        $base = trim((string) ($existing ?? ''));
        if ($base === '') return $suffix;
        if (str_contains($base, $suffix)) return $base;
        return $base . '; ' . $suffix;
    }

    /**
     * @return array<int>
     */
    private function normalizeIds($raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map('intval', $raw), fn ($v) => $v > 0));
        }
        if (!is_string($raw)) {
            return [];
        }
        return array_values(array_filter(array_map('intval', explode(',', $raw)), fn ($v) => $v > 0));
    }

    /**
     * @param  array<int, string>  $dates
     * @return array<int, string>
     */
    private function normalizeDateArray(array $dates): array
    {
        $normalized = [];
        foreach ($dates as $date) {
            try {
                $normalized[] = Carbon::parse($date)->toDateString();
            } catch (\Throwable $e) {
                // validation already catches date format
            }
        }
        $normalized = array_values(array_unique($normalized));
        sort($normalized);
        return $normalized;
    }

    /**
     * @param  array<int, mixed>  $days
     * @return array<int, int>
     */
    private function normalizeWeekdayArray(array $days): array
    {
        $normalized = array_values(array_unique(array_map('intval', $days)));
        $normalized = array_values(array_filter($normalized, fn ($d) => $d >= 1 && $d <= 7));
        sort($normalized);
        return $normalized;
    }

    private function normalizeTime(string $time): string
    {
        $parsed = Carbon::createFromFormat('H:i', substr($time, 0, 5));
        return $parsed->format('H:i:s');
    }

    private function computeEndTime(string $startTime, int $durationMinutes): string
    {
        return Carbon::createFromFormat('H:i:s', $startTime)
            ->addMinutes($durationMinutes)
            ->format('H:i:s');
    }

    private function sessionEndedByEndTime(string $sessionDate, string $endTime, ?Carbon $now = null): bool
    {
        $now = $now ?: Carbon::now();
        $sessionEndAt = Carbon::parse($sessionDate . ' ' . $endTime);
        return $sessionEndAt->lte($now);
    }


    /**
     * Retry StudentClass::create by removing unknown columns for mixed schemas.
     *
     * @param  array<string, mixed>  $payload
     */
    private function createStudentClassResilient(array $payload): StudentClass
    {
        $attempts = 0;
        while ($attempts < 8) {
            try {
                return StudentClass::create($payload);
            } catch (QueryException $e) {
                if (!str_contains($e->getMessage(), 'Unknown column')) {
                    throw $e;
                }
                if (!preg_match("/Unknown column '([^']+)'/", $e->getMessage(), $m)) {
                    throw $e;
                }
                $badColumn = $m[1] ?? null;
                if (!$badColumn || !array_key_exists($badColumn, $payload)) {
                    throw $e;
                }
                unset($payload[$badColumn]);
                $attempts++;
            }
        }
        return StudentClass::create($payload);
    }

    /**
     * @return array{created: bool, approved: bool, deducted: bool}
     */
    private function syncApprovedLearningRecord(
        StudentClass $studentClass,
        ClassSession $classSession,
        int $teacherId,
        string $subjectName,
        ?int $approvedByUserId,
        bool $hasSessionDeductedColumn
    ): array {
        $created = false;
        $approved = false;
        $deducted = false;

        $record = LearningRecord::where('ClassSessionID', $classSession->id)->active()->first();
        if (!$record) {
            $payload = [
                'StudentClassID' => $studentClass->ID,
                'ClassSessionID' => $classSession->id,
                'TeacherID' => $teacherId,
                'CreatedByUserID' => $approvedByUserId ?: null,
                'Content' => '（系統自動核准）',
                'Subject' => $subjectName,
                'SessionDate' => $classSession->SessionDate,
                'StartTime' => $classSession->StartTime,
                'EndTime' => $classSession->EndTime,
                'Status' => 'approved',
                'ApprovedBy' => $approvedByUserId ?: null,
                'ApprovedAt' => now(),
            ];
            if ($hasSessionDeductedColumn) {
                $payload['SessionDeducted'] = false;
            }
            $record = LearningRecord::create($payload);
            $created = true;
            $approved = true;
        } else {
            if ((string) $record->Status !== 'approved') {
                $approved = true;
            }
            $record->StudentClassID = $studentClass->ID;
            $record->TeacherID = $teacherId;
            $record->Subject = $subjectName;
            $record->SessionDate = $classSession->SessionDate;
            $record->StartTime = $classSession->StartTime;
            $record->EndTime = $classSession->EndTime;
            $record->Status = 'approved';
            if (empty((string) $record->Content)) {
                $record->Content = '（系統自動核准）';
            }
            if ($approvedByUserId) {
                $record->ApprovedBy = $approvedByUserId;
                if (empty($record->CreatedByUserID)) {
                    $record->CreatedByUserID = $approvedByUserId;
                }
            }
            $record->ApprovedAt = now();
            $record->save();
        }

        $alreadyDeducted = $hasSessionDeductedColumn && (bool) $record->SessionDeducted;
        if (!$alreadyDeducted && $this->deductSessionForApprovedRecord($studentClass, $classSession->id)) {
            $deducted = true;
            if ($hasSessionDeductedColumn) {
                $record->SessionDeducted = true;
                $record->save();
            }
        }

        return [
            'created' => $created,
            'approved' => $approved,
            'deducted' => $deducted,
        ];
    }

    private function deductSessionForApprovedRecord(StudentClass $studentClass, int $classSessionId): bool
    {
        return SessionDeductionService::deductOnAttendance(
            $studentClass,
            null,
            $classSessionId > 0 ? $classSessionId : null
        );
    }

    /**
     * GET /api/v1/class-sessions/{id}/reassign-contract-targets
     *
     * Same-student + same-subject contracts (closed ones flagged `closed`) this session can move onto.
     */
    public function reassignContractTargets(Request $request, int $id)
    {
        $found = $this->findAccessibleSession($request, $id);
        if ($found instanceof \Symfony\Component\HttpFoundation\Response) {
            return $found;
        }
        [, $old] = $found;
        $targets = StudentClass::query()->where('StudentID', $old->getAttribute('StudentID'))
            ->where('SubjectID', $old->getAttribute('SubjectID'))
            ->where('ID', '!=', $old->getKey())
            ->orderBy('StartDate')
            ->get();

        return response()->json([
            'data' => $targets->map(fn (StudentClass $t) => ClassSessionContractReassigner::summary($t))->values(),
        ]);
    }

    /**
     * POST /api/v1/class-sessions/{id}/reassign-contract  (director + super_admin, campus-scoped)
     *
     * Moves one session (+ sign-in / ledger / learning record) to another contract of the same
     * student+subject, closed ones included (raw query builder: Eloquent guards block settled
     * contracts). Counters via SessionDeductionService; money untouched. `dry_run` rolls back after
     * computing before/after. Moving back is the undo.
     */
    public function reassignContract(Request $request, int $id)
    {
        $data = $request->validate([
            'new_student_class_id' => 'required|integer|min:1',
            'reason' => 'required|string|max:255',
            'dry_run' => 'sometimes|boolean',
        ]);
        $reason = trim($data['reason']);
        if ($reason === '') {
            return response()->json(['message' => '請填寫改派原因'], 422);
        }
        $dryRun = (bool) ($data['dry_run'] ?? false);

        $found = $this->findAccessibleSession($request, $id);
        if ($found instanceof \Symfony\Component\HttpFoundation\Response) {
            return $found;
        }

        $actorId = optional($request->attributes->get('auth_user'))->id;
        $result = app(ClassSessionContractReassigner::class)->reassign($id, (int) $data['new_student_class_id'], $reason, $actorId === null ? null : (int) $actorId, $dryRun);
        if (isset($result['locked'])) {
            return LearningRecordMutableOwnership::lockedResponse(); // #3824
        }
        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 422);
        }

        return response()->json([
            'message' => $dryRun ? '預覽' : '已將堂次改派至新課程合約，堂數已更新。',
            'dry_run' => $dryRun,
            'class_session_id' => (int) $id,
        ] + $result);
    }

    /**
     * POST /api/v1/class-sessions/{id}/substitute
     *
     * Atomic single-session substitute teacher: writes schedules (rescheduled + scheduled
     * with new teacher_id on same date/time) and updates LearningRecord.TeacherID.
     */
    public function substitute(Request $request, int $id)
    {
        $data = $request->validate([
            'substitute_teacher_id' => 'required|integer|exists:User,id',
            'reason' => 'nullable|string|max:255',
            // PRD f0cce4d5：合併「代課 + 換時間」選填參數（FR-001 ~ FR-005）
            // 三個欄位必須「全部填」或「全部省略」；填時以新時段為準做衝堂檢查與 DB 寫入。
            'new_date' => 'nullable|date_format:Y-m-d',
            'new_start_time' => ['nullable', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'new_end_time' => ['nullable', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
        ]);

        $role = $request->attributes->get('auth_role');
        if (!in_array($role, ['director', 'super_admin', 'admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $session = ClassSession::find($id);
        if (!$session) {
            return response()->json(['message' => '找不到該堂次'], 404);
        }

        $studentClass = StudentClass::find($session->StudentClassID);
        if (!$studentClass) {
            return response()->json(['message' => '找不到對應課程'], 404);
        }

        $student = Student::find($studentClass->StudentID);
        if (!$student) {
            return response()->json(['message' => '找不到該課程的學生資料'], 422);
        }
        $campusId = (int) ($student->CampusID ?? 0);
        if ($campusId <= 0) {
            return response()->json(['message' => '學生未設定分校，無法寫入排程與代課'], 422);
        }
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        if (!empty($campusIds) && !in_array($campusId, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $newTeacherId = (int) $data['substitute_teacher_id'];
        $oldTeacherId = (int) ($studentClass->TeacherID ?? 0);

        // #3824: only a real reassignment is refused; re-asserting the assessment's own teacher is a safe repair.
        if (($protectedLr = LearningRecordMutableOwnership::protectedRecordForSession((int) $session->id)) && (int) $protectedLr->TeacherID !== $newTeacherId) {
            return LearningRecordMutableOwnership::lockedResponse(); // rechecked under lock inside the transaction
        }

        try {
            $sessionDate = Carbon::parse($session->SessionDate)->toDateString();
        } catch (\Throwable $e) {
            return response()->json(['message' => '堂次日期格式無效'], 422);
        }
        $startTime = $this->normalizeSessionTimeForSchedule($session->StartTime ?? '');
        $endTime = $this->normalizeSessionTimeForSchedule($session->EndTime ?? '');
        if ($startTime === '' || $endTime === '') {
            return response()->json(['message' => '堂次起迄時間不完整，無法寫入排程'], 422);
        }

        // PRD f0cce4d5：解析選填換時參數
        // FR-003：三欄必須同填同省；FR-004：後續衝堂檢查以新時段為準
        $origSessionDate = $sessionDate;
        $origStartTime = $startTime;
        $origEndTime = $endTime;
        $newDateRaw = isset($data['new_date']) ? trim((string) $data['new_date']) : '';
        $newStartRaw = isset($data['new_start_time']) ? $this->normalizeSessionTimeForSchedule($data['new_start_time']) : '';
        $newEndRaw = isset($data['new_end_time']) ? $this->normalizeSessionTimeForSchedule($data['new_end_time']) : '';
        $rescheduleFields = [$newDateRaw, $newStartRaw, $newEndRaw];
        $rescheduleFilled = count(array_filter($rescheduleFields, static fn ($v) => $v !== ''));
        $hasReschedule = $rescheduleFilled === 3;
        if ($rescheduleFilled > 0 && $rescheduleFilled < 3) {
            return response()->json([
                'message' => '請同時填寫新日期與新開始時間，或收合換時間區塊',
                'errors' => [
                    'new_date' => $newDateRaw === '' ? ['請同時填寫新日期'] : [],
                    'new_start_time' => $newStartRaw === '' ? ['請同時填寫新開始時間'] : [],
                ],
            ], 422);
        }
        if ($hasReschedule) {
            try {
                $parsedNewDate = Carbon::parse($newDateRaw)->startOfDay();
            } catch (\Throwable $e) {
                return response()->json([
                    'message' => '新日期格式無效',
                    'errors' => ['new_date' => ['新日期格式無效']],
                ], 422);
            }
            // Historical corrections may adjust the time within the same
            // already-recorded lesson date. Moving a lesson to a different
            // past date remains blocked; that would be a historical rewrite,
            // not a bounded substitute bookkeeping correction.
            $isSameHistoricalSessionDate = $parsedNewDate->toDateString() === $origSessionDate;
            if ($parsedNewDate->lt(Carbon::today()) && !$isSameHistoricalSessionDate) {
                return response()->json([
                    'message' => '新日期不可為過去日期',
                    'errors' => ['new_date' => ['新日期不可為過去日期']],
                ], 422);
            }
            if (strcmp($newStartRaw, $newEndRaw) >= 0) {
                return response()->json([
                    'message' => '新時段的開始時間需早於結束時間',
                    'errors' => ['new_start_time' => ['新開始時間需早於新結束時間']],
                ], 422);
            }
            // 以新時段覆寫「生效」變數，後續衝堂檢查與 transaction 寫入皆以新時段為準
            $sessionDate = $parsedNewDate->toDateString();
            $startTime = $newStartRaw;
            $endTime = $newEndRaw;
        }

        $courseId = (int) $studentClass->ID;
        $studentId = (int) $studentClass->StudentID;
        $subject = DB::table('Subject')->where('id', $studentClass->SubjectID)->value('Subject_Name') ?? '';
        $classType = (string) ($studentClass->ClassType ?? 'one_on_one');
        $dayOfWeek = (int) Carbon::parse($sessionDate)->dayOfWeekIso;
        $roomId = (int) ($studentClass->room_id ?? 0);
        if ($roomId <= 0) {
            $roomId = null;
        }

        // Pre-read + conflict check OUTSIDE the DB transaction so a 409 does not commit partial writes.
        // 注意：existingRescheduled / existingScheduled 仍需以「原日期」搜尋，因為目前資料庫中這兩列
        // 仍位於舊日期；合併路徑會在 transaction 內將它們遷移至新日期再套用代課老師。
        $rescheduledCandidates = Schedule::where('student_course_id', $courseId)
            ->whereDate('schedule_date', $origSessionDate)
            ->where('status', 'rescheduled')
            ->orderByRaw('CASE WHEN teacher_id = ? THEN 0 ELSE 1 END', [$oldTeacherId])
            ->orderByDesc('id')
            ->get();
        $existingRescheduled = null;
        $existingScheduled = null;

        foreach ($rescheduledCandidates as $candidate) {
            $candidateId = (int) $candidate->id;
            // Same course/date may contain multiple ClassSession rows. Only reuse the
            // anchor whose paired substitute row matches this ClassSession start time.
            $candidateScheduled = Schedule::where('student_course_id', $courseId)
                ->whereDate('schedule_date', $origSessionDate)
                ->where('status', 'scheduled')
                ->where('original_schedule_id', $candidateId)
                ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [$origStartTime])
                ->first();
            if ($candidateScheduled) {
                $existingRescheduled = $candidate;
                $existingScheduled = $candidateScheduled;
                break;
            }

            if (substr((string) $candidate->start_time, 0, 5) === $origStartTime) {
                $existingRescheduled = $candidate;
                break;
            }

            // Historical repair path (#364/#108): chained reschedule -> substitute used to
            // leave a substitute scheduled row with NULL original_schedule_id. Pair that
            // exact-time ghost row with an existing anchor instead of creating a second
            // substitute and tripping the capacity guard.
            $ghostScheduled = Schedule::where('student_course_id', $courseId)
                ->whereDate('schedule_date', $origSessionDate)
                ->where('status', 'scheduled')
                ->whereNull('original_schedule_id')
                ->where('teacher_id', '!=', $oldTeacherId)
                ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [$origStartTime])
                ->orderByDesc('id')
                ->first();
            if ($ghostScheduled) {
                $existingRescheduled = $candidate;
                $existingScheduled = $ghostScheduled;
                break;
            }
        }

        if ($existingRescheduled && !$existingScheduled) {
            $rescheduledIdForGuard = (int) $existingRescheduled->id;
            if (!$existingScheduled) {
                // Historical repair path (#364/#108): chained reschedule -> substitute used to
                // leave a substitute scheduled row with NULL original_schedule_id. Treat it as
                // the existing row so the guard excludes it and the transaction can repair it.
                $existingScheduled = Schedule::where('student_course_id', $courseId)
                    ->whereDate('schedule_date', $origSessionDate)
                    ->where('status', 'scheduled')
                    ->whereNull('original_schedule_id')
                    ->where('teacher_id', '!=', $oldTeacherId)
                    ->where('start_time', $origStartTime)
                    ->orderByDesc('id')
                    ->first();
            }
        }

        if ($newTeacherId === $oldTeacherId) {
            return $this->restoreOriginalTeacherFromSubstitute(
                $request,
                $session,
                $studentClass,
                $existingRescheduled,
                $existingScheduled,
                $sessionDate,
                $startTime,
                $oldTeacherId,
                $data
            );
        }

        // PRD FR-003：候選池為 operator 管理分校的聯集（managed_campus_ids）。
        // 老師只要綁定任一 operator 管理分校即可被指派（跨分校協調）。
        $substituteSvc = app(SubstituteService::class);
        if (!$substituteSvc->teacherBoundToAny($newTeacherId, $campusIds)) {
            return response()->json([
                'message' => '所選老師未綁定任一您管理的分校',
                'errors' => ['substitute_teacher_id' => ['所選老師未綁定任一您管理的分校。']],
            ], 422);
        }
        $teacherHasThisCampus = UserCampus::where('UserID', $newTeacherId)
            ->where('CampusID', $campusId)
            ->exists();
        $crossCampus = !$teacherHasThisCampus;

        // Determine whether the session is in the past (already ended or attended-like status).
        // Past sessions bypass the capacity guard -- the class already happened, so swapping the
        // teacher on record is a bookkeeping correction, not a scheduling conflict.
        $sessionStatus = strtolower(trim((string) ($session->Status ?? 'scheduled')));
        $isPastSession = in_array($sessionStatus, ['attended', 'completed', 'late', 'absent'], true);
        if (!$isPastSession) {
            try {
                $sessionEndDt = Carbon::parse($sessionDate . ' ' . ($endTime ?: '23:59'));
                $isPastSession = $sessionEndDt->lte(Carbon::now());
            } catch (\Throwable $e) {
                $isPastSession = false;
            }
        }

        // PRD FR-004a：跨分校（物理不可分身）衝堂檢查
        // 過去堂次（補記）跳過；否則 operator 不可把一個已在別分校上課的老師再指派。
        // 只攔截「其他分校」的物理衝突；同分校衝堂由 ScheduleGuardService 於下方以 409 處理。
        if (!$isPastSession) {
            $excludeSchedIds = [];
            if ($existingScheduled) {
                $excludeSchedIds[] = (int) $existingScheduled->id;
            }
            $allBusy = $substituteSvc->detectCrossCampusConflict(
                $newTeacherId,
                $sessionDate,
                $startTime,
                $endTime,
                $excludeSchedIds,
                $studentId > 0 ? $studentId : null
            );
            $crossConflicts = array_values(array_filter(
                $allBusy,
                static fn ($c) => (int) ($c['campus_id']) > 0 && (int) $c['campus_id'] !== (int) $campusId
            ));
            if (!empty($crossConflicts)) {
                Log::info('[substitute] cross_campus_conflict', [
                    'class_session_id' => $id,
                    'new_teacher_id' => $newTeacherId,
                    'session_date' => $sessionDate,
                    'conflict_count' => count($crossConflicts),
                    'conflict_campus_ids' => array_values(array_unique(array_filter(array_map(
                        static fn ($conflict) => (int) ($conflict['campus_id']),
                        $crossConflicts
                    )))),
                ]);

                return response()->json([
                    'message' => '代課老師於此時段在其他分校另有課程，無法指派',
                    'conflicts' => $crossConflicts,
                    'cross_campus' => true,
                ], 422);
            }
        }

        if (!$isPastSession) {
            $guard = app(ScheduleGuardService::class);
            $conflicts = $guard->validateScheduleOccurrence([
                'teacher_id' => $newTeacherId,
                'class_type' => $classType,
                'trial_target_class_type' => $studentClass->trial_target_class_type,
                'room_id' => $roomId,
                'branch_id' => $campusId,
                'schedule_date' => $sessionDate,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'exclude_schedule_id' => $existingScheduled ? (int) $existingScheduled->id : null,
                // in-app #203：排除同一學生續約／雙軌佔用，避免代課自撞假 409
                'exclude_student_id' => $studentId > 0 ? $studentId : null,
            ]);
            if (!empty($conflicts)) {
                $conflictMessage = $conflicts[0]['message'] ?? '代課老師此時段與既有課程衝突';
                $overlapSummary = $conflicts[0]['overlap_summary'] ?? '';
                // ScheduleGuard now bakes overlap_summary into message; only append if missing.
                if ($overlapSummary !== '' && !str_contains($conflictMessage, $overlapSummary)) {
                    $conflictMessage .= '（' . $overlapSummary . '）';
                }

                Log::info('[substitute] capacity_conflict', [
                    'class_session_id' => $id,
                    'new_teacher_id' => $newTeacherId,
                    'session_date' => $sessionDate,
                    'conflict_count' => count($conflicts),
                    'conflict_types' => array_values(array_unique(array_filter(array_map(
                        static fn ($conflict) => $conflict['type'] ?? null,
                        $conflicts
                    )))),
                ]);

                return response()->json([
                    'message' => $conflictMessage,
                    'conflicts' => $conflicts,
                ], 409);
            }
        } else {
            $this->logSubstituteDiag('guard_bypassed_past_session', [
                'class_session_id' => $id,
                'session_status' => $sessionStatus,
                'session_date' => $sessionDate,
                'new_teacher_id' => $newTeacherId,
            ]);
        }

        $this->logSubstituteDiag('pre_transaction', [
            'class_session_id' => $id,
            'student_class_id' => $courseId,
            'student_id' => $studentId,
            'campus_id' => $campusId,
            'session_date' => $sessionDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'old_teacher_id' => $oldTeacherId,
            'new_teacher_id' => $newTeacherId,
            'class_type' => $classType,
            'room_id' => $roomId,
            'has_existing_rescheduled' => (bool) $existingRescheduled,
            'has_existing_scheduled' => (bool) $existingScheduled,
        ]);

        if (OccurrenceAssignmentService::enabledFor($campusId) && OccurrenceAssignmentService::onLeave($session)) {
            return response()->json(['message' => '此堂已請假，無法代課'], 422);
        }
        if ($hasReschedule && OccurrenceAssignmentService::enabledFor($campusId) && OccurrenceAssignmentService::onMakeup($session)) {
            return response()->json(['message' => '補課堂次無法同時代課與換時段'], 422);
        }

        try {
            return $this->runSubstituteTransaction(
                $request,
                $session,
                $data,
                $newTeacherId,
                $oldTeacherId,
                $sessionDate,
                $startTime,
                $endTime,
                $courseId,
                $studentId,
                $subject,
                $classType,
                $dayOfWeek,
                $campusId,
                $roomId,
                $existingRescheduled,
                $existingScheduled,
                $crossCampus,
                $hasReschedule,
                $origSessionDate,
                $origStartTime,
                $origEndTime
            );
        } catch (\App\Exceptions\SlotOccupiedException $e) {
            // #1161: moving the session onto an occupied slot is an expected domain
            // outcome (the active-only unique index would 1062). Surface an
            // actionable 422 rather than the generic 500 below.
            $this->logSubstituteDiag('slot_occupied', [
                'class_session_id' => $id,
                'conflict_session_id' => $e->conflictSessionId,
            ]);

            return response()->json($e->toResponseArray(), 422);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            return $e->getResponse(); // #3824 transactional recheck: keep the 409
        } catch (\Throwable $e) {
            $this->logSubstituteDiag('failed', [
                'class_session_id' => $id,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => config('app.debug') ? $e->getMessage() : '代課設定失敗，請稍後再試或聯絡管理員',
            ], 500);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function runSubstituteTransaction(
        Request $request,
        ClassSession $session,
        array $data,
        int $newTeacherId,
        int $oldTeacherId,
        string $sessionDate,
        string $startTime,
        string $endTime,
        int $courseId,
        int $studentId,
        string $subject,
        string $classType,
        int $dayOfWeek,
        int $campusId,
        ?int $roomId,
        $existingRescheduled,
        $existingScheduled,
        bool $crossCampus = false,
        bool $hasReschedule = false,
        string $origSessionDate = '',
        string $origStartTime = '',
        string $origEndTime = ''
    ) {
        return DB::transaction(function () use (
            $request, $session, $data, $newTeacherId, $oldTeacherId,
            $sessionDate, $startTime, $endTime, $courseId, $studentId, $subject,
            $classType, $dayOfWeek, $campusId, $roomId, $existingRescheduled, $existingScheduled,
            $crossCampus, $hasReschedule, $origSessionDate, $origStartTime, $origEndTime
        ) {
            LearningRecordMutableOwnership::assertTeacherChangeAllowed((int) $session->id, (int) $newTeacherId); // #3824
            $durationHours = 2;
            if ($startTime && $endTime) {
                $mins = abs(Carbon::parse($startTime)->diffInMinutes(Carbon::parse($endTime)));
                if ($mins > 0) {
                    $durationHours = max(0.5, round($mins / 60, 1));
                }
            }

            $v2 = OccurrenceAssignmentService::enabledFor($campusId);
            $identityRow = null;
            // Makeup (`extra`) sessions get no chain rows: a substitute row would be a second live row beside the makeup row.
            $makeup = $v2 && OccurrenceAssignmentService::onMakeup($session);

            $this->logSubstituteDiag('transaction_begin', [
                'class_session_id' => $session->id,
                'duration_hours' => $durationHours,
                'has_reschedule' => $hasReschedule,
                'orig_session_date' => $origSessionDate,
                'orig_start_time' => $origStartTime,
            ]);

            // PRD f0cce4d5 FR-005：合併代課+換時—在同一交易內先行遷移時間，再套用代課。
            // - 更新 ClassSession.{SessionDate,StartTime,EndTime}
            // - 同步對應 LearningRecord 的時間欄位
            // - 將原日期的 rescheduled / scheduled schedule 列遷移至新日期與新時段
            // 後續既有代課邏輯便可以新日期為 canonical 寫入，不需額外調整。
            if ($hasReschedule) {
                // #1161: reject a substitute+time move onto a slot already held by
                // another non-cancelled session (would 1062 under uq_class_session_slot).
                app(ClassSessionMaterializationService::class)->assertSlotAvailable(
                    $courseId,
                    $sessionDate,
                    $startTime,
                    (int) $session->id
                );

                // TD-076 B1 (flag on): one live identity row takes teacher + new slot, one log row.
                // Must run while the session still sits on its original slot.
                if ($v2 && !$makeup) {
                    $identityRow = app(OccurrenceAssignmentService::class)->assignTeacher(
                        $session,
                        $newTeacherId,
                        (int) ($request->attributes->get('auth_user')->id ?? 0),
                        'substitute',
                        ['date' => $sessionDate, 'start' => $startTime, 'end' => $endTime]
                    );
                }

                $session->SessionDate = $sessionDate;
                $session->StartTime = $startTime;
                $session->EndTime = $endTime;
                $session->save();

                $lrTableEarly = (new LearningRecord())->getTable();
                $lrUpdateEarly = [
                    'SessionDate' => $sessionDate,
                    'StartTime' => $startTime,
                    'EndTime' => $endTime,
                ];
                if (Schema::hasColumn($lrTableEarly, 'updated_at')) {
                    $lrUpdateEarly['updated_at'] = now();
                }
                DB::table($lrTableEarly)
                    ->where('ClassSessionID', $session->id)
                    ->update($lrUpdateEarly);

                if (!$v2 && $existingRescheduled) {
                    $existingRescheduled->schedule_date = $sessionDate;
                    $existingRescheduled->start_time = $startTime;
                    $existingRescheduled->end_time = $endTime;
                    $existingRescheduled->day_of_week = $dayOfWeek;
                    $existingRescheduled->duration_hours = $durationHours;
                    $existingRescheduled->save();
                }
                if (!$v2 && $existingScheduled) {
                    $existingScheduled->schedule_date = $sessionDate;
                    $existingScheduled->start_time = $startTime;
                    $existingScheduled->end_time = $endTime;
                    $existingScheduled->day_of_week = $dayOfWeek;
                    $existingScheduled->duration_hours = $durationHours;
                    $existingScheduled->save();
                }

                $this->logSubstituteDiag('reschedule_migrated', [
                    'class_session_id' => $session->id,
                    'new_session_date' => $sessionDate,
                    'new_start_time' => $startTime,
                    'new_end_time' => $endTime,
                    'migrated_rescheduled_id' => $existingRescheduled ? (int) $existingRescheduled->id : null,
                    'migrated_scheduled_id' => $existingScheduled ? (int) $existingScheduled->id : null,
                ]);
            }

            if ($v2):
                $identityRow ??= $makeup ? null : app(OccurrenceAssignmentService::class)->assignTeacher(
                    $session,
                    $newTeacherId,
                    (int) ($request->attributes->get('auth_user')->id ?? 0)
                );
                $rescheduledId = $identityRow ? (int) $identityRow->original_schedule_id : null;
                $scheduledId = $identityRow ? (int) $identityRow->id : null;
            else:
            // 1) Upsert rescheduled record (hides original teacher's slot)
            $existingRescheduledRow = $existingRescheduled ?: Schedule::where('student_course_id', $courseId)
                ->whereDate('schedule_date', $sessionDate)
                ->where('status', 'rescheduled')
                ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [$startTime])
                ->orderByRaw('CASE WHEN teacher_id = ? THEN 0 ELSE 1 END', [$oldTeacherId])
                ->orderByDesc('id')
                ->first();

            $rescheduledId = null;
            if (!$existingRescheduledRow) {
                // Align with ScheduleController@store: avoid ghost scheduled rows on the same course/date.
                DB::table('schedules')
                    ->where('student_course_id', $courseId)
                    ->whereDate('schedule_date', $sessionDate)
                    ->where('status', 'scheduled')
                    ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [$startTime])
                    ->delete();

                $rescheduled = Schedule::create([
                    'student_id' => $studentId,
                    'teacher_id' => $oldTeacherId > 0 ? $oldTeacherId : null,
                    'subject' => $subject,
                    'day_of_week' => $dayOfWeek,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'duration_hours' => $durationHours,
                    'class_type' => $classType,
                    'status' => 'rescheduled',
                    'type' => 'normal',
                    'deduction' => 0,
                    'branch_id' => $campusId,
                    'schedule_date' => $sessionDate,
                    'student_course_id' => $courseId,
                ]);
                $rescheduledId = $rescheduled->id;
            } else {
                $rescheduledId = $existingRescheduledRow->id;
            }

            $this->logSubstituteDiag('after_rescheduled', [
                'class_session_id' => $session->id,
                'rescheduled_id' => $rescheduledId,
                'reused_rescheduled_row' => (bool) $existingRescheduledRow,
            ]);

            // 2) Upsert substitute scheduled record (new teacher's slot on same day).
            // Prefer the row matching the ClassSession's current start_time to avoid operating
            // on a stale row from before a same-day reschedule.
            $existingScheduledRow = $existingScheduled;
            if (!$existingScheduledRow) {
                $existingScheduledRow = Schedule::where('student_course_id', $courseId)
                    ->whereDate('schedule_date', $sessionDate)
                    ->where('status', 'scheduled')
                    ->where('original_schedule_id', $rescheduledId)
                    ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [$startTime])
                    ->first();
            }

            if ($existingScheduledRow) {
                $rowUpdate = [
                    'teacher_id' => $newTeacherId,
                    'original_schedule_id' => $rescheduledId,
                ];
                // Sync time if the ClassSession was rescheduled after the substitute was set
                if ($existingScheduledRow->start_time !== $startTime) {
                    $rowUpdate['start_time'] = $startTime;
                }
                if ($existingScheduledRow->end_time !== $endTime) {
                    $rowUpdate['end_time'] = $endTime;
                }
                $existingScheduledRow->update($rowUpdate);
                $scheduledId = $existingScheduledRow->id;
                // Remove any other stale scheduled rows for this rescheduled anchor on the same date
                // (can accumulate when reschedule + substitute are done in different orders)
                Schedule::where('student_course_id', $courseId)
                    ->whereDate('schedule_date', $sessionDate)
                    ->where('status', 'scheduled')
                    ->where('original_schedule_id', $rescheduledId)
                    ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [$startTime])
                    ->where('id', '!=', $scheduledId)
                    ->delete();
            } else {
                $scheduled = Schedule::create([
                    'student_id' => $studentId,
                    'teacher_id' => $newTeacherId,
                    'subject' => $subject,
                    'day_of_week' => $dayOfWeek,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'duration_hours' => $durationHours,
                    'class_type' => $classType,
                    'status' => 'scheduled',
                    'type' => 'normal',
                    'deduction' => 1,
                    'branch_id' => $campusId,
                    'schedule_date' => $sessionDate,
                    'student_course_id' => $courseId,
                    'original_schedule_id' => $rescheduledId,
                ]);
                $scheduledId = $scheduled->id;
            }

            Schedule::where('student_course_id', $courseId)
                ->whereDate('schedule_date', $sessionDate)
                ->where('status', 'scheduled')
                ->whereNull('original_schedule_id')
                ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [$startTime])
                ->where('teacher_id', '!=', $oldTeacherId)
                ->where('id', '!=', $scheduledId)
                ->delete();

            $this->logSubstituteDiag('after_scheduled', [
                'class_session_id' => $session->id,
                'scheduled_id' => $scheduledId,
                'original_schedule_id' => $rescheduledId,
            ]);
            endif;

            // 3) Update LearningRecord if one exists for this session
            // Use query builder (not Eloquent active()+save) so production DB quirks
            // (casts, hydration, partial migrations) cannot break substitute after schedules succeed.
            $lrTable = (new LearningRecord())->getTable();
            $lrRowQuery = DB::table($lrTable)->where('ClassSessionID', $session->id);
            if (Schema::hasColumn($lrTable, 'VoidedAt')) {
                $lrRowQuery->whereNull('VoidedAt');
            }
            $lrRow = $lrRowQuery->first();

            $this->logSubstituteDiag('lr_lookup', [
                'class_session_id' => $session->id,
                'lr_table' => $lrTable,
                'voided_at_column' => Schema::hasColumn($lrTable, 'VoidedAt'),
                'learning_record_found' => (bool) $lrRow,
                'learning_record_id' => $lrRow ? (int) $lrRow->id : null,
            ]);

            $lrId = null;
            if ($lrRow) {
                $lrOldTeacher = (int) ($lrRow->TeacherID ?? 0);
                $lrStatus = (string) ($lrRow->Status ?? '');
                if ($lrOldTeacher !== $newTeacherId) {
                    \App\Services\AssessmentAuthorSnapshot::sendBackForRefill((int) $session->id, $newTeacherId, (int) ($request->attributes->get('auth_user')->id ?? 0) ?: null, $data['reason'] ?? '代課');
                    $lrUpdate = ['TeacherID' => $newTeacherId];
                    if (Schema::hasColumn($lrTable, 'updated_at')) {
                        $lrUpdate['updated_at'] = now();
                    }
                    DB::table($lrTable)->where('id', (int) $lrRow->id)->update($lrUpdate);

                    $authUser = $request->attributes->get('auth_user');
                    $changedBy = (int) ($authUser->id ?? 0);
                    if ($changedBy <= 0) {
                        $changedBy = (int) ($request->attributes->get('auth_teacher_id') ?? 0);
                    }

                    if (Schema::hasTable('learning_record_teacher_changes')) {
                        try {
                            $auditReason = $this->scrubSubstituteUtf8($data['reason'] ?? '代課') ?: '代課';
                            if (function_exists('mb_strlen') && mb_strlen($auditReason, 'UTF-8') > 255) {
                                $auditReason = mb_substr($auditReason, 0, 255, 'UTF-8');
                            } elseif (strlen($auditReason) > 255) {
                                $auditReason = substr($auditReason, 0, 255);
                            }
                            LearningRecordTeacherChange::create([
                                'learning_record_id' => (int) $lrRow->id,
                                'old_teacher_id' => $lrOldTeacher > 0 ? $lrOldTeacher : null,
                                'new_teacher_id' => $newTeacherId,
                                'changed_by' => $changedBy,
                                'reason' => $auditReason,
                            ]);
                        } catch (\Throwable $auditEx) {
                            Log::warning('substitute: learning_record_teacher_changes insert skipped', [
                                'learning_record_id' => $lrRow->id,
                                'message' => $auditEx->getMessage(),
                            ]);
                        }
                    }

                    if ($lrStatus === 'approved' && Schema::hasColumn('User', 'TeachingSessionCount')) {
                        try {
                            if ($lrOldTeacher > 0) {
                                User::where('id', $lrOldTeacher)
                                    ->where('TeachingSessionCount', '>', 0)
                                    ->decrement('TeachingSessionCount');
                            }
                            User::where('id', $newTeacherId)->increment('TeachingSessionCount');
                        } catch (\Throwable $kpiEx) {
                            Log::warning('substitute: TeachingSessionCount update skipped', [
                                'message' => $kpiEx->getMessage(),
                            ]);
                        }
                    }
                }
                $lrId = (int) $lrRow->id;
            }

            $this->logSubstituteDiag('pre_teacher_display', [
                'class_session_id' => $session->id,
                'learning_record_id' => $lrId,
            ]);

            $teacherRaw = TeacherProfileDirectory::nameFor((int) $newTeacherId, '');
            $teacherName = $this->scrubSubstituteUtf8($teacherRaw);
            if ($teacherName === '') {
                $teacherName = '未指派';
            }

            $reasonForLog = $this->scrubSubstituteUtf8($data['reason'] ?? null);
            $this->logSubstituteDiag('applied', [
                'class_session_id' => $session->id,
                'student_class_id' => $courseId,
                'old_teacher_id' => $oldTeacherId,
                'new_teacher_id' => $newTeacherId,
                'session_date' => $sessionDate,
                'reason' => $reasonForLog !== '' ? $reasonForLog : null,
            ]);

            $jsonFlags = JSON_UNESCAPED_UNICODE;
            if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
            }

            // PRD FR-010：同一交易建立家長代課通知
            $oldTeacherName = '';
            if ($oldTeacherId > 0) {
                $oldRaw = TeacherProfileDirectory::nameFor((int) $oldTeacherId, '');
                $oldTeacherName = $this->scrubSubstituteUtf8($oldRaw);
            }
            $studentName = $this->scrubSubstituteUtf8(DB::table('Student')->where('id', $studentId)->value('Name') ?? '');
            $authUser = $request->attributes->get('auth_user');
            $operatorId = (int) ($authUser->id ?? 0);

            $substituteSvc = app(SubstituteService::class);
            $notification = null;
            try {
                $notification = $substituteSvc->createParentNotification([
                    'campus_id' => $campusId,
                    'class_session_id' => $session->id,
                    'student_id' => $studentId,
                    'student_class_id' => $courseId,
                    'student_name' => $studentName,
                    'subject' => $subject,
                    'session_date' => $sessionDate,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'old_teacher_id' => $oldTeacherId,
                    'old_teacher_name' => $oldTeacherName,
                    'new_teacher_id' => $newTeacherId,
                    'new_teacher_name' => $teacherName,
                    'reason' => $reasonForLog ?: null,
                    'cross_campus' => $crossCampus,
                    'operator_id' => $operatorId,
                    // PRD f0cce4d5 FR-006 / FR-008：合併換時訊息 + Undo 還原所需原始時間
                    'operation_type' => $hasReschedule ? 'substitute_with_reschedule' : 'substitute',
                    'original_session_date' => $hasReschedule ? $origSessionDate : $sessionDate,
                    'original_start_time' => $hasReschedule ? $origStartTime : $startTime,
                    'original_end_time' => $hasReschedule ? $origEndTime : $endTime,
                ]);
            } catch (\Throwable $ne) {
                Log::error('[substitute] parent_notification_failed_rollback', [
                    'class_session_id' => $session->id,
                    'message' => $ne->getMessage(),
                ]);
                throw $ne;
            }

            Log::info('[substitute_applied]', [
                'class_session_id' => $session->id,
                'student_class_id' => $courseId,
                'student_id' => $studentId,
                'campus_id' => $campusId,
                'old_teacher_id' => $oldTeacherId,
                'new_teacher_id' => $newTeacherId,
                'cross_campus' => $crossCampus,
                'operator_id' => $operatorId,
                'notification_id' => $notification ? (int) $notification->id : null,
                'rescheduled_id' => $rescheduledId,
                'scheduled_id' => $scheduledId,
                // PRD f0cce4d5：稽核合併換時操作（第 9 節資安）
                'operation_type' => $hasReschedule ? 'substitute_with_reschedule' : 'substitute',
                'rescheduled_from_date' => $hasReschedule ? $origSessionDate : null,
                'rescheduled_from_start_time' => $hasReschedule ? $origStartTime : null,
                'rescheduled_to_date' => $hasReschedule ? $sessionDate : null,
                'rescheduled_to_start_time' => $hasReschedule ? $startTime : null,
            ]);

            $this->logSubstituteDiag('before_json_response', [
                'class_session_id' => $session->id,
                'rescheduled_id' => $rescheduledId,
                'scheduled_id' => $scheduledId,
                'learning_record_id' => $lrId,
                'teacher_name_len' => strlen($teacherName),
            ]);

            return response()->json([
                'message' => $hasReschedule ? '代課與換時設定完成' : '代課設定完成',
                'class_session_id' => $session->id,
                'substitute_teacher_id' => $newTeacherId,
                'substitute_teacher_name' => $teacherName,
                'rescheduled_schedule_id' => $rescheduledId,
                'scheduled_schedule_id' => $scheduledId,
                'learning_record_id' => $lrId,
                'notification_id' => $notification ? (int) $notification->id : null,
                'cross_campus' => $crossCampus,
                // PRD f0cce4d5：合併代課+換時的新時段資訊（純代課時回原時段）
                'operation_type' => $hasReschedule ? 'substitute_with_reschedule' : 'substitute',
                'rescheduled' => $hasReschedule,
                'session_date' => $sessionDate,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'original_session_date' => $hasReschedule ? $origSessionDate : $sessionDate,
                'original_start_time' => $hasReschedule ? $origStartTime : $startTime,
                'original_end_time' => $hasReschedule ? $origEndTime : $endTime,
                // 業界對齊 Gmail Undo Send：UI 倒數用 undo_window_seconds，後端 deadline 保留 60s grace
                'undo_window_seconds' => \App\Http\Controllers\SubstituteController::resolveUiUndoWindow(),
                'undo_deadline_ms' => (int) round(microtime(true) * 1000)
                    + \App\Http\Controllers\SubstituteController::resolveServerUndoWindow() * 1000,
            ], 200, [], $jsonFlags);
        });
    }

    /**
     * Revert a single-session substitute back to the contract teacher.
     *
     * This is intentionally not the time-limited "Undo" flow: directors need a
     * durable correction path when a session was assigned to the wrong teacher.
     */
    private function restoreOriginalTeacherFromSubstitute(
        Request $request,
        ClassSession $session,
        StudentClass $studentClass,
        $existingRescheduled,
        $existingScheduled,
        string $sessionDate,
        string $startTime,
        int $originalTeacherId,
        array $data
    ) {
        // #3780 P1: only a real substitute (its unresolved `substitute:<session id>` notice, written in the same
        // transaction as every substitute and resolved by undo/restore) may be restored. A teacher differing from
        // StudentClass.TeacherID can also be a history pin after a contract teacher change; restoring that would
        // rewrite LearningRecord.TeacherID / TeachingSessionCount for a session the former teacher really taught.
        if (!Notification::query()->where('SourceKey', 'substitute:' . (int) $session->id)->whereNull('ResolvedAt')->exists()) {
            return response()->json([
                'message' => '這堂不是代課堂次，不能「回正班老師」。這堂記錄的是當時實際上課的老師；如記錄有誤，請聯絡系統管理員。',
                'code' => 'not_a_substitute_session',
            ], 409);
        }

        return DB::transaction(function () use (
            $request,
            $session,
            $studentClass,
            $existingRescheduled,
            $existingScheduled,
            $sessionDate,
            $startTime,
            $originalTeacherId,
            $data
        ) {
            LearningRecordMutableOwnership::assertTeacherChangeAllowed((int) $session->id); // #3824
            $courseId = (int) $studentClass->ID;
            $authUser = $request->attributes->get('auth_user');
            $changedBy = (int) ($authUser->id ?? 0);

            $scheduledDeleted = 0;
            $rescheduledDeleted = 0;
            // TD-076 B1: one writer restores the live row; none at the slot (stranded legacy row) = legacy cleanup below.
            $restored = OccurrenceAssignmentService::handles($session, (int) Student::where('id', $studentClass->StudentID)->value('CampusID'))
                ? app(OccurrenceAssignmentService::class)->restoreContractTeacher($session, $changedBy ?: null)
                : null;
            if ($restored):
                $scheduledDeleted = 1; // counts "cleared", for the response only
            else:
            // in-app #276: durable restore must clear substitute_with_reschedule chains even when
            // notification payload times / TIME-column formatting diverge from ClassSession HH:mm.
            $matchDates = $this->substituteRestoreMatchDates($session, $sessionDate);
            $matchTimes = $this->substituteRestoreMatchTimes($session, $startTime);

            $scheduledRows = $this->findSubstituteScheduledRowsForRestore(
                $courseId,
                $originalTeacherId,
                $matchDates,
                $matchTimes,
                $existingScheduled
            );
            $anchorIds = [];
            foreach ($scheduledRows as $row) {
                $anchor = (int) ($row->original_schedule_id ?? 0);
                if ($anchor > 0) {
                    $anchorIds[] = $anchor;
                }
            }
            if ($existingRescheduled) {
                $anchorIds[] = (int) $existingRescheduled->id;
            }
            $anchorIds = array_values(array_unique(array_filter($anchorIds)));

            // Prefer delete-by-id so TIME/varchar formatting cannot strand the chain.
            $scheduledIds = array_values(array_unique(array_filter(array_map(
                static fn ($row) => (int) ($row->id ?? 0),
                $scheduledRows
            ))));
            $scheduledDeleted = 0;
            if (!empty($scheduledIds)) {
                $scheduledDeleted = Schedule::query()->whereIn('id', $scheduledIds)->delete();
            }

            $rescheduledDeleted = 0;
            if (!empty($anchorIds)) {
                // MySQL/MariaDB reject DELETE with subquery on same table (error 1093).
                $stillLinked = DB::table('schedules')
                    ->where('student_course_id', $courseId)
                    ->where('status', 'scheduled')
                    ->whereIn('original_schedule_id', $anchorIds)
                    ->pluck('original_schedule_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
                $safeAnchorIds = array_values(array_diff($anchorIds, $stillLinked));
                if (!empty($safeAnchorIds)) {
                    $rescheduledDeleted = Schedule::where('student_course_id', $courseId)
                        ->where('status', 'rescheduled')
                        ->whereIn('id', $safeAnchorIds)
                        ->delete();
                }
            }

            $remainingSubstitute = Schedule::where('student_course_id', $courseId)
                ->whereIn('schedule_date', $matchDates)
                ->where('status', 'scheduled')
                ->whereNotNull('original_schedule_id')
                ->where('teacher_id', '!=', $originalTeacherId)
                ->where(function ($q) use ($matchTimes) {
                    $this->constrainScheduleStartTimes($q, $matchTimes);
                })
                ->count();
            // Same-course same-date single-session fallback (production #276 shape).
            if ($remainingSubstitute > 0) {
                $sessionsThatDay = ClassSession::query()->where('StudentClassID', $courseId)
                    ->whereDate('SessionDate', $sessionDate)
                    ->where('Status', '!=', 'cancelled')
                    ->count();
                if ($sessionsThatDay <= 1) {
                    $leftoverIds = Schedule::where('student_course_id', $courseId)
                        ->whereDate('schedule_date', $sessionDate)
                        ->where('status', 'scheduled')
                        ->whereNotNull('original_schedule_id')
                        ->where('teacher_id', '!=', $originalTeacherId)
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->all();
                    $leftoverAnchors = Schedule::where('student_course_id', $courseId)
                        ->whereDate('schedule_date', $sessionDate)
                        ->where('status', 'scheduled')
                        ->whereNotNull('original_schedule_id')
                        ->where('teacher_id', '!=', $originalTeacherId)
                        ->pluck('original_schedule_id')
                        ->map(fn ($id) => (int) $id)
                        ->filter(fn ($id) => $id > 0)
                        ->unique()
                        ->values()
                        ->all();
                    if (!empty($leftoverIds)) {
                        $scheduledDeleted += Schedule::query()->whereIn('id', $leftoverIds)->delete();
                    }
                    if (!empty($leftoverAnchors)) {
                        $stillLinked = DB::table('schedules')
                            ->where('student_course_id', $courseId)
                            ->where('status', 'scheduled')
                            ->whereIn('original_schedule_id', $leftoverAnchors)
                            ->pluck('original_schedule_id')
                            ->map(fn ($id) => (int) $id)
                            ->all();
                        $safe = array_values(array_diff($leftoverAnchors, $stillLinked));
                        if (!empty($safe)) {
                            $rescheduledDeleted += Schedule::where('student_course_id', $courseId)
                                ->where('status', 'rescheduled')
                                ->whereIn('id', $safe)
                                ->delete();
                        }
                    }
                    $remainingSubstitute = Schedule::where('student_course_id', $courseId)
                        ->whereDate('schedule_date', $sessionDate)
                        ->where('status', 'scheduled')
                        ->whereNotNull('original_schedule_id')
                        ->where('teacher_id', '!=', $originalTeacherId)
                        ->count();
                }
            }

            if ($remainingSubstitute > 0) {
                Log::warning('[substitute_restore_original] aborted_stale_rows_remain', [
                    'class_session_id' => $session->id,
                    'student_class_id' => $courseId,
                    'remaining_substitute' => $remainingSubstitute,
                    'scheduled_deleted' => $scheduledDeleted,
                    'rescheduled_deleted' => $rescheduledDeleted,
                    'operator_id' => $changedBy ?: null,
                ]);
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'substitute_teacher_id' => ['無法清除此堂代課排程，請稍後再試或聯絡系統管理員。'],
                ]);
            }
            endif;

            $lrId = null;
            $lrTable = (new LearningRecord())->getTable();
            $lrRowQuery = DB::table($lrTable)->where('ClassSessionID', $session->id);
            if (Schema::hasColumn($lrTable, 'VoidedAt')) {
                $lrRowQuery->whereNull('VoidedAt');
            }
            $lrRow = $lrRowQuery->first();
            if ($lrRow) {
                $lrId = (int) $lrRow->id;
                $lrOldTeacher = (int) ($lrRow->TeacherID ?? 0);
                if ($lrOldTeacher !== $originalTeacherId) {
                    \App\Services\AssessmentAuthorSnapshot::sendBackForRefill((int) $session->id, $originalTeacherId, $changedBy > 0 ? $changedBy : null, $data['reason'] ?? '回復正班老師');
                    $lrUpdate = ['TeacherID' => $originalTeacherId];
                    if (Schema::hasColumn($lrTable, 'updated_at')) {
                        $lrUpdate['updated_at'] = now();
                    }
                    DB::table($lrTable)->where('id', $lrId)->update($lrUpdate);

                    if (Schema::hasTable('learning_record_teacher_changes')) {
                        try {
                            $auditReason = $this->scrubSubstituteUtf8($data['reason'] ?? '回復正班老師') ?: '回復正班老師';
                            LearningRecordTeacherChange::create([
                                'learning_record_id' => $lrId,
                                'old_teacher_id' => $lrOldTeacher > 0 ? $lrOldTeacher : null,
                                'new_teacher_id' => $originalTeacherId,
                                'changed_by' => $changedBy > 0 ? $changedBy : null,
                                'reason' => $auditReason,
                            ]);
                        } catch (\Throwable $auditEx) {
                            Log::warning('substitute_restore: learning_record_teacher_changes insert skipped', [
                                'learning_record_id' => $lrId,
                                'message' => $auditEx->getMessage(),
                            ]);
                        }
                    }

                    if (($lrRow->Status ?? '') === 'approved' && Schema::hasColumn('User', 'TeachingSessionCount')) {
                        try {
                            if ($lrOldTeacher > 0) {
                                User::where('id', $lrOldTeacher)
                                    ->where('TeachingSessionCount', '>', 0)
                                    ->decrement('TeachingSessionCount');
                            }
                            User::where('id', $originalTeacherId)->increment('TeachingSessionCount');
                        } catch (\Throwable $kpiEx) {
                            Log::warning('substitute_restore: TeachingSessionCount update skipped', [
                                'message' => $kpiEx->getMessage(),
                            ]);
                        }
                    }
                }
            }

            // Only resolve after schedules are confirmed cleared (in-app #276).
            if (Schema::hasTable('Notifications') && Schema::hasColumn('Notifications', 'ResolvedAt')) {
                DB::table('Notifications')
                    ->where('Type', 'substitute')
                    ->where('SourceType', 'ClassSession')
                    ->where('SourceID', $session->id)
                    ->whereNull('ResolvedAt')
                    ->update(['ResolvedAt' => now()]);
            }

            $cleared = ($scheduledDeleted + $rescheduledDeleted) > 0;
            Log::info('[substitute_restore_original]', [
                'class_session_id' => $session->id,
                'student_class_id' => $courseId,
                'restored_teacher_id' => $originalTeacherId,
                'scheduled_deleted' => $scheduledDeleted,
                'rescheduled_deleted' => $rescheduledDeleted,
                'substitute_cleared' => $cleared,
                'operator_id' => $changedBy ?: null,
            ]);

            return response()->json([
                'message' => '已回復正班老師',
                'class_session_id' => (int) $session->id,
                'restored_teacher_id' => $originalTeacherId,
                'substitute_cleared' => $cleared,
                'deleted_scheduled_count' => $scheduledDeleted,
                'deleted_rescheduled_count' => $rescheduledDeleted,
                'learning_record_id' => $lrId,
            ]);
        });
    }

    /**
     * @return list<string>
     */
    private function substituteRestoreMatchDates(ClassSession $session, string $sessionDate): array
    {
        $dates = [$sessionDate];
        try {
            $dates[] = Carbon::parse($session->SessionDate)->toDateString();
        } catch (\Throwable) {
        }
        $notif = Notification::query()->where('Type', 'substitute')
            ->where('SourceType', 'ClassSession')
            ->where('SourceID', $session->id)
            ->orderByDesc('id')
            ->first();
        $payload = is_array($notif?->Payload) ? $notif->Payload : [];
        foreach (['session_date', 'original_session_date'] as $key) {
            $raw = trim((string) ($payload[$key] ?? ''));
            if ($raw === '') {
                continue;
            }
            try {
                $dates[] = Carbon::parse($raw)->toDateString();
            } catch (\Throwable) {
            }
        }

        return array_values(array_unique(array_filter($dates)));
    }

    /**
     * @return list<string>
     */
    private function substituteRestoreMatchTimes(ClassSession $session, string $startTime): array
    {
        $times = [
            $this->normalizeSessionTimeForSchedule($startTime),
            $this->normalizeSessionTimeForSchedule($session->StartTime ?? ''),
        ];
        $notif = Notification::query()->where('Type', 'substitute')
            ->where('SourceType', 'ClassSession')
            ->where('SourceID', $session->id)
            ->orderByDesc('id')
            ->first();
        $payload = is_array($notif?->Payload) ? $notif->Payload : [];
        foreach (['start_time', 'original_start_time'] as $key) {
            $times[] = $this->normalizeSessionTimeForSchedule($payload[$key] ?? '');
        }

        return array_values(array_unique(array_filter($times)));
    }

    /**
     * @param  list<string>  $matchDates
     * @param  list<string>  $matchTimes
     * @return list<object>
     */
    private function findSubstituteScheduledRowsForRestore(
        int $courseId,
        int $originalTeacherId,
        array $matchDates,
        array $matchTimes,
        $existingScheduled
    ): array {
        $rows = [];
        if ($existingScheduled) {
            $rows[] = $existingScheduled;
        }
        if ($courseId <= 0 || $matchDates === []) {
            return $rows;
        }

        $query = Schedule::where('student_course_id', $courseId)
            ->whereIn('schedule_date', $matchDates)
            ->where('status', 'scheduled')
            ->whereNotNull('original_schedule_id')
            ->where('teacher_id', '!=', $originalTeacherId);
        if ($matchTimes !== []) {
            $query->where(function ($q) use ($matchTimes) {
                $this->constrainScheduleStartTimes($q, $matchTimes);
            });
        }
        foreach ($query->get() as $row) {
            $rows[] = $row;
        }

        // Deduplicate by id.
        $byId = [];
        foreach ($rows as $row) {
            $id = (int) ($row->id ?? 0);
            if ($id > 0) {
                $byId[$id] = $row;
            }
        }

        return array_values($byId);
    }

    /**
     * @param  list<string>  $matchTimes  HH:mm
     */
    private function constrainScheduleStartTimes($query, array $matchTimes): void
    {
        foreach ($matchTimes as $hhmm) {
            $query->orWhereRaw('SUBSTRING(CAST(start_time AS CHAR), 1, 5) = ?', [$hhmm])
                ->orWhere('start_time', $hhmm)
                ->orWhere('start_time', $hhmm.':00');
        }
    }

    /**
     * 代課除錯：寫入 laravel.log，grep `[substitute]` 即可依 step 對照中斷點。
     *
     * @param  array<string, mixed>  $context
     */
    private function logSubstituteDiag(string $step, array $context = []): void
    {
        try {
            Log::info('[substitute]', array_merge([
                'step' => $step,
                't' => now()->format('Y-m-d H:i:s.v'),
            ], $context));
        } catch (\Throwable $e) {
            // 不因 log 失敗影響代課
        }
    }

    /**
     * Strip / replace invalid UTF-8 so Monolog and JsonResponse do not throw.
     *
     * @param  mixed  $value
     */
    private function scrubSubstituteUtf8($value): string
    {
        if ($value === null) {
            return '';
        }
        $s = is_string($value) ? $value : (string) $value;
        if ($s === '') {
            return '';
        }
        if (function_exists('mb_scrub')) {
            return mb_scrub($s, 'UTF-8');
        }
        $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $s);

        return $converted !== false ? $converted : '';
    }

    /**
     * Normalize ClassSession StartTime/EndTime (time, datetime string, or Carbon) to HH:MM for schedules.
     */
    private function normalizeSessionTimeForSchedule($value): string
    {
        if ($value === null) {
            return '';
        }
        if ($value instanceof \Carbon\CarbonInterface) {
            return $value->format('H:i');
        }
        $s = trim((string) $value);
        if ($s === '') {
            return '';
        }
        if (preg_match('/(\d{1,2}):(\d{2})(?::\d{2})?/', $s, $m)) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }

        return $s;
    }

    private function resolveStudentGradeId(int $studentId): int
    {
        try {
            $gradeId = Student::where('id', $studentId)->value('GradeID');
            return $gradeId ? (int) $gradeId : 1;
        } catch (\Throwable $e) {
            return 1;
        }
    }

    /**
     * 主任／超管：近 N 天各授課老師已到班堂次的評量內容填寫率（LearningRecord.Progress 非空視為已填）。
     *
     * 代課堂次計入取代課老師名下（與行事曆顯示邏輯一致）。
     */
    public function directorTeacherLearningFillRates(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        if ($role !== 'director' && $role !== 'super_admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'branch_id' => 'required|integer|min:1',
            'days' => 'nullable|integer|min:1|max:31',
        ]);
        $branchId = (int) $validated['branch_id'];
        $days = (int) ($validated['days'] ?? 14);

        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        if ($role !== 'super_admin' && !empty($campusIds) && !in_array($branchId, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden: branch not accessible'], 403);
        }

        $end = Carbon::today();
        $start = $end->copy()->subDays($days - 1);

        $lrSubSql = '(SELECT lr_inner.* FROM `LearningRecord` lr_inner INNER JOIN (SELECT ClassSessionID, MAX(id) AS max_id FROM `LearningRecord` WHERE VoidedAt IS NULL GROUP BY ClassSessionID) lr_latest ON lr_inner.id = lr_latest.max_id)';

        // Same substitute resolution as buildClassSessionIndexQuery (#985 / #1822):
        // a joined derived table, not a per-row correlated MAX(sub2.id) subquery.
        $effectiveSessionRows = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->leftJoin(DB::raw(\App\Services\SubstituteScheduleService::substituteScheduleDerivedSql() . ' as sub_sched'), function ($join) {
                $join->on('sub_sched.student_course_id', '=', 'sc.ID')
                    ->whereColumn('sub_sched.schedule_date', 'cs.SessionDate')
                    ->whereColumn('sub_sched.start_time_hm', 'cs.StartTimeHM');
            })
            ->leftJoin(DB::raw($lrSubSql . ' AS lr'), 'lr.ClassSessionID', '=', 'cs.id')
            ->where('s.CampusID', $branchId)
            ->where('cs.SessionDate', '>=', $start->toDateString())
            ->where('cs.SessionDate', '<=', $end->toDateString())
            // The same registry drives attendance writes and the denominator. This
            // keeps trial/tutoring attendance included while absent/leave/cancelled
            // sessions never become evaluation work by accident.
            ->whereIn(DB::raw('LOWER(cs.Status)'), AttendanceStatus::requiresLogSessionStatuses())
            ->select([
                DB::raw('COALESCE(sub_sched.teacher_id, sc.TeacherID) AS teacher_id'),
                DB::raw('lr.id AS learning_record_id'),
                DB::raw('lr.Progress AS learning_record_progress'),
            ]);

        // Aggregate from a derived table so MySQL ONLY_FULL_GROUP_BY groups on
        // the already-resolved teacher_id value instead of re-validating the
        // underlying COALESCE(sc.TeacherID, sub_sched.teacher_id) columns.
        $rows = DB::table('effective_sessions')
            ->fromSub($effectiveSessionRows, 'effective_sessions')
            ->select([
                'teacher_id',
                DB::raw('COUNT(*) AS session_total'),
                DB::raw('SUM(CASE WHEN learning_record_id IS NOT NULL THEN 1 ELSE 0 END) AS recorded'),
                DB::raw('SUM(CASE WHEN learning_record_id IS NOT NULL AND TRIM(IFNULL(learning_record_progress, "")) != "" THEN 1 ELSE 0 END) AS filled'),
            ])
            ->groupBy('teacher_id')
            ->orderByDesc('session_total')
            ->get()
            ->filter(static function ($row) {
                return (int) ($row->teacher_id ?? 0) > 0;
            })
            ->values();

        $ids = $rows->pluck('teacher_id')->map(fn ($v) => (int) $v)->filter(fn ($id) => $id > 0)->unique()->values();
        $nameMap = $ids->isNotEmpty()
            ? DB::table('User')->whereIn('id', $ids->all())->pluck('Name', 'id')
            : collect();

        $teachers = $rows->map(static function ($row) use ($nameMap) {
            $tid = (int) ($row->teacher_id ?? 0);
            $total = (int) ($row->session_total ?? 0);
            $recorded = min($total, max(0, (int) ($row->recorded ?? 0)));
            $filled = (int) ($row->filled ?? 0);
            $pct = $total > 0 ? (int) round(100 * $filled / $total) : 0;
            $incomplete = max(0, $total - $filled);
            $missing = max(0, $total - $recorded);

            return [
                'teacher_id'               => $tid,
                'teacher_name'             => $nameMap->get($tid, '未知'),
                'sessions_attended'        => $total,
                'learning_records_present' => $recorded,
                'learning_records_filled'  => $filled,
                'missing_evaluations'      => $missing,
                'pending_evaluations'      => max(0, $incomplete - $missing),
                'fill_rate_pct'            => $pct,
            ];
        })->values();

        $sessionTotal = (int) $teachers->sum('sessions_attended');
        $filledTotal = (int) $teachers->sum('learning_records_filled');
        $followUpTeachers = $teachers->filter(static function (array $teacher): bool {
            return $teacher['sessions_attended'] >= 5
                && $teacher['learning_records_filled'] / max(1, $teacher['sessions_attended']) < 0.7;
        })->count();

        return response()->json([
            'start'                  => $start->toDateString(),
            'end'                    => $end->toDateString(),
            'days'                   => $days,
            'branch_id'               => $branchId,
            'teachers'                => $teachers,
            'overall'                 => [
                'sessions_attended' => $sessionTotal,
                'learning_records_present' => (int) $teachers->sum('learning_records_present'),
                'learning_records_filled' => $filledTotal,
                'pending' => max(0, $sessionTotal - $filledTotal),
                'missing_evaluations' => (int) $teachers->sum('missing_evaluations'),
                'pending_evaluations' => (int) $teachers->sum('pending_evaluations'),
                'fill_rate_pct' => $sessionTotal > 0 ? (int) round(100 * $filledTotal / $sessionTotal) : 0,
                'follow_up_teachers' => $followUpTeachers,
            ],
        ]);
    }

    /**
     * Diagnostic: log when effective session count != purchased for any course in response.
     * Only runs when perfflags.log_session_count_mismatch is true.
     * Logs only course_id, branch_id, and status counts (no PII).
     */
    private function logSessionCountMismatches(array $byClass, $request): void
    {
        static $nonQuota = ['cancelled', 'leave', 'leave_adjusted', 'excused'];
        $branchId = (int) ($request->input('branch_id') ?? 0);

        // #546/TD-018：一次撈齊各課程的 SessionCount，取代每課程一次 value() 查詢（N+1）。
        $courseIds = array_map('intval', array_keys($byClass));
        $purchasedMap = !empty($courseIds)
            ? DB::table('StudentClass')->whereIn('ID', $courseIds)->pluck('SessionCount', 'ID')
            : collect();

        foreach ($byClass as $courseId => $sessions) {
            $purchased = $purchasedMap[(int) $courseId] ?? null;
            if (!$purchased || $purchased <= 0) {
                continue;
            }
            $statusCounts = [];
            $effective = 0;
            foreach ($sessions as $s) {
                $st = strtolower($s->status ?? '');
                $statusCounts[$st] = ($statusCounts[$st] ?? 0) + 1;
                if (!in_array($st, $nonQuota, true)) {
                    $effective++;
                }
            }
            if ($effective !== (int) $purchased) {
                \Illuminate\Support\Facades\Log::channel('daily')->info('session_count_mismatch', [
                    'course_id' => $courseId,
                    'branch_id' => $branchId,
                    'purchased' => (int) $purchased,
                    'effective' => $effective,
                    'status_breakdown' => $statusCounts,
                ]);
            }
        }
    }
}

