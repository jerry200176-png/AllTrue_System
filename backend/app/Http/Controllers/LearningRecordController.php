<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\LearningRecordFeedback;
use App\Models\LearningRecordTeacherChange;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\ApprovalSessionSyncService;
use App\Services\SessionDeductionService;
use App\Services\SubstituteScheduleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class LearningRecordController extends Controller
{
    private function hasLearningRecordSessionDeductedColumn(): bool
    {
        static $hasColumn = null;
        if ($hasColumn === null) {
            $hasColumn = Schema::hasColumn('LearningRecord', 'SessionDeducted');
        }
        return $hasColumn;
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
        $teacherName = DB::table('Teacher')->where('id', $record->TeacherID)->value('T_Name')
            ?? DB::table('User')->where('id', $record->TeacherID)->value('Name');
        $record->teacher_name = $teacherName ?? '未指派';
        return $record;
    }

    public function index(Request $request)
    {
        $query = LearningRecord::active();
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);

        if ($role === 'teacher') {
            $teacherId = $request->attributes->get('auth_teacher_id');
            if (!$teacherId) {
                return response()->json(['message' => 'Teacher not linked'], 403);
            }
            // Show records where: (a) LR.TeacherID is mine, (b) my contract courses,
            // (c) 代課：schedules 顯示我帶此 ClassSession 但 LR 尚未改 TeacherID 者。
            $teacherClassIds = StudentClass::where('TeacherID', $teacherId)->pluck('ID');
            $lrTable = (new LearningRecord())->getTable();
            $query->where(function ($q) use ($teacherId, $teacherClassIds, $lrTable) {
                $q->where('TeacherID', $teacherId);
                if ($teacherClassIds->isNotEmpty()) {
                    $q->orWhereIn('StudentClassID', $teacherClassIds);
                }
                $q->orWhereExists(function ($sub) use ($teacherId, $lrTable) {
                    $sub->select(DB::raw(1))
                        ->from('ClassSession as cs')
                        ->join('schedules as s', function ($join) use ($teacherId) {
                            $join->on('s.student_course_id', '=', 'cs.StudentClassID')
                                ->whereRaw('DATE(s.schedule_date) = DATE(cs.SessionDate)')
                                ->where('s.status', '=', 'scheduled')
                                ->whereNotNull('s.original_schedule_id')
                                ->where('s.teacher_id', '=', $teacherId);
                        })
                        ->whereColumn('cs.id', "{$lrTable}.ClassSessionID");
                });
            });
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
        // Useful for dashboard "pending review" cards to avoid future classes.
        if ($request->boolean('only_due', false)) {
            $now = Carbon::now()->format('Y-m-d H:i:s');
            $query->whereRaw("CONCAT(SessionDate, ' ', COALESCE(EndTime, '23:59:59')) <= ?", [$now]);
        }

        $query->excludePausedCoursePendingReview();
        $query->excludeLeaveSessionPendingReview();

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

        return response()->json($records);
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
        $teacherNameMap = collect();
        if ($teacherIds->isNotEmpty()) {
            $fromTeacher = DB::table('Teacher')->whereIn('id', $teacherIds)->pluck('T_Name', 'id');
            $missingIds = $teacherIds->diff($fromTeacher->keys());
            $fromUser = $missingIds->isNotEmpty()
                ? DB::table('User')->whereIn('id', $missingIds)->pluck('Name', 'id')
                : collect();
            $teacherNameMap = $fromTeacher->union($fromUser);
        }

        $sessionNumbers = static::batchSessionNumbers($collection);
        static $feedbackTableExists = null;
        if ($feedbackTableExists === null) {
            $feedbackTableExists = Schema::hasTable('learning_record_feedbacks');
        }
        $feedbacks = $feedbackTableExists
            ? LearningRecordFeedback::whereIn('learning_record_id', $collection->pluck('id'))
                ->get()
                ->keyBy('learning_record_id')
            : collect();

        $collection->transform(function ($record) use ($subjectMap, $teacherNameMap, $sessionNumbers, $feedbacks) {
            $record->student_name = $record->studentClass->student->name ?? '—';
            $record->student_id = $record->studentClass->student->id ?? null;
            $subjectId = $record->studentClass->SubjectID ?? null;
            $record->student_class_label = ($subjectId && isset($subjectMap[$subjectId]))
                ? $subjectMap[$subjectId]
                : $record->Subject;
            $record->teacher_name = $teacherNameMap[$record->TeacherID] ?? '未指派';
            $record->session_number = $sessionNumbers[(int) $record->id] ?? null;
            $fb = $feedbacks->get((int) $record->id);
            $record->parent_feedback = $fb ? [
                'id' => (int) $fb->id,
                'content' => $fb->content,
                'updated_at' => optional($fb->updated_at)->toIso8601String(),
                'unread_for_teacher' => !$fb->last_read_by_teacher_at || $fb->last_read_by_teacher_at->lt($fb->updated_at),
                'unread_for_director' => !$fb->last_read_by_director_at || $fb->last_read_by_director_at->lt($fb->updated_at),
            ] : null;
            return $record;
        });
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

            $existing = LearningRecord::where('ClassSessionID', $classSessionId)->active()->first();
            if ($existing) {
                return response()->json(['message' => 'Learning record already exists'], 409);
            }

            $authUser = request()->attributes->get('auth_user');
            $content = $data['Content'] ?? ($data['Progress'] ?? '') ?: '（評量表）';
            $sessionDate = $this->normalizeDateValue($classSession->SessionDate) ?? ($data['SessionDate'] ?? null);
            $startTime = $this->normalizeTimeValue($classSession->StartTime) ?? ($data['StartTime'] ?? null);
            $endTime = $this->normalizeTimeValue($classSession->EndTime) ?? ($data['EndTime'] ?? null);

            $lrTeacherId = SubstituteScheduleService::effectiveInstructorUserId(
                (int) $studentClass->ID,
                $classSession->SessionDate,
                (int) ($studentClass->TeacherID ?? 0)
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
                    return response()->json([
                        'message' => '此堂評量表已存在，請重新整理頁面後查看。',
                        'existing_record_id' => $dup ? $dup->id : null,
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
                } else {
                    $this->syncRecordWithClassSession($existing, $classSession, (int) ($data['TeacherID'] ?? 0));
                }

                return response()->json($existing, 201);
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

            return response()->json($record, 201);
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

        $sameDayTimeChange = ($oldDate === $newDate);

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

            if (!$sameDayTimeChange) {
                // Moving to a different date: also update date and day_of_week.
                $updates['schedule_date'] = $newDate;
                $updates['day_of_week']   = (int) Carbon::parse($newDate)->dayOfWeekIso;
            }

            $scheduledRow->update($updates);

            if (!$sameDayTimeChange) {
                // Purge any duplicate scheduled rows on the target date
                // (from race conditions or stale frontend POSTs).
                Schedule::where('student_course_id', $courseId)
                    ->whereDate('schedule_date', $newDate)
                    ->where('status', 'scheduled')
                    ->where('original_schedule_id', $rescheduledRow->id)
                    ->where('id', '!=', $scheduledRow->id)
                    ->delete();
            }
        }
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

        $classes = StudentClass::whereIn('StudentID', $studentIds)
            ->where('Stop', 0)
            ->get();

        $created = 0;

        foreach ($classes as $sc) {
            $sessions = ClassSession::where('StudentClassID', $sc->ID)
                ->whereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted'])
                ->whereRaw("CONCAT(SessionDate, ' ', COALESCE(StartTime, '00:00:00')) <= ?", [$now])
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

                $subjectName = DB::table('Subject')->where('id', $sc->SubjectID)->value('Subject_Name')
                    ?? DB::table('BaseData')->where('Name', '課程')->where('id', $sc->SubjectID)->value('Val')
                    ?? '未知';

                $tid = SubstituteScheduleService::effectiveInstructorUserId(
                    (int) $sc->ID,
                    $cs->SessionDate,
                    (int) ($sc->TeacherID ?? 0)
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
            $classSession->SessionDate
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
        $sub = SubstituteScheduleService::resolveSubstituteUserId((int) $cs->StudentClassID, $cs->SessionDate);

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
        $sub = SubstituteScheduleService::resolveSubstituteUserId((int) $sc->ID, $cs->SessionDate);
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
        if (!$date || !$time) {
            return null;
        }

        try {
            $timezone = config('app.timezone', 'Asia/Taipei');
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
