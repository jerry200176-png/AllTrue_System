<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\LearningRecordFeedback;
use App\Models\LearningRecordTeacherComment;
use App\Models\LearningRecordTeacherChange;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\ApprovalSessionSyncService;
use App\Services\UserEngagementXpAwardService;
use App\Services\SessionDeductionService;
use App\Services\SubstituteScheduleService;
use App\Support\TeacherProfileDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class LearningRecordController extends Controller
{
    /**
     * 系統自動作廢（非人工作廢）的 LearningRecord VoidReason 白名單。
     * 這些都是「請假 / 狀態調整」cascade 產生的副作用，且在作廢當下已對應沖回堂數
     * （或該堂從未扣堂）。當對應 ClassSession 後續回到 fillable 狀態時，老師重新填寫
     * 應 resurrect 舊列、避免被 409 永久擋住。人工作廢（其他 VoidReason）維持 409，
     * 不覆寫管理員決策。
     *
     *  - '一般請假'            CourseLeaveCascadeService（整門/批次請假，#125/#495）
     *  - '由已上調整狀態'      ClassSessionController attended→scheduled/cancelled（已 reverseForSession 沖回，#146）
     *  - '補請假：已上課改請假' ClassSessionController::handleRetroLeaveTransition（已沖回）
     *  - '單堂標記請假'        ClassSessionController scheduled→leave（scheduled 未扣堂）
     */
    private const SYSTEM_RESURRECTABLE_VOID_REASONS = [
        '一般請假',
        '由已上調整狀態',
        '補請假：已上課改請假',
        '單堂標記請假',
    ];

    private function hasLearningRecordSessionDeductedColumn(): bool
    {
        static $hasColumn = null;
        if ($hasColumn === null) {
            $hasColumn = Schema::hasColumn('LearningRecord', 'SessionDeducted');
        }
        return $hasColumn;
    }

    /**
     * 評量列／詳情顯示用：依 schedules 單堂代課列解析「實際授課老師」User id，
     * 避免 LearningRecord.TeacherID 與代課不同步時主任端仍顯示正班老師。
     */
    private function resolveEffectiveInstructorUserId(LearningRecord $record): int
    {
        $record->loadMissing('studentClass');
        $sc = $record->studentClass;
        $contractTid = $sc ? (int) ($sc->TeacherID ?? 0) : 0;
        $studentClassId = (int) ($record->StudentClassID ?? 0);
        if ($studentClassId <= 0) {
            return (int) ($record->TeacherID ?? 0);
        }
        $eff = SubstituteScheduleService::effectiveInstructorUserId(
            $studentClassId,
            $record->SessionDate,
            $contractTid,
            $record->StartTime
        );

        return $eff > 0 ? $eff : (int) ($record->TeacherID ?? 0);
    }

    /**
     * Bug #495 / in-app #125：cascade-void 復原 helper。
     *
     * 條件已在呼叫端確認：
     *   1) `$voided->VoidReason` 屬 SYSTEM_RESURRECTABLE_VOID_REASONS（系統 cascade 作廢）
     *   2) `$classSession->Status` 屬 fillable 集合
     * 此處只負責 in-place 更新欄位，保留 LR id 與審計時間戳。
     */
    private function resurrectVoidedLearningRecord(
        LearningRecord $voided,
        StudentClass $studentClass,
        ClassSession $classSession,
        array $data
    ): LearningRecord {
        $authUser = request()->attributes->get('auth_user');
        $content = $data['Content'] ?? ($data['Progress'] ?? '') ?: '（評量表）';
        $sessionDate = $this->normalizeDateValue($classSession->SessionDate) ?? ($data['SessionDate'] ?? null);
        $startTime = $this->normalizeTimeValue($classSession->StartTime) ?? ($data['StartTime'] ?? null);
        $endTime = $this->normalizeTimeValue($classSession->EndTime) ?? ($data['EndTime'] ?? null);

        $lrTeacherId = SubstituteScheduleService::effectiveInstructorUserId(
            (int) $studentClass->ID,
            $classSession->SessionDate,
            (int) ($studentClass->TeacherID ?? 0),
            $classSession->StartTime
        );
        if ($lrTeacherId <= 0) {
            $lrTeacherId = (int) ($data['TeacherID'] ?? $voided->TeacherID ?? 0);
        }

        $voided->fill([
            'StudentClassID' => $studentClass->ID,
            'TeacherID' => $lrTeacherId,
            'CreatedByUserID' => $authUser ? (int) $authUser->id : $voided->CreatedByUserID,
            'Content' => $content,
            'AttachmentUrl' => $data['AttachmentUrl'] ?? null,
            'Subject' => $data['Subject'] ?? $voided->Subject,
            'SessionDate' => $sessionDate,
            'StartTime' => $startTime,
            'EndTime' => $endTime,
            'HomeworkStatus' => $data['HomeworkStatus'] ?? null,
            'QuizScore' => $data['QuizScore'] ?? null,
            'Progress' => $data['Progress'] ?? null,
            'NextHomework' => $data['NextHomework'] ?? null,
            'NextWeekTestScope' => $data['NextWeekTestScope'] ?? null,
            'Performance' => $data['Performance'] ?? null,
            'Comment' => $data['Comment'] ?? null,
            'Status' => 'pending',
            'VoidedAt' => null,
            'VoidedByUserID' => null,
            'VoidReason' => null,
            'ApprovedBy' => null,
            'ApprovedAt' => null,
            'ReviewNote' => null,
            'SessionDeducted' => false,
        ]);
        $voided->save();

        return $voided;
    }

    private function hydrateRecordForResponse(LearningRecord $record): LearningRecord
    {
        $record->loadMissing('studentClass.student');
        $record->student_name = $record->studentClass->student->name ?? '—';
        $record->student_id = $record->studentClass->student->id ?? null;
        $subjectId = $record->studentClass->SubjectID ?? null;
        $subjectName = $subjectId
            ? DB::table('Subject')->where('id', $subjectId)->value('Subject_Name')
            : null;
        $record->student_class_label = $subjectName ?? $record->Subject;
        $effTid = $this->resolveEffectiveInstructorUserId($record);
        $record->effective_teacher_id = $effTid;
        $record->teacher_name = TeacherProfileDirectory::nameFor((int) $effTid, '未指派');
        $comment = LearningRecordTeacherComment::where('learning_record_id', $record->id)->first();
        $record->teacher_comment = $comment ? $this->formatTeacherCommentForRecord($comment, [
            (int) $comment->author_user_id => DB::table('User')->where('id', $comment->author_user_id)->value('Name'),
        ]) : null;
        return $record;
    }

    public function index(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);

        if ($request->boolean('for_conflict_lookup') && $request->filled('class_session_id')) {
            return $this->learningRecordsForClassSessionConflictLookup($request, $role, $campusIds);
        }

        $query = LearningRecord::active();

        if ($role === 'teacher') {
            $teacherId = $request->attributes->get('auth_teacher_id');
            if (!$teacherId) {
                return response()->json(['message' => 'Teacher not linked'], 403);
            }
            $this->applyTeacherScopedLearningRecordConstraint($query, (int) $teacherId);
            $query->excludeCancelledClassSessionForTeacherList();
        }

        if ($role !== 'teacher' && ($request->filled('branch_id') || $request->filled('campus_id'))) {
            $requestedCampus = (int) ($request->input('branch_id') ?? $request->input('campus_id'));
            if ($role !== 'super_admin' && !empty($campusIds) && !in_array($requestedCampus, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden: branch not accessible'], 403);
            }
            if ($requestedCampus > 0) {
                $campusIds = [$requestedCampus];
            }
        }

        if ($request->filled('student_class_ids')) {
            $scIds = array_filter(array_map('intval', explode(',', $request->input('student_class_ids'))));
            if (!empty($scIds)) {
                $query->whereIn('StudentClassID', $scIds);
            }
        } elseif (!empty($campusIds) && $role !== 'teacher') {
            $studentIds = Student::whereIn('CampusID', $campusIds)->pluck('id');
            $classIds = StudentClass::whereIn('StudentID', $studentIds)->pluck('ID');
            if ($classIds->isNotEmpty()) {
                $query->whereIn('StudentClassID', $classIds);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if ($request->filled('status')) {
            $statusInput = $request->input('status');
            if (is_string($statusInput) && strpos($statusInput, ',') !== false) {
                $statuses = array_map('trim', explode(',', $statusInput));
                $statuses = array_filter($statuses, fn ($s) => $s !== '');
                if (!empty($statuses)) {
                    $query->whereIn('Status', $statuses);
                }
            } else {
                $query->where('Status', $statusInput);
            }
        }

        // Optional: only records that carry parent feedback (#138/#139).
        // The director "家長回饋待看" CTA needs the records WITH feedback regardless of the
        // default 90-day window or pagination page — counts/lists are otherwise derived from a
        // single client page and silently hide older feedback. `feedback=unread` narrows to the
        // viewer's unread state; `feedback=has` returns any record that has parent feedback.
        if ($request->filled('feedback')) {
            $fbMode = (string) $request->input('feedback');
            if (in_array($fbMode, ['has', 'unread'], true)) {
                $lrTbl = (new LearningRecord())->getTable();
                $query->whereExists(function ($sub) use ($lrTbl, $fbMode, $role) {
                    $sub->select(DB::raw(1))
                        ->from('learning_record_feedbacks as lrf')
                        ->whereColumn('lrf.learning_record_id', "{$lrTbl}.id");
                    if ($fbMode === 'unread') {
                        $col = $role === 'teacher'
                            ? 'lrf.last_read_by_teacher_at'
                            : 'lrf.last_read_by_director_at';
                        $sub->where(function ($q) use ($col) {
                            $q->whereNull($col)
                                ->orWhereColumn($col, '<', 'lrf.updated_at');
                        });
                    }
                });
            }
        }

        if ($request->filled('teacher_id')) {
            $filterTid = (int) $request->input('teacher_id');
            $lrTable = (new LearningRecord())->getTable();
            $query->where(function ($q) use ($filterTid, $lrTable) {
                $q->where('TeacherID', $filterTid)
                    ->orWhereExists(function ($sub) use ($filterTid, $lrTable) {
                        $sub->select(DB::raw(1))
                            ->from('ClassSession as cs')
                            ->join('schedules as s', function ($join) use ($filterTid) {
                                $join->on('s.student_course_id', '=', 'cs.StudentClassID')
                                    ->whereRaw('DATE(s.schedule_date) = DATE(cs.SessionDate)')
                                    ->whereRaw('SUBSTRING(s.start_time, 1, 5) = SUBSTRING(cs.StartTime, 1, 5)')
                                    ->where('s.status', '=', 'scheduled')
                                    ->whereNotNull('s.original_schedule_id')
                                    ->where('s.teacher_id', '=', $filterTid);
                            })
                            ->whereColumn('cs.id', "{$lrTable}.ClassSessionID");
                    });
            });
        }

        if ($request->filled('student_class_id')) {
            $query->where('StudentClassID', $request->input('student_class_id'));
        }

        if ($request->filled('student_name')) {
            $nameQ = $request->input('student_name');
            $matchingStudentIds = Student::where('name', 'like', "%{$nameQ}%")->pluck('id');
            $matchingClassIds = StudentClass::whereIn('StudentID', $matchingStudentIds)->pluck('ID');
            if ($matchingClassIds->isNotEmpty()) {
                $query->whereIn('StudentClassID', $matchingClassIds);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if ($request->filled('start_date')) {
            $query->where('SessionDate', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->where('SessionDate', '<=', $request->input('end_date'));
        }

        // Optional: only return records whose class session has already ended.
        // Useful when the product explicitly wants "post-class" slices.
        if ($request->boolean('only_due', false)) {
            $now = Carbon::now()->format('Y-m-d H:i:s');
            $query->whereRaw("CONCAT(SessionDate, ' ', COALESCE(EndTime, '23:59:59')) <= ?", [$now]);
        }

        // Optional: session start time has passed (director dashboard "待審" 自開課起可見).
        if ($request->boolean('only_started', false)) {
            $now = Carbon::now()->format('Y-m-d H:i:s');
            $query->whereRaw("CONCAT(SessionDate, ' ', COALESCE(StartTime, '00:00:00')) <= ?", [$now]);
        }

        $query->excludePausedCoursePendingReview();
        $query->excludeLeaveSessionPendingReview();

        // Optional: per-status totals over the full filtered scope (#139/#595). The eval page
        // derives its review-tab badges from a single client page, so the counts never matched the
        // director dashboard (server-side total). When `with_status_counts=1` (and no status filter),
        // return authoritative per-status counts so badges become a single source of truth.
        $statusCounts = null;
        if ($request->boolean('with_status_counts') && !$request->filled('status')) {
            $statusCounts = (clone $query)
                ->select('Status', DB::raw('COUNT(*) as aggregate'))
                ->groupBy('Status')
                ->pluck('aggregate', 'Status');
        }

        $defaultPerPage = config('perfflags.learning_records_default_per_page', 50);
        $maxPerPage = config('perfflags.learning_records_max_per_page', 200);
        $perPage = min((int) $request->input('per_page', $defaultPerPage), $maxPerPage);

        // Keyset (cursor) pagination branch: when `before_id` is supplied the caller is walking the
        // dataset backwards using a stable anchor (the smallest `id` seen so far). This avoids
        // the OFFSET "sliding window" problem when rows are inserted between calls
        // (see Slack Engineering: "Evolving API Pagination at Slack"). When this branch is taken
        // the `page` parameter is completely ignored — the two pagination modes never share state.
        //
        // Ordering is `id DESC` (not SessionDate DESC) because the anchor lives on `id` alone.
        // Using SessionDate as primary sort would break keyset semantics for back-dated rows
        // (e.g. director backdoor-creating an old record gets a new id with an old SessionDate),
        // where `min(id)` of a page would leave gaps. Callers (frontend loadAllRecords) re-sort
        // the merged set client-side by SessionDate, so API-side ordering has no UX impact.
        if ($request->filled('before_id')) {
            $beforeId = (int) $request->input('before_id');
            if ($beforeId > 0) {
                $query->where('id', '<', $beforeId);
            }

            $rows = $query->with('studentClass.student')
                ->orderBy('id', 'desc')
                ->limit($perPage)
                ->get();

            $this->decorateRecords($rows);

            $minId = $rows->isNotEmpty() ? (int) $rows->min('id') : null;
            return response()->json([
                'data'          => $rows->values(),
                'per_page'      => $perPage,
                'pagination'    => 'keyset',
                'before_id'     => $beforeId,
                'next_before_id' => $minId,
                'has_more'      => $rows->count() === $perPage,
            ]);
        }

        // Legacy OFFSET pagination path (used by the standard "load next page" flow).
        $records = $query->with('studentClass.student')
            ->orderBy('SessionDate', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        $collection = $records->getCollection();
        $this->decorateRecords($collection);

        if ($statusCounts !== null) {
            $payload = $records->toArray();
            $payload['status_counts'] = $statusCounts;

            return response()->json($payload);
        }

        return response()->json($records);
    }

    public function latestApprovedSummary(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'student_class_id' => 'required|integer|min:1',
            'session_date' => 'nullable|date',
            'exclude_id' => 'nullable|integer|min:1',
        ]);

        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);

        $query = LearningRecord::active()
            ->where('StudentClassID', (int) $data['student_class_id'])
            ->where('Status', 'approved');

        if (!empty($data['exclude_id'])) {
            $query->where('id', '!=', (int) $data['exclude_id']);
        }

        if (!empty($data['session_date'])) {
            $query->whereDate('SessionDate', '<=', $data['session_date']);
        }

        if ($role === 'teacher') {
            $teacherId = (int) ($request->attributes->get('auth_teacher_id') ?? 0);
            if ($teacherId <= 0) {
                return response()->json(['message' => 'Teacher not linked'], 403);
            }
            $this->applyTeacherScopedLearningRecordConstraint($query, $teacherId);
            $query->excludeCancelledClassSessionForTeacherList();
        } elseif ($role !== 'super_admin' && !empty($campusIds)) {
            $query->whereHas('studentClass.student', function ($q) use ($campusIds) {
                $q->whereIn('CampusID', $campusIds);
            });
        }

        /** @var LearningRecord|null $record */
        $record = $query
            ->with('studentClass.student')
            ->orderBy('SessionDate', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if (!$record) {
            return response()->json(['summary' => null]);
        }

        $subjectId = $record->studentClass->SubjectID ?? null;
        $subjectName = $subjectId
            ? DB::table('Subject')->where('id', $subjectId)->value('Subject_Name')
            : null;
        $regularTeacherId = (int) ($record->studentClass->TeacherID ?? 0);
        $effectiveTeacherId = $this->resolveEffectiveInstructorUserId($record);
        $teacherName = TeacherProfileDirectory::nameFor((int) $effectiveTeacherId, '未指派');

        return response()->json([
            'summary' => [
                'id' => (int) $record->id,
                'student_class_id' => (int) $record->StudentClassID,
                'session_date' => $record->SessionDate ? Carbon::parse($record->SessionDate)->toDateString() : null,
                'effective_teacher_id' => $effectiveTeacherId,
                'teacher_name' => $teacherName,
                'is_substitute' => $regularTeacherId > 0 && $effectiveTeacherId > 0 && $regularTeacherId !== $effectiveTeacherId,
                'subject' => $subjectName ?? $record->Subject,
                'homework_status' => (string) ($record->HomeworkStatus ?? ''),
                'quiz_score' => (string) ($record->QuizScore ?? ''),
                'progress' => (string) ($record->Progress ?? ''),
                'next_homework' => (string) ($record->NextHomework ?? ''),
                'next_week_test_scope' => (string) ($record->NextWeekTestScope ?? ''),
                'comment' => (string) ($record->Comment ?? ''),
            ],
        ]);
    }

    /**
     * 供前端 409 後精準拉取「該堂次」評量（含作廢列），不受分頁／active 清單限制。
     */
    private function learningRecordsForClassSessionConflictLookup(Request $request, string $role, array $campusIds): \Illuminate\Http\JsonResponse
    {
        $csid = (int) $request->input('class_session_id');
        if ($csid <= 0) {
            return response()->json(['message' => 'Invalid class_session_id'], 422);
        }

        $query = LearningRecord::query()->where('ClassSessionID', $csid);

        if ($role === 'teacher') {
            $teacherId = (int) ($request->attributes->get('auth_teacher_id') ?? 0);
            if ($teacherId <= 0) {
                return response()->json(['message' => 'Teacher not linked'], 403);
            }
            $this->applyTeacherScopedLearningRecordConstraint($query, $teacherId);
        } elseif ($role === 'super_admin') {
            // no extra campus filter
        } elseif (!empty($campusIds)) {
            $query->whereHas('studentClass.student', function ($q) use ($campusIds) {
                $q->whereIn('CampusID', $campusIds);
            });
        } else {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $rows = $query->with('studentClass.student')->orderBy('id', 'desc')->limit(5)->get();
        $this->decorateRecords($rows);

        return response()->json([
            'data' => $rows->values(),
            'pagination' => 'conflict_lookup',
            'for_conflict_lookup' => true,
        ]);
    }

    /**
     * 老師儀表板角標：pending／需修改評量數 + 今日堂次尚未建立評量筆數（與前端教學工作台加總規則一致）。
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function teacherPendingBadgeSummary(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        if ($role === 'super_admin') {
            return response()->json([
                'pending_learning_records'         => 0,
                'today_sessions_without_record' => 0,
                'total'                           => 0,
            ]);
        }
        if ($role !== 'teacher') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $teacherId = (int) $request->attributes->get('auth_teacher_id');
        if ($teacherId <= 0) {
            return response()->json(['message' => 'Teacher not linked'], 403);
        }

        $branchId = (int) $request->input('branch_id', 0);
        $campusIds = $request->attributes->get('auth_campus_ids', []);
        if ($branchId > 0 && !empty($campusIds) && !in_array($branchId, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden: branch not accessible'], 403);
        }

        $lrQuery = LearningRecord::active();
        $this->applyTeacherScopedLearningRecordConstraint($lrQuery, $teacherId);
        $lrQuery->whereIn('Status', ['pending', 'changes_requested']);
        $lrQuery->excludePausedCoursePendingReview();
        $lrQuery->excludeLeaveSessionPendingReview();
        $lrQuery->excludeCancelledClassSessionForTeacherList();
        if ($branchId > 0) {
            $lrQuery->whereHas('studentClass.student', fn ($stu) => $stu->where('CampusID', $branchId));
        }
        $pendingLr = (int) $lrQuery->count();
        $changesRequestedLr = (int) (clone $lrQuery)->where('Status', 'changes_requested')->count();

        $today = Carbon::today()->toDateString();
        $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString();
        $lrSubSql = '(SELECT lr_inner.* FROM `LearningRecord` lr_inner INNER JOIN (SELECT ClassSessionID, MAX(id) AS max_id FROM `LearningRecord` WHERE VoidedAt IS NULL GROUP BY ClassSessionID) lr_latest ON lr_inner.id = lr_latest.max_id)';

        $missingSessionBase = function () use ($teacherId, $branchId, $lrSubSql) {
            $query = DB::table('ClassSession as cs')
                ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
                ->join('Student as s', 's.id', '=', 'sc.StudentID')
                ->leftJoin('schedules as sub_sched', function ($join) {
                    $join->on('sub_sched.student_course_id', '=', 'sc.ID')
                        ->where('sub_sched.status', '=', 'scheduled')
                        ->whereNotNull('sub_sched.original_schedule_id')
                        ->whereRaw('DATE(sub_sched.schedule_date) = DATE(cs.SessionDate)')
                        ->whereRaw('SUBSTRING(sub_sched.start_time, 1, 5) = SUBSTRING(cs.StartTime, 1, 5)')
                        ->whereColumn('sub_sched.teacher_id', '!=', 'sc.TeacherID')
                        ->whereRaw('sub_sched.id = (
                            SELECT MAX(sub2.id)
                            FROM schedules sub2
                            WHERE sub2.student_course_id = sc.ID
                              AND sub2.status = "scheduled"
                              AND sub2.original_schedule_id IS NOT NULL
                              AND DATE(sub2.schedule_date) = DATE(cs.SessionDate)
                              AND SUBSTRING(sub2.start_time, 1, 5) = SUBSTRING(cs.StartTime, 1, 5)
                              AND sub2.teacher_id <> sc.TeacherID
                        )');
                })
                ->leftJoin(DB::raw($lrSubSql . ' AS lr'), 'lr.ClassSessionID', '=', 'cs.id')
                ->where(function ($outer) use ($teacherId) {
                    $outer->where(function ($inner) use ($teacherId) {
                        $inner->whereNull('sub_sched.teacher_id')
                            ->where('sc.TeacherID', $teacherId);
                    })->orWhere('sub_sched.teacher_id', $teacherId);
                })
                ->whereNull('lr.id');

            if ($branchId > 0) {
                $query->where('s.CampusID', $branchId);
            }

            return $query;
        };

        $missingToday = (int) $missingSessionBase()
            ->whereDate('cs.SessionDate', '=', $today)
            ->whereRaw('LOWER(cs.Status) IN ("scheduled","attended")')
            ->distinct()
            ->count(DB::raw('cs.id'));

        $pastWeekMissing = 0;
        if ($weekStart < $today) {
            $pastWeekMissing = (int) $missingSessionBase()
                ->whereBetween('cs.SessionDate', [$weekStart, Carbon::today()->subDay()->toDateString()])
                ->whereRaw('LOWER(cs.Status) IN ("attended","late")')
                ->distinct()
                ->count(DB::raw('cs.id'));
        }

        $total = $pendingLr + $missingToday + $pastWeekMissing;

        return response()->json([
            'pending_learning_records'         => $pendingLr,
            'changes_requested_learning_records' => $changesRequestedLr,
            'today_sessions_without_record' => $missingToday,
            'week_attended_sessions_without_record' => $pastWeekMissing,
            'total'                           => $total,
        ]);
    }

    /**
     * 老師個人進度摘要（僅 teacher）：
     * - expected_sessions: 區間內已到班/遲到堂次（含代課歸屬）；
     * - completed_sessions: 上述堂次中 latest LearningRecord.Progress 非空；
     * - by_day: 每日 expected/completed 與完成率；
     * - streak_days: 從結束日往前，連續「當日有 expected 且 100% 完成」天數。
     */
    public function teacherLearningProgressSummary(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        if ($role !== 'teacher') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $teacherId = (int) $request->attributes->get('auth_teacher_id');
        if ($teacherId <= 0) {
            return response()->json(['message' => 'Teacher not linked'], 403);
        }

        $validated = $request->validate([
            'branch_id' => 'nullable|integer|min:1',
            'days' => 'nullable|integer|min:1|max:31',
        ]);

        $branchId = (int) ($validated['branch_id'] ?? 0);
        $days = (int) ($validated['days'] ?? 7);
        $campusIds = $request->attributes->get('auth_campus_ids', []);
        if ($branchId > 0 && !empty($campusIds) && !in_array($branchId, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden: branch not accessible'], 403);
        }

        $end = Carbon::today();
        $start = $end->copy()->subDays($days - 1);

        $rows = $this->buildTeacherSessionProgressRowsQuery($teacherId, $start, $end, $branchId)
            ->select([
                DB::raw('DATE(cs.SessionDate) AS session_date'),
                DB::raw('COUNT(*) AS expected_total'),
                DB::raw('SUM(CASE WHEN lr.id IS NOT NULL AND TRIM(IFNULL(lr.Progress, "")) != "" THEN 1 ELSE 0 END) AS completed_total'),
            ])
            ->groupBy(DB::raw('DATE(cs.SessionDate)'))
            ->orderBy(DB::raw('DATE(cs.SessionDate)'))
            ->get();

        $dailyMap = [];
        foreach ($rows as $row) {
            $date = (string) ($row->session_date ?? '');
            if ($date === '') {
                continue;
            }
            $expected = (int) ($row->expected_total ?? 0);
            $completed = (int) ($row->completed_total ?? 0);
            $dailyMap[$date] = [
                'date' => $date,
                'expected_sessions' => $expected,
                'completed_sessions' => $completed,
                'completion_rate_pct' => $expected > 0 ? (int) round(100 * $completed / $expected) : 0,
            ];
        }

        $byDay = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $date = $cursor->toDateString();
            $byDay[] = $dailyMap[$date] ?? [
                'date' => $date,
                'expected_sessions' => 0,
                'completed_sessions' => 0,
                'completion_rate_pct' => 0,
            ];
            $cursor->addDay();
        }

        $expectedTotal = (int) collect($byDay)->sum('expected_sessions');
        $completedTotal = (int) collect($byDay)->sum('completed_sessions');
        $completionRate = $expectedTotal > 0 ? (int) round(100 * $completedTotal / $expectedTotal) : 0;

        $today = $end->toDateString();
        $todayRow = collect($byDay)->firstWhere('date', $today) ?? [
            'expected_sessions' => 0,
            'completed_sessions' => 0,
            'completion_rate_pct' => 0,
        ];

        $streakDays = 0;
        for ($i = count($byDay) - 1; $i >= 0; $i--) {
            $d = $byDay[$i];
            $expected = (int) ($d['expected_sessions'] ?? 0);
            $completed = (int) ($d['completed_sessions'] ?? 0);
            if ($expected <= 0 || $completed < $expected) {
                break;
            }
            $streakDays++;
        }

        return response()->json([
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'days' => $days,
            'branch_id' => $branchId > 0 ? $branchId : null,
            'summary' => [
                'expected_sessions' => $expectedTotal,
                'completed_sessions' => $completedTotal,
                'completion_rate_pct' => $completionRate,
                'today_expected_sessions' => (int) ($todayRow['expected_sessions'] ?? 0),
                'today_completed_sessions' => (int) ($todayRow['completed_sessions'] ?? 0),
                'today_completion_rate_pct' => (int) ($todayRow['completion_rate_pct'] ?? 0),
                'streak_days' => $streakDays,
            ],
            'by_day' => $byDay,
        ]);
    }

    private function buildTeacherSessionProgressRowsQuery(
        int $teacherId,
        Carbon $start,
        Carbon $end,
        int $branchId = 0
    ) {
        $lrSubSql = '(SELECT lr_inner.* FROM `LearningRecord` lr_inner INNER JOIN (SELECT ClassSessionID, MAX(id) AS max_id FROM `LearningRecord` WHERE VoidedAt IS NULL GROUP BY ClassSessionID) lr_latest ON lr_inner.id = lr_latest.max_id)';

        $query = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->leftJoin('schedules as sub_sched', function ($join) {
                $join->on('sub_sched.student_course_id', '=', 'sc.ID')
                    ->where('sub_sched.status', '=', 'scheduled')
                    ->whereNotNull('sub_sched.original_schedule_id')
                    ->whereRaw('DATE(sub_sched.schedule_date) = DATE(cs.SessionDate)')
                    ->whereRaw('SUBSTRING(sub_sched.start_time, 1, 5) = SUBSTRING(cs.StartTime, 1, 5)')
                    ->whereColumn('sub_sched.teacher_id', '!=', 'sc.TeacherID')
                    ->whereRaw('sub_sched.id = (
                        SELECT MAX(sub2.id)
                        FROM schedules sub2
                        WHERE sub2.student_course_id = sc.ID
                          AND sub2.status = "scheduled"
                          AND sub2.original_schedule_id IS NOT NULL
                          AND DATE(sub2.schedule_date) = DATE(cs.SessionDate)
                          AND SUBSTRING(sub2.start_time, 1, 5) = SUBSTRING(cs.StartTime, 1, 5)
                          AND sub2.teacher_id <> sc.TeacherID
                    )');
            })
            ->leftJoin(DB::raw($lrSubSql . ' AS lr'), 'lr.ClassSessionID', '=', 'cs.id')
            ->whereBetween(DB::raw('DATE(cs.SessionDate)'), [$start->toDateString(), $end->toDateString()])
            ->whereRaw('LOWER(cs.Status) IN ("attended","late")')
            ->where(function ($outer) use ($teacherId) {
                $outer->where(function ($inner) use ($teacherId) {
                    $inner->whereNull('sub_sched.teacher_id')
                        ->where('sc.TeacherID', $teacherId);
                })->orWhere('sub_sched.teacher_id', $teacherId);
            });

        if ($branchId > 0) {
            $query->where('s.CampusID', $branchId);
        }

        return $query;
    }

    /**
     * 與 GET learning-records 老師視角一致的 OR 範圍（代課可見性）。
     */
    private function applyTeacherScopedLearningRecordConstraint(Builder $query, int $teacherId): void
    {
        // 與 class-sessions 可見性一致：
        // 1) 若該堂存在代課（teacher_id != 正班老師），僅代課老師可見；
        // 2) 無代課時才由正班老師/評量歸屬老師可見。
        $lrTable = (new LearningRecord())->getTable();
        $query->where(function ($q) use ($teacherId, $lrTable) {
            $q->where(function ($baseScope) use ($teacherId, $lrTable) {
                $baseScope->where(function ($owner) use ($teacherId, $lrTable) {
                    $owner->where('TeacherID', $teacherId)
                        ->orWhereExists(function ($scSub) use ($teacherId, $lrTable) {
                            $scSub->select(DB::raw(1))
                                ->from('StudentClass as sc')
                                ->whereColumn('sc.ID', "{$lrTable}.StudentClassID")
                                ->where('sc.TeacherID', '=', $teacherId);
                        });
                })->whereNotExists(function ($sub) use ($lrTable) {
                    $sub->select(DB::raw(1))
                        ->from('ClassSession as cs')
                        ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
                        ->join('schedules as s', function ($join) {
                            $join->on('s.student_course_id', '=', 'cs.StudentClassID')
                                ->whereRaw('DATE(s.schedule_date) = DATE(cs.SessionDate)')
                                ->whereRaw('SUBSTRING(s.start_time, 1, 5) = SUBSTRING(cs.StartTime, 1, 5)')
                                ->where('s.status', '=', 'scheduled')
                                ->whereNotNull('s.original_schedule_id')
                                ->whereColumn('s.teacher_id', '!=', 'sc.TeacherID');
                        })
                        ->whereColumn('cs.id', "{$lrTable}.ClassSessionID");
                });
            })->orWhereExists(function ($sub) use ($teacherId, $lrTable) {
                $sub->select(DB::raw(1))
                    ->from('ClassSession as cs')
                    ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
                    ->join('schedules as s', function ($join) use ($teacherId) {
                        $join->on('s.student_course_id', '=', 'cs.StudentClassID')
                            ->whereRaw('DATE(s.schedule_date) = DATE(cs.SessionDate)')
                            ->whereRaw('SUBSTRING(s.start_time, 1, 5) = SUBSTRING(cs.StartTime, 1, 5)')
                            ->where('s.status', '=', 'scheduled')
                            ->whereNotNull('s.original_schedule_id')
                            ->whereColumn('s.teacher_id', '!=', 'sc.TeacherID')
                            ->where('s.teacher_id', '=', $teacherId);
                    })
                    ->whereColumn('cs.id', "{$lrTable}.ClassSessionID");
            });
        });
    }

    /**
     * Attach derived fields (student_name, subject label, teacher name, session_number) to a
     * collection of LearningRecords in a single pass, avoiding per-record N+1 queries.
     * Shared by the OFFSET and keyset pagination paths.
     */
    private function decorateRecords($collection): void
    {
        if ($collection->isEmpty()) {
            return;
        }

        $collection->loadMissing('studentClass.student');

        $subjectIds = $collection->pluck('studentClass.SubjectID')->filter()->unique()->values();
        $subjectMap = $subjectIds->isNotEmpty()
            ? DB::table('Subject')->whereIn('id', $subjectIds)->pluck('Subject_Name', 'id')
            : collect();

        $teacherIds = $collection->pluck('TeacherID')->filter()->unique()->values();
        $effTidByRecordId = [];
        foreach ($collection as $r) {
            $effTidByRecordId[(int) $r->id] = $this->resolveEffectiveInstructorUserId($r);
        }
        $effectiveIds = collect($effTidByRecordId)->filter(fn ($id) => $id > 0)->values();
        $allNameIds = $teacherIds->merge($effectiveIds)->unique()->values();
        $teacherNameMap = collect();
        if ($allNameIds->isNotEmpty()) {
            $teacherNameMap = collect(TeacherProfileDirectory::names($allNameIds->all()));
        }

        $sessionNumbers = static::batchSessionNumbers($collection);
        $feedbacks = LearningRecordFeedback::whereIn('learning_record_id', $collection->pluck('id'))
            ->get()
            ->keyBy('learning_record_id');
        // 批次載入回覆串（避免 N+1），依 feedback_id 分組。
        $repliesByFeedback = \App\Models\LearningRecordFeedbackReply::whereIn('feedback_id', $feedbacks->pluck('id'))
            ->orderBy('id')
            ->get()
            ->groupBy('feedback_id');
        $replyAuthorIds = $repliesByFeedback->flatten(1)->pluck('author_user_id')->filter()->unique()->values();
        $replyAuthorNames = $replyAuthorIds->isNotEmpty()
            ? DB::table('User')->whereIn('id', $replyAuthorIds)->pluck('Name', 'id')->toArray()
            : [];
        $teacherComments = LearningRecordTeacherComment::whereIn('learning_record_id', $collection->pluck('id'))
            ->get()
            ->keyBy('learning_record_id');
        $authorIds = $teacherComments->pluck('author_user_id')->filter()->unique()->values();
        $authorNameMap = $authorIds->isNotEmpty()
            ? DB::table('User')->whereIn('id', $authorIds)->pluck('Name', 'id')->toArray()
            : [];

        $collection->transform(function ($record) use ($subjectMap, $teacherNameMap, $sessionNumbers, $feedbacks, $teacherComments, $authorNameMap, $effTidByRecordId, $repliesByFeedback, $replyAuthorNames) {
            $record->student_name = $record->studentClass->student->name ?? '—';
            $record->student_id = $record->studentClass->student->id ?? null;
            $subjectId = $record->studentClass->SubjectID ?? null;
            $record->student_class_label = ($subjectId && isset($subjectMap[$subjectId]))
                ? $subjectMap[$subjectId]
                : $record->Subject;
            $effTid = $effTidByRecordId[(int) $record->id] ?? (int) ($record->TeacherID ?? 0);
            $record->effective_teacher_id = $effTid;
            $record->teacher_name = $teacherNameMap[$effTid] ?? '未指派';
            $record->session_number = $sessionNumbers[(int) $record->id] ?? null;
            $fb = $feedbacks->get((int) $record->id);
            $fbReplies = $fb ? ($repliesByFeedback->get((int) $fb->id) ?? collect()) : collect();
            $record->parent_feedback = $fb ? [
                'id' => (int) $fb->id,
                'content' => $fb->content,
                'updated_at' => optional($fb->updated_at)->toIso8601String(),
                'unread_for_teacher' => !$fb->last_read_by_teacher_at || $fb->last_read_by_teacher_at->lt($fb->updated_at),
                'unread_for_director' => !$fb->last_read_by_director_at || $fb->last_read_by_director_at->lt($fb->updated_at),
                'reply_count' => $fbReplies->count(),
                'replies' => $fbReplies->map(fn ($r) => [
                    'id' => (int) $r->id,
                    'author_role' => (string) $r->author_role,
                    'author_name' => $r->author_role !== 'parent' && $r->author_user_id
                        ? ($replyAuthorNames[$r->author_user_id] ?? null) : null,
                    'content' => $r->content,
                    'created_at' => optional($r->created_at)->toIso8601String(),
                ])->values()->all(),
            ] : null;
            $comment = $teacherComments->get((int) $record->id);
            $record->teacher_comment = $comment
                ? $this->formatTeacherCommentForRecord($comment, $authorNameMap)
                : null;
            return $record;
        });
    }

    private function formatTeacherCommentForRecord(LearningRecordTeacherComment $comment, array $authorNameMap): array
    {
        return [
            'id' => (int) $comment->id,
            'content' => $comment->content,
            'author_user_id' => (int) $comment->author_user_id,
            'author_name' => $authorNameMap[(int) $comment->author_user_id] ?? null,
            'updated_at' => optional($comment->updated_at)->toIso8601String(),
            'unread_for_teacher' => !$comment->last_read_by_teacher_at || $comment->last_read_by_teacher_at->lt($comment->updated_at),
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'StudentID' => 'required|integer',
            'ClassSessionID' => 'nullable|integer',
            'TeacherID' => 'required|integer',
            'Content' => 'nullable|string|max:65535',
            'AttachmentUrl' => 'nullable|string|max:255',
            'Subject' => 'nullable|string|max:32',
            'SessionDate' => 'nullable|date',
            'StartTime' => 'nullable|string|max:8',
            'EndTime' => 'nullable|string|max:8',
            'HomeworkStatus' => 'nullable|string|max:16',
            'QuizScore' => 'nullable|string|max:32',
            'Progress' => 'nullable|string|max:2000',
            'NextHomework' => 'nullable|string|max:2000',
            'NextWeekTestScope' => 'nullable|string|max:2000',
            'Performance' => 'nullable|string|max:16',
            'Comment' => 'nullable|string|max:2000',
        ]);

        return DB::transaction(function () use ($data) {
            $studentId = $data['StudentID'];
            $subjectName = $data['Subject'] ?? '數學';
            $subjectId = \Illuminate\Support\Facades\DB::table('Subject')->where('Subject_Name', 'like', "%$subjectName%")->value('id') ??
                \Illuminate\Support\Facades\DB::table('BaseData')->where('Name', '課程')->where('Val', 'like', "%$subjectName%")->value('id') ?? 1;

            $studentClass = null;
            $classSessionIdEarly = !empty($data['ClassSessionID']) ? (int) $data['ClassSessionID'] : 0;
            if ($classSessionIdEarly > 0) {
                $csEarly = ClassSession::find($classSessionIdEarly);
                if ($csEarly) {
                    $scCandidate = StudentClass::find($csEarly->StudentClassID);
                    if ($scCandidate && (int) $scCandidate->StudentID === (int) $studentId) {
                        $studentClass = $scCandidate;
                    }
                }
            }

            if (!$studentClass) {
                $studentClass = StudentClass::where('StudentID', $studentId)
                    ->where('TeacherID', $data['TeacherID'])
                    ->where('SubjectID', $subjectId)
                    ->where('Stop', 0)
                    ->first();
            }

            if (!$studentClass) {
                $studentClass = StudentClass::where('StudentID', $studentId)
                    ->where('TeacherID', $data['TeacherID'])
                    ->where('Stop', 0)
                    ->first();
            }

            if (!$studentClass) {
                $studentClass = StudentClass::where('StudentID', $studentId)->first();
            }

            if (!$studentClass) {
                return response()->json(['message' => '找不到學生的班級資料，無法建立紀錄'], 422);
            }

            $role = request()->attributes->get('auth_role');
            $campusIds = $role === 'super_admin' ? [] : request()->attributes->get('auth_campus_ids', []);

            if ($role !== 'teacher' && !empty($campusIds)) {
                $allowed = Student::whereIn('CampusID', $campusIds)
                    ->where('id', $studentId)
                    ->exists();
                if (!$allowed) {
                    return response()->json(['message' => 'Forbidden'], 403);
                }
            }

            $classSessionId = !empty($data['ClassSessionID']) ? (int) $data['ClassSessionID'] : 0;
            if ($classSessionId > 0) {
                $classSession = ClassSession::find($classSessionId);
                if (!$classSession || (int) $classSession->StudentClassID !== (int) $studentClass->ID) {
                    return response()->json(['message' => 'Session does not match class'], 422);
                }
            } else {
                $classSession = ClassSession::create([
                    'StudentClassID' => $studentClass->ID,
                    'SessionDate' => $data['SessionDate'] ?? now()->toDateString(),
                    'StartTime' => $data['StartTime'] ?? '00:00',
                    'EndTime' => $data['EndTime'] ?? '00:00',
                    'Status' => 'completed',
                ]);
                $classSessionId = $classSession->id;
            }

            if ($role === 'teacher') {
                $authTid = (int) (request()->attributes->get('auth_teacher_id') ?? 0);
                if (!$authTid || !$this->teacherAllowedToCreateLearningRecord($studentClass, $classSession, $authTid, (int) $data['TeacherID'])) {
                    return response()->json(['message' => 'Forbidden'], 403);
                }
            }

            $timeGateResponse = $this->validateSessionStartedForWrite(
                $classSession->SessionDate ?? ($data['SessionDate'] ?? null),
                $classSession->StartTime ?? ($data['StartTime'] ?? null)
            );
            if ($timeGateResponse) {
                return $timeGateResponse;
            }

            // 409：同一 ClassSession 僅能一筆 LearningRecord（unique）；作廢列仍占用鍵值。
            $rowForSession = LearningRecord::where('ClassSessionID', $classSessionId)
                ->orderByDesc('id')
                ->first();
            if ($rowForSession) {
                if ($rowForSession->VoidedAt) {
                    // Bug #495 / in-app #125 / #146：系統 cascade（請假 / 狀態調整）作廢的 LR，
                    // 若該 ClassSession 已被回復為 fillable 狀態 (attended/scheduled/completed/late)，
                    // 自動 resurrect 舊 LR，避免老師被永久 409 擋住。人工作廢（其他 VoidReason）維持原
                    // 409，不覆寫管理員決策。#146：attended→scheduled→attended 會以 '由已上調整狀態'
                    // 作廢且已沖回堂數，原本只認 '一般請假' → 老師無法重填，故改用系統白名單。
                    $fillableStatuses = ['attended', 'scheduled', 'completed', 'late'];
                    $csStatus = strtolower((string) ($classSession->Status ?? ''));
                    $isCascadeVoid = in_array(
                        (string) ($rowForSession->VoidReason ?? ''),
                        self::SYSTEM_RESURRECTABLE_VOID_REASONS,
                        true
                    );

                    if ($isCascadeVoid && in_array($csStatus, $fillableStatuses, true)) {
                        $resurrected = $this->resurrectVoidedLearningRecord(
                            $rowForSession,
                            $studentClass,
                            $classSession,
                            $data
                        );
                        return response()->json($this->hydrateRecordForResponse($resurrected), 200);
                    }

                    return response()->json([
                        'message' => '此堂評量已作廢，無法從此入口重複建立。請聯絡分校主任協助處理。',
                        'existing_id' => (int) $rowForSession->id,
                        'existing_record_id' => (int) $rowForSession->id,
                        'voided' => true,
                    ], 409);
                }

                $eid = (int) $rowForSession->id;

                return response()->json([
                    'message' => '此堂評量表已存在，請重新整理頁面後查看。',
                    'existing_id' => $eid,
                    'existing_record_id' => $eid,
                ], 409);
            }

            $authUser = request()->attributes->get('auth_user');
            $content = $data['Content'] ?? ($data['Progress'] ?? '') ?: '（評量表）';
            $sessionDate = $this->normalizeDateValue($classSession->SessionDate) ?? ($data['SessionDate'] ?? null);
            $startTime = $this->normalizeTimeValue($classSession->StartTime) ?? ($data['StartTime'] ?? null);
            $endTime = $this->normalizeTimeValue($classSession->EndTime) ?? ($data['EndTime'] ?? null);

            $lrTeacherId = SubstituteScheduleService::effectiveInstructorUserId(
                (int) $studentClass->ID,
                $classSession->SessionDate,
                (int) ($studentClass->TeacherID ?? 0),
                $classSession->StartTime
            );
            if ($lrTeacherId <= 0) {
                $lrTeacherId = (int) ($data['TeacherID'] ?? 0);
            }

            try {
                $record = LearningRecord::create([
                    'StudentClassID' => $studentClass->ID,
                    'ClassSessionID' => $classSessionId,
                    'TeacherID' => $lrTeacherId,
                    'CreatedByUserID' => $authUser ? (int) $authUser->id : null,
                    'Content' => $content,
                    'AttachmentUrl' => $data['AttachmentUrl'] ?? null,
                    'Subject' => $data['Subject'] ?? null,
                    // Keep LearningRecord strictly bound to ClassSession date/time.
                    'SessionDate' => $sessionDate,
                    'StartTime' => $startTime,
                    'EndTime' => $endTime,
                    'HomeworkStatus' => $data['HomeworkStatus'] ?? null,
                    'QuizScore' => $data['QuizScore'] ?? null,
                    'Progress' => $data['Progress'] ?? null,
                    'NextHomework' => $data['NextHomework'] ?? null,
                    'NextWeekTestScope' => $data['NextWeekTestScope'] ?? null,
                    'Performance' => $data['Performance'] ?? null,
                    'Comment' => $data['Comment'] ?? null,
                    'Status' => 'pending',
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // Race condition or duplicate submission: another record was inserted between
                // the active() check above and this INSERT. Return the existing record instead.
                if ($e->errorInfo[1] === 1062) {
                    $dup = LearningRecord::where('ClassSessionID', $classSessionId)->first();
                    $dupId = $dup ? $dup->id : null;
                    return response()->json([
                        'message' => '此堂評量表已存在，請重新整理頁面後查看。',
                        'existing_id' => $dupId,
                        // 舊版前端鍵名，與 active() 檢查路徑一致
                        'existing_record_id' => $dupId,
                    ], 409);
                }
                throw $e;
            }

            return response()->json($this->hydrateRecordForResponse($record), 201);
        });
    }

    public function update(Request $request, LearningRecord $learningRecord)
    {
        $data = $request->validate([
            'Content' => 'nullable|string|max:65535',
            'AttachmentUrl' => 'nullable|string|max:255',
            'Subject' => 'nullable|string|max:32',
            'SessionDate' => 'nullable|date',
            'StartTime' => 'nullable|string|max:8',
            'EndTime' => 'nullable|string|max:8',
            'HomeworkStatus' => 'nullable|string|max:16',
            'QuizScore' => 'nullable|string|max:32',
            'Progress' => 'nullable|string|max:2000',
            'NextHomework' => 'nullable|string|max:2000',
            'NextWeekTestScope' => 'nullable|string|max:2000',
            'Performance' => 'nullable|string|max:16',
            'Comment' => 'nullable|string|max:2000',
        ]);

        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        if ($role === 'teacher') {
            $teacherId = (int) ($request->attributes->get('auth_teacher_id') ?? 0);
            if (!$teacherId || !$this->teacherIsInstructorForLearningRecord($learningRecord, $teacherId)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        if (!empty($campusIds)) {
            $classIds = StudentClass::whereIn('StudentID', Student::whereIn('CampusID', $campusIds)->pluck('id'))
                ->pluck('ID');
            if (!$classIds->contains($learningRecord->StudentClassID)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $isDirector = in_array($role, ['director', 'super_admin', 'admin'], true);

        if (!$isDirector) {
            $isOwner = $role === 'teacher' &&
                $this->teacherIsInstructorForLearningRecord(
                    $learningRecord,
                    (int) ($request->attributes->get('auth_teacher_id') ?? 0)
                );
            $editableStatuses = $isOwner
                ? ['pending', 'rejected', 'changes_requested']
                : ['rejected', 'changes_requested'];
            if (!in_array($learningRecord->Status, $editableStatuses, true)) {
                return response()->json(['message' => 'Record is not editable'], 409);
            }
        }

        $boundSession = null;
        $boundSessionId = (int) ($learningRecord->ClassSessionID ?? 0);
        if ($boundSessionId > 0) {
            $boundSession = ClassSession::find($boundSessionId);
        }
        $timeGateResponse = $this->validateSessionStartedForWrite(
            $boundSession->SessionDate ?? $learningRecord->SessionDate ?? ($data['SessionDate'] ?? null),
            $boundSession->StartTime ?? $learningRecord->StartTime ?? ($data['StartTime'] ?? null)
        );
        if ($timeGateResponse) {
            return $timeGateResponse;
        }

        $learningRecord->Content = $data['Content'] ?? $learningRecord->Content;
        $learningRecord->AttachmentUrl = $data['AttachmentUrl'] ?? null;
        // SessionDate/StartTime/EndTime are derived from ClassSession and are intentionally
        // not writable from payload anymore (kept only as compatibility mirror columns).
        foreach (['Subject', 'HomeworkStatus', 'QuizScore', 'Progress', 'NextHomework', 'NextWeekTestScope', 'Performance', 'Comment'] as $key) {
            if (array_key_exists($key, $data)) {
                $learningRecord->$key = $data[$key];
            }
        }

        $this->syncRecordWithClassSession($learningRecord);

        if ($isDirector && $learningRecord->Status === 'approved') {
            // Director editing an approved record keeps it approved
        } else {
            $learningRecord->Status = 'pending';
            $learningRecord->ReviewNote = null;
            $learningRecord->ApprovedBy = null;
            $learningRecord->ApprovedAt = null;
        }
        $learningRecord->save();

        return response()->json($this->hydrateRecordForResponse($learningRecord));
    }

    public function updateTeacher(Request $request, LearningRecord $learningRecord)
    {
        $data = $request->validate([
            'TeacherID' => 'required|integer|exists:User,id',
            'reason' => 'nullable|string|max:255',
            'update_class' => 'nullable|boolean',
        ]);

        $role = $request->attributes->get('auth_role');
        if (!in_array($role, ['director', 'super_admin', 'admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        $studentClass = StudentClass::find($learningRecord->StudentClassID);
        $student = $studentClass ? Student::find($studentClass->StudentID) : null;
        $targetCampusId = (int) ($student->CampusID ?? 0);
        if ($targetCampusId <= 0) {
            return response()->json(['message' => 'Student campus not found'], 422);
        }

        if (!empty($campusIds) && !in_array($targetCampusId, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $newTeacherId = (int) $data['TeacherID'];
        $oldTeacherId = (int) ($learningRecord->TeacherID ?? 0);
        if ($newTeacherId === $oldTeacherId) {
            return response()->json([
                'message' => '授課老師未變更',
                'errors' => [
                    'TeacherID' => ['請選擇不同的授課老師。'],
                ],
            ], 422);
        }

        $teacherHasCampus = UserCampus::where('UserID', $newTeacherId)
            ->where('CampusID', $targetCampusId)
            ->exists();
        if (!$teacherHasCampus) {
            return response()->json([
                'message' => 'Teacher is not assigned to the target campus.',
                'errors' => [
                    'TeacherID' => ['所選老師未綁定此分校。'],
                ],
            ], 422);
        }

        $updateClass = (bool) ($data['update_class'] ?? false);

        return DB::transaction(function () use ($request, $learningRecord, $newTeacherId, $oldTeacherId, $data, $updateClass) {
            $learningRecord->TeacherID = $newTeacherId;
            $learningRecord->save();

            if ($updateClass) {
                $studentClass = StudentClass::find($learningRecord->StudentClassID);
                if ($studentClass && (int) $studentClass->TeacherID !== $newTeacherId) {
                    $studentClass->TeacherID = $newTeacherId;
                    $studentClass->save();
                }
            }

            if ((string) $learningRecord->Status === 'approved') {
                if ($oldTeacherId > 0) {
                    User::where('id', $oldTeacherId)
                        ->where('TeachingSessionCount', '>', 0)
                        ->decrement('TeachingSessionCount');
                }
                User::where('id', $newTeacherId)->increment('TeachingSessionCount');
            }

            $authUser = $request->attributes->get('auth_user');
            $changedBy = (int) ($authUser->id ?? 0);
            if ($changedBy <= 0) {
                $changedBy = (int) ($request->attributes->get('auth_teacher_id') ?? 0);
            }

            LearningRecordTeacherChange::create([
                'learning_record_id' => (int) $learningRecord->id,
                'old_teacher_id' => $oldTeacherId > 0 ? $oldTeacherId : null,
                'new_teacher_id' => $newTeacherId,
                'changed_by' => $changedBy,
                'reason' => $data['reason'] ?? null,
            ]);

            $response = $this->hydrateRecordForResponse($learningRecord->fresh());
            if ($updateClass) {
                $response['class_teacher_updated'] = true;
            }
            return response()->json($response);
        });
    }

    public function destroy(Request $request, LearningRecord $learningRecord)
    {
        $role = $request->attributes->get('auth_role');
        if (!in_array($role, ['director', 'super_admin', 'admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $learningRecord->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function approve(Request $request, LearningRecord $learningRecord)
    {
        $data = $request->validate([
            'DirectorID' => 'nullable|integer',
        ]);

        return DB::transaction(function () use ($learningRecord, $data) {
            if ($learningRecord->Status === 'approved') {
                return response()->json(['message' => 'Already approved'], 409);
            }

            if (!in_array($learningRecord->Status, ['pending', 'changes_requested'], true)) {
                return response()->json(['message' => 'Record cannot be approved'], 409);
            }

            $learningRecord->Status = 'approved';
            $learningRecord->ApprovedBy = $data['DirectorID'] ?? null;
            $learningRecord->ApprovedAt = now();
            $learningRecord->ReviewNote = null;
            $learningRecord->save();

            // 給授課老師加一堂課 (業績+1)
            User::where('id', $learningRecord->TeacherID)->increment('TeachingSessionCount');

            $sc = StudentClass::find($learningRecord->StudentClassID);
            if ($sc) {
                ApprovalSessionSyncService::syncOnApprove($learningRecord, $sc, (int) ($data['DirectorID'] ?? 0));
            }

            app(UserEngagementXpAwardService::class)->awardOnLearningRecordApprovedIfEligible($learningRecord->fresh());

            return response()->json($learningRecord->fresh());
        });
    }

    public function rollbackApproval(Request $request, LearningRecord $learningRecord)
    {
        $data = $request->validate([
            'DirectorID' => 'nullable|integer',
            'ReviewNote' => 'nullable|string|max:255',
        ]);

        return DB::transaction(function () use ($learningRecord, $data) {
            if ($learningRecord->Status !== 'approved') {
                return response()->json(['message' => 'Only approved records can be rolled back'], 409);
            }

            app(UserEngagementXpAwardService::class)->revokeLearningRecordApproved((int) $learningRecord->id);

            $learningRecord->Status = 'pending';
            $learningRecord->ReviewNote = $data['ReviewNote'] ?? null;
            $learningRecord->ApprovedBy = null;
            $learningRecord->ApprovedAt = null;
            $learningRecord->save();

            // Roll back previously-added approved session count.
            User::where('id', $learningRecord->TeacherID)
                ->where('TeachingSessionCount', '>', 0)
                ->decrement('TeachingSessionCount');

            $sc = StudentClass::find($learningRecord->StudentClassID);
            if ($sc) {
                ApprovalSessionSyncService::syncOnRollback($learningRecord, $sc, (int) ($data['DirectorID'] ?? 0));
            }

            return response()->json($learningRecord);
        });
    }

    /**
     * 主任一鍵審核：將目前篩選範圍內所有待審核評量表一次核准。
     * POST /api/v1/learning-records/batch-approve
     */
    public function batchApprove(Request $request)
    {
        $data = $request->validate([
            'DirectorID' => 'required|integer',
            'branch_id' => 'nullable|integer',
            'teacher_id' => 'nullable|integer',
        ]);

        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);

        if (!empty($data['branch_id'])) {
            if ($role !== 'super_admin' && !empty($campusIds) && !in_array((int) $data['branch_id'], $campusIds, true)) {
                return response()->json(['message' => 'Forbidden: branch not accessible'], 403);
            }
            $campusIds = [(int) $data['branch_id']];
        }

        $classIds = StudentClass::query();
        if (!empty($campusIds)) {
            $studentIds = Student::whereIn('CampusID', $campusIds)->pluck('id');
            $classIds = $classIds->whereIn('StudentID', $studentIds);
        }
        $classIds = $classIds->where(function ($q) {
            $q->where('Stop', 0)->orWhereNull('Stop');
        })->pluck('ID');

        $query = LearningRecord::active()
            ->whereIn('StudentClassID', $classIds)
            ->whereIn('Status', ['pending', 'changes_requested'])
            ->excludeLeaveSessionPendingReview();

        if (!empty($data['teacher_id'])) {
            $filterTid = (int) $data['teacher_id'];
            $lrTable = (new LearningRecord())->getTable();
            $query->where(function ($q) use ($filterTid, $lrTable) {
                $q->where('TeacherID', $filterTid)
                    ->orWhereExists(function ($sub) use ($filterTid, $lrTable) {
                        $sub->select(DB::raw(1))
                            ->from('ClassSession as cs')
                            ->join('schedules as s', function ($join) use ($filterTid) {
                                $join->on('s.student_course_id', '=', 'cs.StudentClassID')
                                    ->whereRaw('DATE(s.schedule_date) = DATE(cs.SessionDate)')
                                    ->where('s.status', '=', 'scheduled')
                                    ->whereNotNull('s.original_schedule_id')
                                    ->where('s.teacher_id', '=', $filterTid);
                            })
                            ->whereColumn('cs.id', "{$lrTable}.ClassSessionID");
                    });
            });
        }

        $records = $query->get();
        $directorId = (int) $data['DirectorID'];
        $approved = 0;

        return DB::transaction(function () use ($records, $directorId, &$approved) {
            foreach ($records as $learningRecord) {
                $learningRecord->Status = 'approved';
                $learningRecord->ApprovedBy = $directorId;
                $learningRecord->ApprovedAt = now();
                $learningRecord->ReviewNote = null;
                $learningRecord->save();

                User::where('id', $learningRecord->TeacherID)->increment('TeachingSessionCount');

                $sc = StudentClass::find($learningRecord->StudentClassID);
                if ($sc) {
                    ApprovalSessionSyncService::syncOnApprove($learningRecord, $sc, $directorId);
                }

                app(UserEngagementXpAwardService::class)->awardOnLearningRecordApprovedIfEligible($learningRecord->fresh());

                $approved++;
            }
            return response()->json(['message' => "已核准 {$approved} 筆評量", 'approved' => $approved]);
        });
    }

    public function batchReject(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'ReviewNote' => 'nullable|string|max:255',
            'DirectorID' => 'nullable|integer',
        ]);

        $role = $request->attributes->get('auth_role');
        if ($role === 'teacher') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $records = LearningRecord::active()
            ->whereIn('id', $data['ids'])
            ->whereIn('Status', ['pending', 'changes_requested'])
            ->get();

        $rejected = 0;
        DB::transaction(function () use ($records, $data, &$rejected) {
            foreach ($records as $record) {
                $record->Status = 'rejected';
                $record->ReviewNote = $data['ReviewNote'] ?? '';
                $record->ApprovedBy = $data['DirectorID'] ?? null;
                $record->ApprovedAt = null;
                $record->save();
                $rejected++;
            }
        });

        return response()->json(['message' => "已退回 {$rejected} 筆評量", 'rejected' => $rejected]);
    }

    public function batchRequestChanges(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'ReviewNote' => 'nullable|string|max:255',
            'DirectorID' => 'nullable|integer',
        ]);

        $role = $request->attributes->get('auth_role');
        if ($role === 'teacher') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $records = LearningRecord::active()
            ->whereIn('id', $data['ids'])
            ->whereIn('Status', ['pending', 'changes_requested'])
            ->get();

        $changed = 0;
        DB::transaction(function () use ($records, $data, &$changed) {
            foreach ($records as $record) {
                $record->Status = 'changes_requested';
                $record->ReviewNote = $data['ReviewNote'] ?? '';
                $record->ApprovedBy = $data['DirectorID'] ?? null;
                $record->ApprovedAt = null;
                $record->save();
                $changed++;
            }
        });

        return response()->json(['message' => "已標記 {$changed} 筆需修改", 'changed' => $changed]);
    }

    public function backdoorApprove(Request $request)
    {
        $data = $request->validate([
            'StudentClassID' => 'required|integer',
            'TeacherID' => 'required|integer',
            'DirectorID' => 'required|integer',
            'SessionDate' => 'required|date'
        ]);
        $today = now()->toDateString();
        if ($data['SessionDate'] > $today) {
            return response()->json(['message' => '補登僅限今天或已過的日期，今天以後的課尚不需補登'], 422);
        }

        return DB::transaction(function () use ($data) {
            $studentClass = StudentClass::find($data['StudentClassID']);
            if (!$studentClass) {
                return response()->json(['message' => '找不到學生課程'], 422);
            }

            $expectedDateSet = $this->buildBackfillExpectedDateSet($studentClass);
            $classSession = $this->findEffectiveClassSessionForDate($studentClass->ID, $data['SessionDate']);
            if (!$classSession) {
                if (!$this->canBackfillCreateClassSession($data['SessionDate'], $expectedDateSet)) {
                    return response()->json([
                        'message' => '該日期沒有可補登的上課堂次（可能為請假/停課），未建立評量表',
                    ], 422);
                }
                $slot = $this->resolveBackfillSlotForDate($studentClass, $data['SessionDate']);
                $classSession = ClassSession::create([
                    'StudentClassID' => $studentClass->ID,
                    'SessionDate' => $data['SessionDate'],
                    'StartTime' => $slot['start'],
                    'EndTime' => $slot['end'],
                    'Status' => $data['SessionDate'] <= now()->toDateString() ? 'completed' : 'scheduled',
                    'Note' => 'auto-created by backfill',
                ]);
            }

            $existing = LearningRecord::where('ClassSessionID', $classSession->id)->active()->first();
            if ($existing) {
                if ($existing->Status !== 'approved') {
                    $existing->Status = 'approved';
                    $existing->ApprovedBy = $data['DirectorID'];
                    $existing->ApprovedAt = now();
                    $existing->TeacherID = (int) ($data['TeacherID'] ?? $existing->TeacherID);
                    $this->syncRecordWithClassSession($existing, $classSession, (int) ($data['TeacherID'] ?? 0));
                    $existing->save();
                    app(UserEngagementXpAwardService::class)->awardOnLearningRecordApprovedIfEligible($existing->fresh());
                } else {
                    $this->syncRecordWithClassSession($existing, $classSession, (int) ($data['TeacherID'] ?? 0));
                }

                return response()->json($existing->fresh(), 201);
            }

            $recordPayload = [
                'StudentClassID' => $studentClass->ID,
                'ClassSessionID' => $classSession->id,
                'TeacherID' => $data['TeacherID'],
                'CreatedByUserID' => $data['DirectorID'],
                'Content' => '（系統補登/扣除堂數）',
                'Subject' => $studentClass->Subject ?? '系統扣堂',
                'SessionDate' => $this->normalizeDateValue($classSession->SessionDate),
                'StartTime' => $this->normalizeTimeValue($classSession->StartTime),
                'EndTime' => $this->normalizeTimeValue($classSession->EndTime),
                'Status' => 'approved',
                'ApprovedBy' => $data['DirectorID'],
                'ApprovedAt' => now(),
            ];
            // Some deployments may not have this optional column yet.
            if (\Illuminate\Support\Facades\Schema::hasColumn('LearningRecord', 'ExcludeFromSubjectCount')) {
                $recordPayload['ExcludeFromSubjectCount'] = 1; // 補登空白評量(單一課程) 不算入老師科目數
            }
            $record = LearningRecord::create($recordPayload);
            app(UserEngagementXpAwardService::class)->awardOnLearningRecordApprovedIfEligible($record->fresh());

            return response()->json($record->fresh(), 201);
        });
    }

    /**
     * 主任一鍵補登：依多個上課日期一次建立多筆已核准空白評量並扣堂（系統使用前已上課適用）。
     * POST /api/v1/learning-records/bulk-backdoor-approve
     */
    public function bulkBackdoorApprove(Request $request)
    {
        return response()->json([
            'message' => 'Legacy bulk backfill endpoint retired. Use POST /api/v1/class-sessions/batch.',
            'code' => 'legacy_bulk_backfill_retired',
        ], 410);

        $data = $request->validate([
            'StudentClassID' => 'required|integer',
            'TeacherID' => 'required|integer',
            'DirectorID' => 'required|integer',
            'session_dates' => 'required|array',
            'session_dates.*' => 'required|date',
            'teacher_per_date' => 'nullable|array',
            'teacher_per_date.*' => 'integer',
            'auto_project_future' => 'nullable|boolean',
        ]);

        $sessionDates = array_values(array_unique($data['session_dates']));
        $teacherPerDate = $data['teacher_per_date'] ?? [];
        $autoProjectFuture = array_key_exists('auto_project_future', $data)
            ? (bool) $data['auto_project_future']
            : true;
        if (count($sessionDates) > 200) {
            return response()->json(['message' => '單次補登最多 200 堂'], 422);
        }
        $today = now()->toDateString();
        $future = array_filter($sessionDates, fn ($d) => $d > $today);
        if (!empty($future)) {
            return response()->json(['message' => '補登僅限今天或已過的日期，請移除未來日期'], 422);
        }

        $studentClass = StudentClass::find($data['StudentClassID']);
        if (!$studentClass) {
            return response()->json(['message' => '找不到學生課程'], 422);
        }

        $subjectName = \Illuminate\Support\Facades\DB::table('Subject')->where('id', $studentClass->SubjectID)->value('Subject_Name')
            ?? \Illuminate\Support\Facades\DB::table('BaseData')->where('Name', '課程')->where('id', $studentClass->SubjectID)->value('Val')
            ?? '補登';

        $created = 0;
        $approved = 0;
        $projectedFutureDates = [];
        $skippedDates = [];
        $projectionAnchorDate = null;
        $expectedDateSet = $this->buildBackfillExpectedDateSet($studentClass);
        return DB::transaction(function () use (
            $data,
            $studentClass,
            $subjectName,
            $sessionDates,
            $teacherPerDate,
            $autoProjectFuture,
            &$created,
            &$approved,
            &$projectedFutureDates,
            &$skippedDates,
            &$projectionAnchorDate,
            $expectedDateSet
        ) {
            $today = now()->toDateString();
            foreach ($sessionDates as $sessionDate) {
                $teacherId = isset($teacherPerDate[$sessionDate]) ? (int) $teacherPerDate[$sessionDate] : (int) $data['TeacherID'];
                if ($projectionAnchorDate === null || strcmp($sessionDate, $projectionAnchorDate) > 0) {
                    $projectionAnchorDate = $sessionDate;
                }
                $classSession = $this->findEffectiveClassSessionForDate($studentClass->ID, $sessionDate);
                if (!$classSession) {
                    if (!$this->canBackfillCreateClassSession($sessionDate, $expectedDateSet)) {
                        $skippedDates[] = $sessionDate;
                        continue;
                    }

                    $slot = $this->resolveBackfillSlotForDate($studentClass, $sessionDate);
                    $classSession = ClassSession::create([
                        'StudentClassID' => $studentClass->ID,
                        'SessionDate' => $sessionDate,
                        'StartTime' => $slot['start'],
                        'EndTime' => $slot['end'],
                        'Status' => $sessionDate <= $today ? 'completed' : 'scheduled',
                        'Note' => 'auto-created by backfill',
                    ]);
                }
                $effectiveDate = $this->normalizeDateValue($classSession->SessionDate) ?: $sessionDate;
                if ($projectionAnchorDate === null || strcmp($effectiveDate, $projectionAnchorDate) > 0) {
                    $projectionAnchorDate = $effectiveDate;
                }

                $record = LearningRecord::where('ClassSessionID', $classSession->id)->active()->first();
                if ($record) {
                    if ($record->Status === 'approved') {
                        $this->syncRecordWithClassSession($record, $classSession, $teacherId);
                        continue;
                    }
                    $record->Status = 'approved';
                    $record->ApprovedBy = $data['DirectorID'];
                    $record->ApprovedAt = now();
                    if ($teacherId && $record->TeacherID != $teacherId) {
                        $record->TeacherID = $teacherId;
                    }
                    $this->syncRecordWithClassSession($record, $classSession, $teacherId);
                    $record->save();
                    $approved++;
                    continue;
                }

                $record = LearningRecord::create([
                    'StudentClassID' => $studentClass->ID,
                    'ClassSessionID' => $classSession->id,
                    'TeacherID' => $teacherId,
                    'CreatedByUserID' => $data['DirectorID'],
                    'Content' => '（系統補登）',
                    'Subject' => $subjectName,
                    'SessionDate' => $this->normalizeDateValue($classSession->SessionDate),
                    'StartTime' => $this->normalizeTimeValue($classSession->StartTime),
                    'EndTime' => $this->normalizeTimeValue($classSession->EndTime),
                    'Status' => 'approved',
                    'ApprovedBy' => $data['DirectorID'],
                    'ApprovedAt' => now(),
                ]);
                $created++;
            }

            if ($autoProjectFuture) {
                $studentClass->refresh();
                $remainingToProject = $this->resolveRemainingSessionsForProjection($studentClass);
                if ($remainingToProject > 0) {
                    $projectedFutureDates = $this->projectFutureClassSessions(
                        $studentClass,
                        $remainingToProject,
                        $projectionAnchorDate
                    );
                }
            }

            $total = $created + $approved;
            if ($total > 0) {
                $parts = [];
                if ($created > 0) $parts[] = "新增 {$created} 筆";
                if ($approved > 0) $parts[] = "核准待審 {$approved} 筆";
                if (count($skippedDates) > 0) $parts[] = '略過無課堂 ' . count($skippedDates) . ' 筆';
                if (count($projectedFutureDates) > 0) $parts[] = '推算未來 ' . count($projectedFutureDates) . ' 堂';
                $message = '已完成：' . implode('、', $parts);
            } else {
                if (count($projectedFutureDates) > 0) {
                    $message = '歷史補登無異動，已推算未來 ' . count($projectedFutureDates) . ' 堂';
                } elseif (count($skippedDates) > 0) {
                    $message = '所選日期皆無可補登課堂（請假/停課），未建立評量';
                } else {
                    $message = '所選日期皆已為已核准紀錄，未變更';
                }
            }
            return response()->json([
                'message' => $message,
                'created' => $created,
                'approved' => $approved,
                'skipped_missing_session_count' => count($skippedDates),
                'skipped_missing_session_dates' => array_values($skippedDates),
                'projected_future_count' => count($projectedFutureDates),
                'projected_future_dates' => array_values($projectedFutureDates),
            ], 201);
        });
    }

    /**
     * 調課/加課時同步更新或建立 ClassSession，確保 RFID 刷卡可匹配並正確扣課。
     * POST /api/v1/learning-records/reschedule-session
     *
     * Body:
     *   student_class_id  (required)
     *   new_date          (required, Y-m-d) — 新上課日期 (加課 = 加課日期)
     *   old_date          (nullable, Y-m-d) — 原上課日期，若提供則將該 ClassSession 移至 new_date
     *   start_time        (nullable, H:i)
     *   end_time          (nullable, H:i)
     */
    public function rescheduleSession(Request $request)
    {
        $data = $request->validate([
            'student_class_id' => 'required|integer',
            'old_date'         => 'nullable|date',
            'new_date'         => 'required|date',
            'start_time'       => 'nullable|string|max:8',
            'end_time'         => 'nullable|string|max:8',
            'old_start_time'   => 'nullable|string|max:8',
        ]);
        $classId   = (int) $data['student_class_id'];
        $oldDate   = $data['old_date'] ?? null;
        $newDate   = $data['new_date'];
        $startTime = $data['start_time'] ?? null;
        $endTime   = $data['end_time'] ?? null;
        $oldStartTime = $data['old_start_time'] ?? null;
        $authUser = $request->attributes->get('auth_user');
        $authUserId = (int) ($authUser->id ?? 0);

        $studentClass = StudentClass::find($classId);
        if (!$studentClass) {
            return response()->json(['message' => '找不到課程'], 404);
        }

        // Try to find an existing ClassSession on the old date to move
        $session = null;
        if ($oldDate) {
            $query = ClassSession::where('StudentClassID', $classId)
                ->where('SessionDate', $oldDate);
            if ($oldStartTime) {
                $normalized = $this->normalizeProjectionTime($oldStartTime);
                if ($normalized) {
                    $session = (clone $query)->where('StartTime', $normalized)->first();
                }
                // old_start_time was explicitly provided but no session matched → strict 422
                if (!$session) {
                    return response()->json(['message' => '找不到指定堂次'], 422);
                }
            } else {
                $session = $query->first();
            }
        }

        if ($session) {
            $oldStatus = strtolower(trim((string) ($session->Status ?? 'scheduled')));
            $this->cancelAutoMaterializedDuplicateSession(
                $classId,
                $newDate,
                $startTime ?: (string) $session->StartTime,
                (int) $session->id
            );
            $session->SessionDate = $newDate;
            if ($startTime) $session->StartTime = $startTime;
            if ($endTime)   $session->EndTime   = $endTime;
            $session->save();

            $resetApplied = false;
            if ($this->shouldResetToScheduledByReschedule($session)) {
                $resetApplied = $this->resetRescheduledFutureSession($session, $studentClass, $authUserId, $oldStatus);
            }

            LearningRecord::where('ClassSessionID', $session->id)->update([
                'SessionDate' => $session->SessionDate,
                'StartTime' => $session->StartTime,
                'EndTime' => $session->EndTime,
            ]);

            $this->syncSchedulesAfterReschedule(
                $classId,
                $oldDate,
                $newDate,
                $session->StartTime,
                $session->EndTime
            );

            return response()->json([
                'message' => $resetApplied ? '已調課至未來並重置為未上' : '已同步更新評量表日期',
                'session_id' => $session->id,
                'reset_to_scheduled' => $resetApplied,
            ], 200);
        }

        // No existing session to move: ensure one exists on new_date for RFID matching
        $existing = ClassSession::where('StudentClassID', $classId)
            ->where('SessionDate', $newDate)
            ->first();
        if ($existing) {
            if ($startTime) { $existing->StartTime = $startTime; }
            if ($endTime) { $existing->EndTime = $endTime; }
            $existing->save();
            LearningRecord::where('ClassSessionID', $existing->id)->update([
                'SessionDate' => $existing->SessionDate,
                'StartTime' => $existing->StartTime,
                'EndTime' => $existing->EndTime,
            ]);
            return response()->json(['message' => '該日期已有課堂紀錄', 'session_id' => $existing->id], 200);
        }

        $fallbackStart = $startTime ?? ($studentClass->time1 ?? '00:00');
        $fallbackEnd   = $endTime ?? '00:00';

        $newSession = ClassSession::create([
            'StudentClassID' => $classId,
            'SessionDate'    => $newDate,
            'StartTime'      => $fallbackStart,
            'EndTime'        => $fallbackEnd,
            'Status'         => 'scheduled',
        ]);
        return response()->json(['message' => '已建立課堂紀錄', 'session_id' => $newSession->id], 201);
    }

    private function shouldResetToScheduledByReschedule(ClassSession $session): bool
    {
        $date = $this->normalizeDateValue($session->SessionDate);
        if (!$date) {
            return false;
        }
        $endTime = $this->normalizeProjectionTime($session->EndTime) ?? '23:59:59';
        try {
            $timezone = config('app.timezone', 'Asia/Taipei');
            $sessionEndAt = Carbon::createFromFormat('Y-m-d H:i:s', "{$date} {$endTime}", $timezone);
            return $sessionEndAt->gt(now($timezone));
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function resetRescheduledFutureSession(
        ClassSession $session,
        StudentClass $studentClass,
        int $authUserId,
        string $oldStatus = 'scheduled'
    ): bool {
        $attendedLike = ['attended', 'completed', 'late', 'absent'];
        $reason = '調課到未來，自動改回未上';
        $sessionId = (int) $session->id;
        $classId = (int) $studentClass->ID;

        $activeSignIns = StudentSignIn::where('ClassSessionID', $sessionId)
            ->active()
            ->get();
        $hadDeductedSignIn = $activeSignIns->contains(fn ($si) => (bool) ($si->SessionDeducted ?? false));
        foreach ($activeSignIns as $signIn) {
            $signIn->VoidedAt = now();
            $signIn->VoidedByUserID = $authUserId > 0 ? $authUserId : null;
            $signIn->VoidReason = $reason;
            $signIn->save();
        }

        $approvedRecords = LearningRecord::where('ClassSessionID', $sessionId)
            ->active()
            ->where('Status', 'approved')
            ->get();
        $approvedCountByTeacher = [];
        $hasLrSessionDeducted = $this->hasLearningRecordSessionDeductedColumn();
        foreach ($approvedRecords as $record) {
            $teacherId = (int) ($record->TeacherID ?? 0);
            if ($teacherId > 0) {
                $approvedCountByTeacher[$teacherId] = ($approvedCountByTeacher[$teacherId] ?? 0) + 1;
            }
            $record->Status = 'pending';
            $record->ApprovedBy = null;
            $record->ApprovedAt = null;
            if ($hasLrSessionDeducted) {
                $record->SessionDeducted = false;
            }
            $record->save();
        }

        foreach ($approvedCountByTeacher as $teacherId => $count) {
            $safeCount = max(0, (int) $count);
            if ($teacherId <= 0 || $safeCount <= 0) {
                continue;
            }
            DB::table('User')
                ->where('id', (int) $teacherId)
                ->update([
                    'TeachingSessionCount' => DB::raw("CASE WHEN TeachingSessionCount >= {$safeCount} THEN TeachingSessionCount - {$safeCount} ELSE 0 END"),
                ]);
        }

        $wasAttendedLike = in_array($oldStatus, $attendedLike, true);
        if ($wasAttendedLike || $hadDeductedSignIn || $approvedRecords->count() > 0) {
            SessionDeductionService::reverseForSession(
                $classId,
                $sessionId,
                'status_adjust',
                $authUserId > 0 ? $authUserId : null,
                $reason
            );
        }

        $wasAlreadyScheduled = strtolower(trim((string) ($session->Status ?? ''))) === 'scheduled';
        if (!$wasAlreadyScheduled) {
            $session->Status = 'scheduled';
            $session->save();
        }

        SessionDeductionService::recomputeCounters($classId);
        return !$wasAlreadyScheduled || $hadDeductedSignIn || $approvedRecords->count() > 0;
    }

    /**
     * FR-001/FR-002: 調課後同步 schedules 表的代課相關列至新日期，
     * 並清除 race condition 植入的重複 scheduled 列。
     */
    private function syncSchedulesAfterReschedule(
        int $courseId,
        ?string $oldDate,
        string $newDate,
        ?string $startTime,
        ?string $endTime
    ): void {
        if (!$oldDate) {
            return;
        }

        // The `rescheduled` anchor row stays on the OLD date as a historical marker.
        // Only the linked `scheduled` (substitute) row follows the session to its new slot.
        $rescheduledRow = Schedule::where('student_course_id', $courseId)
            ->whereDate('schedule_date', $oldDate)
            ->where('status', 'rescheduled')
            ->lockForUpdate()
            ->first();

        if (!$rescheduledRow) {
            return;
        }

        $scheduledRow = Schedule::where('student_course_id', $courseId)
            ->where('original_schedule_id', $rescheduledRow->id)
            ->where('status', 'scheduled')
            ->lockForUpdate()
            ->first();

        if ($scheduledRow) {
            $updates = [
                'start_time' => $startTime ?: $scheduledRow->start_time,
                'end_time'   => $endTime   ?: $scheduledRow->end_time,
            ];

            // Moving to a different date updates date/day; same-day time changes
            // still go through the duplicate purge below for the same anchor.
            $updates['schedule_date'] = $newDate;
            $updates['day_of_week']   = (int) Carbon::parse($newDate)->dayOfWeekIso;

            $scheduledRow->update($updates);

            // Purge duplicate scheduled rows for this reschedule anchor, including
            // same-day time changes where stale rows can otherwise render twice.
            Schedule::where('student_course_id', $courseId)
                ->whereDate('schedule_date', $newDate)
                ->where('status', 'scheduled')
                ->where('original_schedule_id', $rescheduledRow->id)
                ->where('id', '!=', $scheduledRow->id)
                ->delete();
        }
    }

    private function cancelAutoMaterializedDuplicateSession(
        int $courseId,
        string $newDate,
        ?string $startTime,
        int $movingSessionId
    ): void {
        $startHm = substr((string) ($startTime ?? ''), 0, 5);
        if ($courseId <= 0 || $startHm === '' || $movingSessionId <= 0) {
            return;
        }

        $duplicate = ClassSession::where('StudentClassID', $courseId)
            ->whereDate('SessionDate', $newDate)
            ->whereRaw('SUBSTRING(StartTime, 1, 5) = ?', [$startHm])
            ->where('id', '!=', $movingSessionId)
            ->where('Status', 'scheduled')
            ->where(function ($q) {
                $q->whereNull('Note')
                    ->orWhere('Note', '')
                    ->orWhere('Note', 'auto-materialized-from-schedule');
            })
            ->lockForUpdate()
            ->first();

        if (!$duplicate) {
            return;
        }

        $duplicate->Status = 'cancelled';
        $duplicate->Note = trim((string) ($duplicate->Note ?? '') . '; cancelled-duplicate-reschedule-placeholder', '; ');
        $duplicate->save();
    }

    public function requestChanges(Request $request, LearningRecord $learningRecord)
    {
        $data = $request->validate([
            'DirectorID' => 'nullable|integer',
            'ReviewNote' => 'nullable|string|max:255',
        ]);

        if ($learningRecord->Status === 'approved') {
            return response()->json(['message' => 'Record already approved'], 409);
        }

        $learningRecord->Status = 'changes_requested';
        $learningRecord->ReviewNote = $data['ReviewNote'] ?? '';
        $learningRecord->ApprovedBy = $data['DirectorID'] ?? null;
        $learningRecord->ApprovedAt = null;
        $learningRecord->save();

        return response()->json($learningRecord);
    }

    public function reject(Request $request, LearningRecord $learningRecord)
    {
        $data = $request->validate([
            'DirectorID' => 'nullable|integer',
            'ReviewNote' => 'nullable|string|max:255',
        ]);

        if ($learningRecord->Status === 'approved') {
            return response()->json(['message' => 'Record already approved'], 409);
        }

        $learningRecord->Status = 'rejected';
        $learningRecord->ReviewNote = $data['ReviewNote'] ?? '';
        $learningRecord->ApprovedBy = $data['DirectorID'] ?? null;
        $learningRecord->ApprovedAt = null;
        $learningRecord->save();

        return response()->json($learningRecord);
    }

    /**
     * Auto-generate pending LearningRecord entries for all past ClassSessions
     * that don't yet have one, within a given branch.
     *
     * POST /api/v1/learning-records/ensure-past
     * Body: { branch_id: int }
     */
    public function ensurePastRecords(Request $request)
    {
        $branchId = (int) $request->input('branch_id', 0);
        if ($branchId <= 0) {
            return response()->json(['created' => 0]);
        }

        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        if ($role !== 'super_admin' && !empty($campusIds) && !in_array($branchId, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $now = Carbon::now()->format('Y-m-d H:i:s');

        $studentIds = Student::where('CampusID', $branchId)->pluck('id');
        if ($studentIds->isEmpty()) {
            return response()->json(['created' => 0]);
        }

        $classes = StudentClass::whereIn('StudentID', $studentIds)->get();

        $subjectNameMap = DB::table('Subject')->pluck('Subject_Name', 'id')->all();

        $created = 0;

        foreach ($classes as $sc) {
            $courseStopped = (int) ($sc->Stop ?? 0) === 1;
            $sessions = ClassSession::where('StudentClassID', $sc->ID)
                ->whereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted'])
                ->whereRaw("CONCAT(SessionDate, ' ', COALESCE(StartTime, '00:00:00')) <= ?", [$now])
                ->when($courseStopped, function ($query) {
                    $query->whereIn('Status', ['attended', 'late', 'absent']);
                })
                ->get();

            foreach ($sessions as $cs) {
                $existing = LearningRecord::where('ClassSessionID', $cs->id)->first();
                if ($existing) {
                    if (!$existing->isVoided()) {
                        // Self-heal legacy drift: if record date/time no longer matches ClassSession,
                        // force them back to ClassSession so CourseManagement and LearningRecords stay aligned.
                        $this->syncRecordWithClassSession($existing, $cs, (int) ($sc->TeacherID ?? 0));
                    } elseif (in_array(strtolower((string) ($cs->Status ?? '')), ['attended', 'completed', 'late', 'absent'], true)) {
                        // The LR was previously voided (e.g. by a leave cascade that was later reversed),
                        // but the session is now attended. Restore it so teachers can fill in the record.
                        $existing->VoidedAt = null;
                        $existing->VoidedByUserID = null;
                        $existing->VoidReason = null;
                        $existing->Status = 'pending';
                        $existing->SessionDate = $cs->SessionDate ? substr((string) $cs->SessionDate, 0, 10) : null;
                        $existing->StartTime   = $cs->StartTime   ? substr((string) $cs->StartTime, 0, 5)   : null;
                        $existing->EndTime     = $cs->EndTime     ? substr((string) $cs->EndTime, 0, 5)     : null;
                        $existing->save();
                        $created++;
                    }
                    continue;
                }

                $subjectName = $subjectNameMap[(int) $sc->SubjectID] ?? '未知';

                $tid = SubstituteScheduleService::effectiveInstructorUserId(
                    (int) $sc->ID,
                    $cs->SessionDate,
                    (int) ($sc->TeacherID ?? 0),
                    $cs->StartTime
                );
                LearningRecord::create([
                    'StudentClassID' => $sc->ID,
                    'ClassSessionID' => $cs->id,
                    'TeacherID' => $tid > 0 ? $tid : (int) ($sc->TeacherID ?? 0),
                    'Content' => '',
                    'Subject' => $subjectName,
                    'SessionDate' => $cs->SessionDate,
                    'StartTime' => $cs->StartTime,
                    'EndTime' => $cs->EndTime,
                    'Status' => 'pending',
                ]);
                $created++;
            }
        }

        return response()->json(['created' => $created]);
    }

    private function syncRecordWithClassSession(
        LearningRecord $record,
        ?ClassSession $classSession = null,
        ?int $fallbackTeacherId = null
    ): void {
        $sessionId = (int) ($record->ClassSessionID ?? 0);
        if ($sessionId <= 0) {
            return;
        }

        $classSession = $classSession ?: ClassSession::find($sessionId);
        if (!$classSession) {
            return;
        }

        $targetDate = $this->normalizeDateValue($classSession->SessionDate);
        $targetStart = $this->normalizeTimeValue($classSession->StartTime);
        $targetEnd = $this->normalizeTimeValue($classSession->EndTime);
        $recordDate = $this->normalizeDateValue($record->SessionDate);
        $recordStart = $this->normalizeTimeValue($record->StartTime);
        $recordEnd = $this->normalizeTimeValue($record->EndTime);

        $dirty = false;

        if ((int) $record->StudentClassID !== (int) $classSession->StudentClassID) {
            $record->StudentClassID = (int) $classSession->StudentClassID;
            $dirty = true;
        }
        if ($targetDate !== $recordDate) {
            $record->SessionDate = $targetDate;
            $dirty = true;
        }
        if ($targetStart !== $recordStart) {
            $record->StartTime = $targetStart;
            $dirty = true;
        }
        if ($targetEnd !== $recordEnd) {
            $record->EndTime = $targetEnd;
            $dirty = true;
        }
        $subTid = SubstituteScheduleService::resolveSubstituteUserId(
            (int) $classSession->StudentClassID,
            $classSession->SessionDate,
            $classSession->StartTime
        );
        $contractTid = (int) ($fallbackTeacherId ?? 0);
        $effectiveTeacherId = $subTid !== null ? $subTid : max(0, $contractTid);
        if ($effectiveTeacherId > 0 && (int) $record->TeacherID !== $effectiveTeacherId) {
            $record->TeacherID = $effectiveTeacherId;
            $dirty = true;
        }

        if ($dirty) {
            $record->save();
        }
    }

    /**
     * 正班（LR.TeacherID）或單堂代課 schedules 指派之代課老師皆可編輯該筆評量。
     */
    private function teacherIsInstructorForLearningRecord(LearningRecord $lr, int $authTeacherId): bool
    {
        if ($authTeacherId <= 0) {
            return false;
        }
        if ((int) ($lr->TeacherID ?? 0) === $authTeacherId) {
            return true;
        }
        $csId = (int) ($lr->ClassSessionID ?? 0);
        if ($csId <= 0) {
            return false;
        }
        $cs = ClassSession::find($csId);
        if (!$cs) {
            return false;
        }
        $sub = SubstituteScheduleService::resolveSubstituteUserId((int) $cs->StudentClassID, $cs->SessionDate, $cs->StartTime);

        return $sub !== null && $sub === $authTeacherId;
    }

    private function teacherAllowedToCreateLearningRecord(StudentClass $sc, ClassSession $cs, int $authTeacherId, int $payloadTeacherId): bool
    {
        if ($authTeacherId <= 0) {
            return false;
        }
        if ((int) ($sc->TeacherID ?? 0) === $authTeacherId) {
            return true;
        }
        $sub = SubstituteScheduleService::resolveSubstituteUserId((int) $sc->ID, $cs->SessionDate, $cs->StartTime);
        if ($sub !== null && $sub === $authTeacherId) {
            return true;
        }

        return $payloadTeacherId === $authTeacherId;
    }

    private function findEffectiveClassSessionForDate(int $studentClassId, string $sessionDate, ?string $startTime = null): ?ClassSession
    {
        $query = ClassSession::where('StudentClassID', $studentClassId)
            ->where('SessionDate', $sessionDate)
            ->whereNotIn('Status', ['leave', 'cancelled']);

        if ($startTime) {
            $normalizedTime = $this->normalizeProjectionTime($startTime);
            if ($normalizedTime) {
                $exact = (clone $query)->where('StartTime', $normalizedTime)->orderBy('id', 'desc')->first();
                if ($exact) {
                    return $exact;
                }
            }
        }

        return $query->orderBy('id', 'desc')->first();
    }

    private function validateSessionStartedForWrite($sessionDate, $startTime): ?\Illuminate\Http\JsonResponse
    {
        $date = $this->normalizeDateValue($sessionDate);
        $time = $this->normalizeProjectionTime($startTime);
        if (!$date) {
            return null;
        }

        try {
            $timezone = config('app.timezone', 'Asia/Taipei');
            $today = now($timezone)->toDateString();
            if ($date > $today) {
                return response()->json([
                    'message' => '課程尚未開始，請於上課時間後再填寫評量表',
                    'session_start_at' => "{$date} 00:00:00",
                ], 422);
            }
            if (!$time) {
                return null;
            }
            $sessionStartAt = Carbon::createFromFormat('Y-m-d H:i:s', "{$date} {$time}", $timezone);
            if (now($timezone)->lessThan($sessionStartAt)) {
                return response()->json([
                    'message' => '課程尚未開始，請於上課時間後再填寫評量表',
                    'session_start_at' => $sessionStartAt->toDateTimeString(),
                ], 422);
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    /**
     * @return array<string, bool>
     */
    private function buildBackfillExpectedDateSet(StudentClass $studentClass): array
    {
        $startDate = $this->normalizeDateValue($studentClass->StartDate ?? null);
        $sessionCount = max(0, (int) ($studentClass->SessionCount ?? 0));
        $daysOfWeek = $this->resolveStudentClassWeekdays($studentClass);
        if (!$startDate || $sessionCount <= 0 || empty($daysOfWeek)) {
            return [];
        }

        $leaveSet = [];
        $scheduledSet = [];
        $exceptions = Schedule::whereNotNull('student_course_id')
            ->where('student_course_id', (int) $studentClass->ID)
            ->select('schedule_date', 'status')
            ->get();
        foreach ($exceptions as $row) {
            $date = $this->normalizeDateValue($row->schedule_date ?? null);
            if (!$date) {
                continue;
            }
            if ((string) $row->status === 'scheduled') {
                $scheduledSet[$date] = true;
            } else {
                $leaveSet[$date] = true;
            }
        }

        $dates = $this->computeEffectiveSessionDatesForBackfill(
            $startDate,
            $sessionCount,
            $daysOfWeek,
            $leaveSet,
            $scheduledSet
        );

        return collect($dates)->mapWithKeys(fn ($d) => [(string) $d => true])->all();
    }

    /**
     * @param array<string, bool> $expectedDateSet
     */
    private function canBackfillCreateClassSession(string $sessionDate, array $expectedDateSet): bool
    {
        $normalized = $this->normalizeDateValue($sessionDate);
        if (!$normalized) {
            return false;
        }
        if (empty($expectedDateSet)) {
            return true;
        }
        return isset($expectedDateSet[$normalized]);
    }

    /**
     * @return array{start:string,end:string}
     */
    private function resolveBackfillSlotForDate(StudentClass $studentClass, string $sessionDate): array
    {
        $isoDow = (int) Carbon::parse($sessionDate)->dayOfWeekIso;
        $pairs = [
            ['week', 'time'],
            ['week1', 'time1'],
            ['week2', 'time2'],
            ['week3', 'time3'],
            ['week4', 'time4'],
            ['week5', 'time5'],
            ['week6', 'time6'],
        ];

        $start = null;
        foreach ($pairs as [$weekField, $timeField]) {
            $dow = (int) ($studentClass->{$weekField} ?? 0);
            if ($dow !== $isoDow) {
                continue;
            }
            $start = $this->normalizeProjectionTime($studentClass->{$timeField} ?? null);
            if ($start) {
                break;
            }
        }
        if (!$start) {
            $start = $this->normalizeProjectionTime($studentClass->time ?? null)
                ?? $this->normalizeProjectionTime($studentClass->time1 ?? null)
                ?? '16:00:00';
        }

        $durationMinutes = $this->resolveBackfillDurationMinutes($studentClass);
        $end = Carbon::createFromFormat('H:i:s', $start)
            ->addMinutes($durationMinutes)
            ->format('H:i:s');

        return ['start' => $start, 'end' => $end];
    }

    private function resolveBackfillDurationMinutes(StudentClass $studentClass): int
    {
        $sessionDuration = (int) ($studentClass->SessionDuration ?? 0);
        if ($sessionDuration > 0) {
            if ($sessionDuration <= 8) {
                return $sessionDuration * 60;
            }
            if ($sessionDuration <= 12 * 60) {
                return $sessionDuration;
            }
        }

        $learnTimeId = (int) ($studentClass->LearnTimeID ?? 0);
        if ($learnTimeId > 0 && $learnTimeId <= 8) {
            return $learnTimeId * 60;
        }

        return 120;
    }

    /**
     * @param array<int> $daysOfWeek
     * @param array<string, bool> $leaveSet
     * @param array<string, bool> $scheduledSet
     * @return array<int, string>
     */
    private function computeEffectiveSessionDatesForBackfill(
        string $startDate,
        int $sessionCount,
        array $daysOfWeek,
        array $leaveSet,
        array $scheduledSet
    ): array {
        $list = [];
        if ($sessionCount <= 0 || empty($daysOfWeek)) {
            return $list;
        }

        $cursor = Carbon::parse($startDate . ' 12:00:00');
        $end = $cursor->copy()->addYears(2);
        while ($cursor->lte($end) && count($list) < $sessionCount) {
            $ymd = $cursor->toDateString();
            $dow = (int) $cursor->dayOfWeekIso;
            $isRegular = in_array($dow, $daysOfWeek, true);
            $isLeave = isset($leaveSet[$ymd]);
            $isScheduledExtra = isset($scheduledSet[$ymd]);

            if ($isRegular && !$isLeave) {
                $list[] = $ymd;
            } elseif ($isScheduledExtra && !$isRegular) {
                $list[] = $ymd;
            }

            $cursor->addDay();
        }

        return array_slice($list, 0, $sessionCount);
    }

    private function resolveRemainingSessionsForProjection(StudentClass $studentClass): int
    {
        $sessionCount = (int) ($studentClass->SessionCount ?? 0);
        $remainingSessions = $studentClass->RemainingSessions !== null
            ? max(0, (int) $studentClass->RemainingSessions)
            : null;
        $usedSessions = max(0, (int) ($studentClass->UsedSessions ?? 0));

        // 堂數制以「總堂數 - 已使用(出缺勤扣堂)」推導。
        if ($sessionCount > 0) {
            $derivedRemaining = max(0, $sessionCount - $usedSessions);

            if ($remainingSessions === null) {
                return $derivedRemaining;
            }

            return min($remainingSessions, $derivedRemaining);
        }

        return $remainingSessions ?? 0;
    }

    /**
     * 推算剩餘堂數的未來日期：
     * 起點 = 最後一筆 ClassSession 日期之後
     * 規則 = 僅依固定星期幾往未來排，不回補歷史空檔
     */
    private function projectFutureClassSessions(StudentClass $studentClass, int $count, ?string $anchorDate = null): array
    {
        if ($count <= 0) {
            return [];
        }

        $lastSession = ClassSession::where('StudentClassID', $studentClass->ID)
            ->orderBy('SessionDate', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        $weekdays = $this->resolveStudentClassWeekdays($studentClass);
        $effectiveAnchor = null;
        if ($lastSession && !empty($lastSession->SessionDate)) {
            $effectiveAnchor = Carbon::parse($lastSession->SessionDate)->toDateString();
        }
        $normalizedAnchor = $anchorDate ? $this->normalizeDateValue($anchorDate) : null;
        if ($normalizedAnchor && ($effectiveAnchor === null || strcmp($normalizedAnchor, $effectiveAnchor) > 0)) {
            $effectiveAnchor = $normalizedAnchor;
        }
        if (!$effectiveAnchor) {
            return [];
        }

        if (empty($weekdays)) {
            $weekdays = [(int) Carbon::parse($effectiveAnchor)->dayOfWeekIso];
        }

        $fallbackStart = $this->normalizeProjectionTime(
            $studentClass->time1 ?? $studentClass->time ?? '16:00'
        ) ?? '16:00:00';
        $fallbackSeedSession = $lastSession ?: new ClassSession([
            'StudentClassID' => $studentClass->ID,
            'SessionDate' => $effectiveAnchor,
            'StartTime' => $fallbackStart,
            'EndTime' => null,
        ]);
        $slots = $this->resolveProjectionSlotsByWeekday($studentClass, $weekdays, $fallbackSeedSession);
        $fallbackSlot = reset($slots) ?: ['start' => $fallbackStart, 'end' => '18:00:00'];

        $existingDates = ClassSession::where('StudentClassID', $studentClass->ID)
            ->pluck('SessionDate')
            ->filter()
            ->map(fn ($d) => (string) $d)
            ->flip()
            ->all();

        $cursor = Carbon::parse($effectiveAnchor)->startOfDay()->addDay();
        $projected = [];
        $guard = 0;
        $guardMax = 4000;

        while (count($projected) < $count && $guard < $guardMax) {
            $guard++;
            $isoDow = (int) $cursor->dayOfWeekIso;
            if (!in_array($isoDow, $weekdays, true)) {
                $cursor->addDay();
                continue;
            }

            $date = $cursor->toDateString();
            if (isset($existingDates[$date])) {
                $cursor->addDay();
                continue;
            }

            $slot = $slots[$isoDow] ?? $fallbackSlot;
            ClassSession::create([
                'StudentClassID' => $studentClass->ID,
                'SessionDate' => $date,
                'StartTime' => $slot['start'],
                'EndTime' => $slot['end'],
                'Status' => 'scheduled',
                'Note' => 'auto-projected by backfill',
            ]);

            $existingDates[$date] = true;
            $projected[] = $date;
            $cursor->addDay();
        }

        return $projected;
    }

    /**
     * @return array<int>
     */
    private function resolveStudentClassWeekdays(StudentClass $studentClass): array
    {
        $weekdays = [];
        foreach (['week', 'week1', 'week2', 'week3', 'week4', 'week5', 'week6'] as $field) {
            $dow = (int) ($studentClass->{$field} ?? 0);
            if ($dow >= 1 && $dow <= 7) {
                $weekdays[$dow] = $dow;
            }
        }
        ksort($weekdays);
        return array_values($weekdays);
    }

    /**
     * @param array<int> $weekdays
     * @return array<int, array{start:string,end:string}>
     */
    private function resolveProjectionSlotsByWeekday(
        StudentClass $studentClass,
        array $weekdays,
        ClassSession $fallbackSession
    ): array {
        $durationMinutes = $this->resolveProjectionDurationMinutes($studentClass, $fallbackSession);
        $fallbackStart = $this->normalizeProjectionTime($fallbackSession->StartTime) ?? '16:00:00';
        $fallbackEnd = $this->normalizeProjectionTime($fallbackSession->EndTime)
            ?? Carbon::createFromFormat('H:i:s', $fallbackStart)->addMinutes($durationMinutes)->format('H:i:s');

        $slots = [];
        foreach ($weekdays as $dow) {
            $slots[$dow] = ['start' => $fallbackStart, 'end' => $fallbackEnd];
        }

        $pairs = [
            ['week', 'time'],
            ['week1', 'time1'],
            ['week2', 'time2'],
            ['week3', 'time3'],
            ['week4', 'time4'],
            ['week5', 'time5'],
            ['week6', 'time6'],
        ];

        foreach ($pairs as [$weekField, $timeField]) {
            $dow = (int) ($studentClass->{$weekField} ?? 0);
            if ($dow < 1 || $dow > 7 || !isset($slots[$dow])) {
                continue;
            }

            $start = $this->normalizeProjectionTime($studentClass->{$timeField} ?? null)
                ?? $this->normalizeProjectionTime($studentClass->time ?? null)
                ?? $fallbackStart;

            $end = Carbon::createFromFormat('H:i:s', $start)
                ->addMinutes($durationMinutes)
                ->format('H:i:s');

            $slots[$dow] = ['start' => $start, 'end' => $end];
        }

        return $slots;
    }

    private function resolveProjectionDurationMinutes(StudentClass $studentClass, ClassSession $fallbackSession): int
    {
        $fallbackStart = $this->normalizeProjectionTime($fallbackSession->StartTime);
        $fallbackEnd = $this->normalizeProjectionTime($fallbackSession->EndTime);
        if ($fallbackStart && $fallbackEnd) {
            try {
                $start = Carbon::createFromFormat('H:i:s', $fallbackStart);
                $end = Carbon::createFromFormat('H:i:s', $fallbackEnd);
                if ($end->lessThanOrEqualTo($start)) {
                    $end->addDay();
                }
                $diff = (int) $start->diffInMinutes($end);
                if ($diff > 0 && $diff <= 12 * 60) {
                    return $diff;
                }
            } catch (\Throwable $e) {
                // ignore and continue fallback rules
            }
        }

        $sessionDuration = (int) ($studentClass->SessionDuration ?? 0);
        if ($sessionDuration > 0) {
            if ($sessionDuration <= 8) {
                return $sessionDuration * 60;
            }
            if ($sessionDuration <= 12 * 60) {
                return $sessionDuration;
            }
        }

        $learnTimeId = (int) ($studentClass->LearnTimeID ?? 0);
        if ($learnTimeId > 0 && $learnTimeId <= 8) {
            return $learnTimeId * 60;
        }

        return 120;
    }

    private function normalizeProjectionTime($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $str = trim((string) $value);
        if ($str === '') {
            return null;
        }
        if (preg_match('/^\d{1,2}:\d{2}$/', $str)) {
            [$h, $m] = explode(':', $str);
            return sprintf('%02d:%02d:00', (int) $h, (int) $m);
        }
        if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $str)) {
            [$h, $m, $s] = explode(':', $str);
            return sprintf(
                '%02d:%02d:%02d',
                (int) $h,
                (int) $m,
                (int) $s
            );
        }
        try {
            return Carbon::parse($str)->format('H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function normalizeDateValue($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function normalizeTimeValue($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $str = trim((string) $value);
        if ($str === '') {
            return null;
        }
        return substr($str, 0, 5);
    }

    /**
     * Batch-compute session numbers for a collection of LearningRecords.
     *
     * For each record's StudentClass, all ClassSessions are ordered chronologically.
     * Non-leave/non-cancelled sessions are numbered sequentially (1, 2, 3…).
     * Leave/cancelled sessions get null (they don't occupy a slot).
     *
     * @param  \Illuminate\Support\Collection  $records  Collection of LearningRecord models
     * @return array<int, int|null>  Map of LearningRecord.id => session_number (null if not determinable)
     */
    public static function batchSessionNumbers($records): array
    {
        $skipStatuses = ['cancelled', 'leave', 'leave_adjusted', 'excused'];

        $classSessionIds = $records->pluck('ClassSessionID')->filter()->unique()->values();
        if ($classSessionIds->isEmpty()) {
            return [];
        }

        $studentClassIds = $records->pluck('StudentClassID')->filter()->unique()->values();
        if ($studentClassIds->isEmpty()) {
            return [];
        }

        $allSessions = ClassSession::whereIn('StudentClassID', $studentClassIds)
            ->orderBy('SessionDate', 'asc')
            ->orderBy('StartTime', 'asc')
            ->orderBy('id', 'asc')
            ->get(['id', 'StudentClassID', 'SessionDate', 'StartTime', 'Status']);

        // Build map: classSessionId => session_number per StudentClassID
        $sessionNumberMap = [];
        $grouped = $allSessions->groupBy('StudentClassID');
        foreach ($grouped as $scId => $sessions) {
            $num = 0;
            foreach ($sessions as $cs) {
                $status = strtolower(trim((string) ($cs->Status ?? '')));
                $isSkip = in_array($status, $skipStatuses, true);
                if (!$isSkip) {
                    $num++;
                }
                $sessionNumberMap[(int) $cs->id] = $isSkip ? null : $num;
            }
        }

        $result = [];
        foreach ($records as $rec) {
            $csId = (int) ($rec->ClassSessionID ?? 0);
            $result[(int) $rec->id] = $csId > 0 ? ($sessionNumberMap[$csId] ?? null) : null;
        }

        return $result;
    }
}
