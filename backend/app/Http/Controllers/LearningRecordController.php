<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class LearningRecordController extends Controller
{
    public function index(Request $request)
    {
        $query = LearningRecord::query();
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);

        if ($role === 'teacher') {
            $teacherId = $request->attributes->get('auth_teacher_id');
            if (!$teacherId) {
                return response()->json(['message' => 'Teacher not linked'], 403);
            }
            $query->where('TeacherID', $teacherId);
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
            $query->where('TeacherID', $request->input('teacher_id'));
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

        $perPage = min((int) $request->input('per_page', 20), 200);
        $records = $query->with('studentClass.student')
            ->orderBy('SessionDate', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        $records->getCollection()->transform(function ($record) {
            $record->student_name = $record->studentClass->student->name ?? 'Unknown';
            $record->student_id = $record->studentClass->student->id ?? null;
            $record->student_class_label = $record->studentClass->Subject ?? $record->Subject;
            $teacherName = \Illuminate\Support\Facades\DB::table('Teacher')->where('id', $record->TeacherID)->value('T_Name')
                ?? \Illuminate\Support\Facades\DB::table('User')->where('id', $record->TeacherID)->value('Name');
            $record->teacher_name = $teacherName ?? 'Unknown';
            return $record;
        });

        return response()->json($records);
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
            'Performance' => 'nullable|string|max:16',
            'Comment' => 'nullable|string|max:2000',
        ]);

        return DB::transaction(function () use ($data) {
            $studentId = $data['StudentID'];
            $subjectName = $data['Subject'] ?? '數學';
            $subjectId = \Illuminate\Support\Facades\DB::table('Subject')->where('Subject_Name', 'like', "%$subjectName%")->value('id') ??
                \Illuminate\Support\Facades\DB::table('BaseData')->where('Name', '課程')->where('Val', 'like', "%$subjectName%")->value('id') ?? 1;

            $studentClass = StudentClass::where('StudentID', $studentId)
                ->where('TeacherID', $data['TeacherID'])
                ->where('SubjectID', $subjectId)
                ->where('Stop', 0)
                ->first();

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
            if ($role === 'teacher') {
                $teacherId = request()->attributes->get('auth_teacher_id');
                if (!$teacherId || (int) $data['TeacherID'] !== (int) $teacherId) {
                    return response()->json(['message' => 'Forbidden'], 403);
                }
            }

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

            $existing = LearningRecord::where('ClassSessionID', $classSessionId)->first();
            if ($existing) {
                return response()->json(['message' => 'Learning record already exists'], 409);
            }

            $authUser = request()->attributes->get('auth_user');
            $content = $data['Content'] ?? ($data['Progress'] ?? '') ?: '（評量表）';

            $record = LearningRecord::create([
                'StudentClassID' => $studentClass->ID,
                'ClassSessionID' => $classSessionId,
                'TeacherID' => $data['TeacherID'],
                'CreatedByUserID' => $authUser ? (int) $authUser->id : null,
                'Content' => $content,
                'AttachmentUrl' => $data['AttachmentUrl'] ?? null,
                'Subject' => $data['Subject'] ?? null,
                'SessionDate' => isset($data['SessionDate']) ? $data['SessionDate'] : null,
                'StartTime' => $data['StartTime'] ?? null,
                'EndTime' => $data['EndTime'] ?? null,
                'HomeworkStatus' => $data['HomeworkStatus'] ?? null,
                'QuizScore' => $data['QuizScore'] ?? null,
                'Progress' => $data['Progress'] ?? null,
                'NextHomework' => $data['NextHomework'] ?? null,
                'Performance' => $data['Performance'] ?? null,
                'Comment' => $data['Comment'] ?? null,
                'Status' => 'pending',
            ]);

            return response()->json($record, 201);
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
            'Performance' => 'nullable|string|max:16',
            'Comment' => 'nullable|string|max:2000',
        ]);

        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        if ($role === 'teacher') {
            $teacherId = $request->attributes->get('auth_teacher_id');
            if (!$teacherId || (int) $learningRecord->TeacherID !== (int) $teacherId) {
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

        if (!$isDirector && !in_array($learningRecord->Status, ['rejected', 'changes_requested'], true)) {
            return response()->json(['message' => 'Record is not editable'], 409);
        }

        $learningRecord->Content = $data['Content'] ?? $learningRecord->Content;
        $learningRecord->AttachmentUrl = $data['AttachmentUrl'] ?? null;
        foreach (['Subject', 'SessionDate', 'StartTime', 'EndTime', 'HomeworkStatus', 'QuizScore', 'Progress', 'NextHomework', 'Performance', 'Comment'] as $key) {
            if (array_key_exists($key, $data)) {
                $learningRecord->$key = $data[$key];
            }
        }

        if ($isDirector && $learningRecord->Status === 'approved') {
            // Director editing an approved record keeps it approved
        } else {
            $learningRecord->Status = 'pending';
            $learningRecord->ReviewNote = null;
            $learningRecord->ApprovedBy = null;
            $learningRecord->ApprovedAt = null;
        }
        $learningRecord->save();

        return response()->json($learningRecord);
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

            // 堂數扣除已改為刷卡簽到時觸發，核准評量僅更新老師業績
            // 給授課老師加一堂課 (業績+1)
            User::where('id', $learningRecord->TeacherID)->increment('TeachingSessionCount');

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
        $classIds = $classIds->pluck('ID');

        $query = LearningRecord::whereIn('StudentClassID', $classIds)
            ->whereIn('Status', ['pending', 'changes_requested']);

        if (!empty($data['teacher_id'])) {
            $query->where('TeacherID', (int) $data['teacher_id']);
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
                // 堂數扣除已改為刷卡簽到時觸發
                User::where('id', $learningRecord->TeacherID)->increment('TeachingSessionCount');
                $approved++;
            }
            return response()->json(['message' => "已核准 {$approved} 筆評量", 'approved' => $approved]);
        });
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

            $classSession = ClassSession::create([
                'StudentClassID' => $studentClass->ID,
                'SessionDate' => $data['SessionDate'],
                'StartTime' => '00:00',
                'EndTime' => '00:00',
                'Status' => 'completed',
            ]);

            $record = LearningRecord::create([
                'StudentClassID' => $studentClass->ID,
                'ClassSessionID' => $classSession->id,
                'TeacherID' => $data['TeacherID'],
                'CreatedByUserID' => $data['DirectorID'],
                'Content' => '（系統補登/扣除堂數）',
                'Subject' => $studentClass->Subject ?? '系統扣堂',
                'SessionDate' => $data['SessionDate'],
                'Status' => 'approved',
                'ApprovedBy' => $data['DirectorID'],
                'ApprovedAt' => now(),
                'ExcludeFromSubjectCount' => 1, // 補登空白評量(單一課程) 不算入老師科目數
            ]);

            // 補登單筆：堂數扣除已改為刷卡簽到時觸發，此處不再扣堂

            return response()->json($record, 201);
        });
    }

    /**
     * 主任一鍵補登：依多個上課日期一次建立多筆已核准空白評量並扣堂（系統使用前已上課適用）。
     * POST /api/v1/learning-records/bulk-backdoor-approve
     */
    public function bulkBackdoorApprove(Request $request)
    {
        $data = $request->validate([
            'StudentClassID' => 'required|integer',
            'TeacherID' => 'required|integer',
            'DirectorID' => 'required|integer',
            'session_dates' => 'required|array',
            'session_dates.*' => 'required|date',
            'teacher_per_date' => 'nullable|array',
            'teacher_per_date.*' => 'integer',
        ]);

        $sessionDates = array_values(array_unique($data['session_dates']));
        $teacherPerDate = $data['teacher_per_date'] ?? [];
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
        return DB::transaction(function () use ($data, $studentClass, $subjectName, $sessionDates, $teacherPerDate, &$created, &$approved) {
            foreach ($sessionDates as $sessionDate) {
                $teacherId = isset($teacherPerDate[$sessionDate]) ? (int) $teacherPerDate[$sessionDate] : (int) $data['TeacherID'];
                $classSession = ClassSession::where('StudentClassID', $studentClass->ID)
                    ->where('SessionDate', $sessionDate)
                    ->first();

                if ($classSession) {
                    $record = LearningRecord::where('ClassSessionID', $classSession->id)->first();
                    if ($record) {
                        if ($record->Status === 'approved') {
                            continue;
                        }
                        $record->Status = 'approved';
                        $record->ApprovedBy = $data['DirectorID'];
                        $record->ApprovedAt = now();
                        if ($teacherId && $record->TeacherID != $teacherId) {
                            $record->TeacherID = $teacherId;
                        }
                        $record->save();
                        $approved++;
                        continue;
                    }
                    LearningRecord::create([
                        'StudentClassID' => $studentClass->ID,
                        'ClassSessionID' => $classSession->id,
                        'TeacherID' => $teacherId,
                        'CreatedByUserID' => $data['DirectorID'],
                        'Content' => '（系統補登）',
                        'Subject' => $subjectName,
                        'SessionDate' => $sessionDate,
                        'Status' => 'approved',
                        'ApprovedBy' => $data['DirectorID'],
                        'ApprovedAt' => now(),
                    ]);
                    $created++;
                    continue;
                }

                $classSession = ClassSession::create([
                    'StudentClassID' => $studentClass->ID,
                    'SessionDate' => $sessionDate,
                    'StartTime' => '00:00',
                    'EndTime' => '00:00',
                    'Status' => 'completed',
                ]);
                LearningRecord::create([
                    'StudentClassID' => $studentClass->ID,
                    'ClassSessionID' => $classSession->id,
                    'TeacherID' => $teacherId,
                    'CreatedByUserID' => $data['DirectorID'],
                    'Content' => '（系統補登）',
                    'Subject' => $subjectName,
                    'SessionDate' => $sessionDate,
                    'Status' => 'approved',
                    'ApprovedBy' => $data['DirectorID'],
                    'ApprovedAt' => now(),
                ]);
                $created++;
            }
            $total = $created + $approved;
            if ($total > 0) {
                $parts = [];
                if ($created > 0) $parts[] = "新增 {$created} 筆";
                if ($approved > 0) $parts[] = "核准待審 {$approved} 筆";
                $message = '已補登 ' . $total . ' 筆';
            } else {
                $message = '所選日期皆已為已核准紀錄，未變更';
            }
            return response()->json(['message' => $message, 'created' => $created, 'approved' => $approved], 201);
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
        ]);
        $classId   = (int) $data['student_class_id'];
        $oldDate   = $data['old_date'] ?? null;
        $newDate   = $data['new_date'];
        $startTime = $data['start_time'] ?? null;
        $endTime   = $data['end_time'] ?? null;

        $studentClass = StudentClass::find($classId);
        if (!$studentClass) {
            return response()->json(['message' => '找不到課程'], 404);
        }

        // Try to find an existing ClassSession on the old date to move
        $session = null;
        if ($oldDate) {
            $session = ClassSession::where('StudentClassID', $classId)
                ->where('SessionDate', $oldDate)
                ->first();
        }

        if ($session) {
            $session->SessionDate = $newDate;
            if ($startTime) $session->StartTime = $startTime;
            if ($endTime)   $session->EndTime   = $endTime;
            $session->save();
            LearningRecord::where('ClassSessionID', $session->id)->update(['SessionDate' => $newDate]);
            return response()->json(['message' => '已同步更新評量表日期', 'session_id' => $session->id], 200);
        }

        // No existing session to move: ensure one exists on new_date for RFID matching
        $existing = ClassSession::where('StudentClassID', $classId)
            ->where('SessionDate', $newDate)
            ->first();
        if ($existing) {
            if ($startTime) { $existing->StartTime = $startTime; $existing->save(); }
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

        $today = Carbon::today()->toDateString();

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
                ->where('SessionDate', '<=', $today)
                ->whereNotIn('Status', ['cancelled'])
                ->get();

            foreach ($sessions as $cs) {
                $exists = LearningRecord::where('ClassSessionID', $cs->id)->exists();
                if ($exists) continue;

                $subjectName = DB::table('Subject')->where('id', $sc->SubjectID)->value('Subject_Name')
                    ?? DB::table('BaseData')->where('Name', '課程')->where('id', $sc->SubjectID)->value('Val')
                    ?? '未知';

                LearningRecord::create([
                    'StudentClassID' => $sc->ID,
                    'ClassSessionID' => $cs->id,
                    'TeacherID' => $sc->TeacherID ?: 0,
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

}
