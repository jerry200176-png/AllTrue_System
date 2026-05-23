<?php

namespace App\Http\Controllers;

use App\Models\LearningRecord;
use App\Models\LearningRecordFeedback;
use App\Models\ParentSession;
use App\Models\Student;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LearningRecordFeedbackController extends Controller
{
    public function parentShow(Request $request, LearningRecord $learningRecord)
    {
        $session = $this->resolveParentSession($request);
        if (!$session) return response()->json(['message' => 'Unauthorized'], 401);
        $auth = $this->authorizeParentRecord($session, $learningRecord);
        if ($auth !== true) return $auth;

        return response()->json([
            'feedback' => $this->formatParentFeedback($learningRecord->feedback),
        ]);
    }

    public function parentUpsert(Request $request, LearningRecord $learningRecord)
    {
        $session = $this->resolveParentSession($request);
        if (!$session) return response()->json(['message' => 'Unauthorized'], 401);
        $auth = $this->authorizeParentRecord($session, $learningRecord);
        if ($auth !== true) return $auth;

        $content = trim((string) $request->input('content', ''));
        if ($content === '' || mb_strlen($content) > 500) {
            return response()->json(['message' => '回饋內容需為 1-500 字'], 422);
        }

        $ctx = $this->recordContext($learningRecord);
        if (!$ctx) return response()->json(['message' => 'Learning record context missing'], 409);

        $feedback = LearningRecordFeedback::updateOrCreate(
            ['learning_record_id' => $learningRecord->id],
            [
                'student_id' => $ctx['student_id'],
                'student_class_id' => $ctx['student_class_id'],
                'class_session_id' => $ctx['class_session_id'],
                'teacher_id' => $ctx['teacher_id'],
                'campus_id' => $ctx['campus_id'],
                'content' => $content,
                'parent_session_id' => $session->id,
                'last_read_by_teacher_at' => null,
                'last_read_by_director_at' => null,
            ]
        );

        return response()->json([
            'feedback' => $this->formatParentFeedback($feedback),
            'message' => '已送出給老師',
        ]);
    }

    public function index(Request $request)
    {
        $role = (string) $request->attributes->get('auth_role');
        $query = LearningRecordFeedback::query()
            ->with('learningRecord')
            ->orderBy('updated_at', 'desc');

        if ($role === 'teacher') {
            $teacherId = (int) $request->attributes->get('auth_teacher_id');
            if (!$teacherId) return response()->json(['message' => 'Teacher not linked'], 403);
            $query->where('teacher_id', $teacherId);
        } else {
            $campusIds = $role === 'super_admin' ? [] : array_map('intval', $request->attributes->get('auth_campus_ids', []));
            $branchId = (int) ($request->input('branch_id') ?: $request->input('campus_id'));
            if ($branchId > 0) {
                if ($role !== 'super_admin' && !in_array($branchId, $campusIds, true)) {
                    return response()->json(['message' => 'Forbidden: branch not accessible'], 403);
                }
                $query->where('campus_id', $branchId);
            } elseif ($role !== 'super_admin') {
                if (empty($campusIds)) return response()->json(['message' => 'Campus required'], 403);
                $query->whereIn('campus_id', $campusIds);
            }
            if ($request->filled('teacher_id')) {
                $query->where('teacher_id', (int) $request->input('teacher_id'));
            }
        }

        if ($request->boolean('unread')) {
            $col = $role === 'teacher' ? 'last_read_by_teacher_at' : 'last_read_by_director_at';
            $query->where(fn ($q) => $q->whereNull($col)->orWhereColumn($col, '<', 'updated_at'));
        }

        $perPage = min(max((int) $request->input('per_page', 50), 1), 100);
        $page = $query->paginate($perPage);
        $items = $page->getCollection()->map(fn ($f) => $this->formatStaffFeedback($f));

        return response()->json([
            'data' => $items->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function markRead(Request $request, LearningRecordFeedback $feedback)
    {
        $role = (string) $request->attributes->get('auth_role');
        $readAt = now();
        if ($role === 'teacher') {
            $teacherId = (int) $request->attributes->get('auth_teacher_id');
            if ($teacherId !== (int) $feedback->teacher_id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            DB::table($feedback->getTable())
                ->where('id', $feedback->id)
                ->update(['last_read_by_teacher_at' => $readAt]);
        } else {
            $campusIds = $role === 'super_admin' ? [] : array_map('intval', $request->attributes->get('auth_campus_ids', []));
            if ($role !== 'super_admin' && !in_array((int) $feedback->campus_id, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            DB::table($feedback->getTable())
                ->where('id', $feedback->id)
                ->update(['last_read_by_director_at' => $readAt]);
        }

        return response()->json(['message' => 'ok']);
    }

    public function unreadCount(Request $request)
    {
        $role = (string) $request->attributes->get('auth_role');
        $query = LearningRecordFeedback::query();

        if ($role === 'teacher') {
            $teacherId = (int) $request->attributes->get('auth_teacher_id');
            if (!$teacherId) return response()->json(['message' => 'Teacher not linked'], 403);
            $query->where('teacher_id', $teacherId);
            $readAtColumn = 'last_read_by_teacher_at';
        } else {
            $campusIds = $role === 'super_admin' ? [] : array_map('intval', $request->attributes->get('auth_campus_ids', []));
            $branchId = (int) ($request->input('branch_id') ?: $request->input('campus_id'));
            if ($branchId > 0) {
                if ($role !== 'super_admin' && !in_array($branchId, $campusIds, true)) {
                    return response()->json(['message' => 'Forbidden: branch not accessible'], 403);
                }
                $query->where('campus_id', $branchId);
            } elseif ($role !== 'super_admin') {
                if (empty($campusIds)) return response()->json(['message' => 'Campus required'], 403);
                $query->whereIn('campus_id', $campusIds);
            }
            $readAtColumn = 'last_read_by_director_at';
        }

        $count = $query
            ->where(fn ($q) => $q->whereNull($readAtColumn)->orWhereColumn($readAtColumn, '<', 'updated_at'))
            ->count();

        return response()->json(['count' => (int) $count]);
    }

    public function analytics(Request $request)
    {
        $role = (string) $request->attributes->get('auth_role');
        $days = min(30, max(1, (int) $request->input('days', 7)));
        $windowStart = Carbon::now()->subDays($days - 1)->startOfDay();
        $windowEnd = Carbon::now()->endOfDay();
        $branchId = (int) ($request->input('branch_id') ?: $request->input('campus_id'));

        $campusIds = array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));
        if ($role !== 'super_admin' && $role !== 'teacher' && empty($campusIds)) {
            return response()->json(['message' => 'Campus required'], 403);
        }
        if ($role !== 'super_admin' && $role !== 'teacher' && $branchId > 0 && !in_array($branchId, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden: branch not accessible'], 403);
        }

        $teacherId = 0;
        if ($role === 'teacher') {
            $teacherId = (int) $request->attributes->get('auth_teacher_id');
            if ($teacherId <= 0) {
                return response()->json(['message' => 'Teacher not linked'], 403);
            }
        }

        $approvedQuery = DB::table('LearningRecord as lr')
            ->join('Student as st', 'st.id', '=', 'lr.StudentID')
            ->where('lr.Status', 'approved')
            ->whereNotNull('lr.ApprovedAt')
            ->whereBetween('lr.ApprovedAt', [$windowStart, $windowEnd]);

        if ($teacherId > 0) {
            $approvedQuery->where('lr.TeacherID', $teacherId);
        } elseif ($role !== 'super_admin') {
            if ($branchId > 0) {
                $approvedQuery->where('st.CampusID', $branchId);
            } else {
                $approvedQuery->whereIn('st.CampusID', $campusIds);
            }
        } elseif ($branchId > 0) {
            $approvedQuery->where('st.CampusID', $branchId);
        }

        if ($branchId > 0 && $teacherId > 0) {
            $approvedQuery->where('st.CampusID', $branchId);
        }

        $approvedRows = $approvedQuery->get([
            'lr.id as learning_record_id',
            'lr.TeacherID as teacher_id',
            'st.CampusID as campus_id',
            'lr.SessionDate as session_date',
            'lr.Subject as subject',
            'lr.ApprovedAt as approved_at',
            'st.name as student_name',
        ]);

        $approvedTotal = $approvedRows->count();
        if ($approvedTotal === 0) {
            return response()->json([
                'data' => [
                    'window' => ['start' => $windowStart->toDateString(), 'end' => $windowEnd->toDateString(), 'days' => $days],
                    'summary' => [
                        'approved_records' => 0,
                        'replied_records' => 0,
                        'reply_rate_pct' => 0.0,
                        'unreplied_records' => 0,
                        'new_replies_unread' => 0,
                    ],
                    'by_teacher' => [],
                    'pending_preview' => [],
                ],
                'meta' => ['scope_role' => $role, 'branch_id' => $branchId > 0 ? $branchId : null],
            ]);
        }

        $approvedIds = $approvedRows->pluck('learning_record_id')->map(fn ($id) => (int) $id)->all();
        $feedbackRows = LearningRecordFeedback::query()
            ->whereIn('learning_record_id', $approvedIds)
            ->get(['learning_record_id', 'teacher_id', 'last_read_by_teacher_at', 'last_read_by_director_at', 'updated_at']);

        $repliedIds = $feedbackRows->pluck('learning_record_id')->map(fn ($id) => (int) $id)->unique()->values();
        $repliedTotal = $repliedIds->count();
        $unrepliedSet = array_fill_keys(array_diff($approvedIds, $repliedIds->all()), true);
        $unrepliedTotal = count($unrepliedSet);
        $replyRate = $approvedTotal > 0 ? round(($repliedTotal / $approvedTotal) * 100, 1) : 0.0;

        $unreadNew = $feedbackRows->filter(function ($row) use ($role) {
            if ($role === 'teacher') {
                return !$row->last_read_by_teacher_at || $row->last_read_by_teacher_at->lt($row->updated_at);
            }
            return !$row->last_read_by_director_at || $row->last_read_by_director_at->lt($row->updated_at);
        })->count();

        $approvedByTeacher = [];
        foreach ($approvedRows as $row) {
            $tid = (int) $row->teacher_id;
            if ($tid <= 0) {
                continue;
            }
            $approvedByTeacher[$tid] = ($approvedByTeacher[$tid] ?? 0) + 1;
        }
        $repliedByTeacher = [];
        foreach ($feedbackRows as $row) {
            $tid = (int) $row->teacher_id;
            if ($tid <= 0) {
                continue;
            }
            $repliedByTeacher[$tid] = ($repliedByTeacher[$tid] ?? 0) + 1;
        }

        $teacherIds = array_keys($approvedByTeacher);
        $teacherNames = empty($teacherIds)
            ? collect()
            : DB::table('User')->whereIn('id', $teacherIds)->pluck('Name', 'id');

        $byTeacher = collect($teacherIds)->map(function ($tid) use ($approvedByTeacher, $repliedByTeacher, $teacherNames) {
            $approved = (int) ($approvedByTeacher[$tid] ?? 0);
            $replied = (int) ($repliedByTeacher[$tid] ?? 0);
            $unreplied = max(0, $approved - $replied);
            return [
                'teacher_id' => (int) $tid,
                'teacher_name' => (string) ($teacherNames[$tid] ?? ('#' . $tid)),
                'approved_records' => $approved,
                'replied_records' => $replied,
                'unreplied_records' => $unreplied,
                'reply_rate_pct' => $approved > 0 ? round(($replied / $approved) * 100, 1) : 0.0,
            ];
        })
            ->sortBy([
                ['unreplied_records', 'desc'],
                ['reply_rate_pct', 'asc'],
                ['teacher_name', 'asc'],
            ])
            ->values()
            ->all();

        $pendingPreview = [];
        foreach ($approvedRows as $row) {
            if (!isset($unrepliedSet[(int) $row->learning_record_id])) {
                continue;
            }
            if (count($pendingPreview) >= 5) {
                break;
            }
            $pendingPreview[] = [
                'learning_record_id' => (int) $row->learning_record_id,
                'student_name' => (string) ($row->student_name ?? ''),
                'teacher_id' => (int) $row->teacher_id,
                'teacher_name' => (string) ($teacherNames[(int) $row->teacher_id] ?? ('#' . (int) $row->teacher_id)),
                'session_date' => (string) ($row->session_date ?? ''),
                'subject' => (string) ($row->subject ?? ''),
                'approved_at' => optional($row->approved_at)->toIso8601String(),
            ];
        }

        return response()->json([
            'data' => [
                'window' => ['start' => $windowStart->toDateString(), 'end' => $windowEnd->toDateString(), 'days' => $days],
                'summary' => [
                    'approved_records' => $approvedTotal,
                    'replied_records' => $repliedTotal,
                    'reply_rate_pct' => $replyRate,
                    'unreplied_records' => $unrepliedTotal,
                    'new_replies_unread' => (int) $unreadNew,
                ],
                'by_teacher' => $byTeacher,
                'pending_preview' => $pendingPreview,
            ],
            'meta' => ['scope_role' => $role, 'branch_id' => $branchId > 0 ? $branchId : null],
        ]);
    }

    private function resolveParentSession(Request $request): ?ParentSession
    {
        $token = $request->bearerToken();
        if (!$token) return null;

        return ParentSession::where('TokenHash', hash('sha256', $token))
            ->where('ExpiresAt', '>', Carbon::now())
            ->first();
    }

    private function authorizeParentRecord(ParentSession $session, LearningRecord $record)
    {
        $ctx = $this->recordContext($record);
        if (!$ctx) return response()->json(['message' => 'Learning record context missing'], 409);
        if ((int) $ctx['student_id'] !== (int) $session->StudentID) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($record->VoidedAt !== null || (string) $record->Status !== 'approved') {
            return response()->json(['message' => 'Only approved records can receive feedback'], 409);
        }

        return true;
    }

    private function recordContext(LearningRecord $record): ?array
    {
        $class = StudentClass::find($record->StudentClassID);
        $studentId = (int) ($record->StudentID ?: ($class->StudentID ?? 0));
        $student = $studentId > 0 ? Student::find($studentId) : null;
        if (!$class || !$student) return null;

        return [
            'student_id' => $studentId,
            'student_class_id' => (int) $record->StudentClassID,
            'class_session_id' => $record->ClassSessionID ? (int) $record->ClassSessionID : null,
            'teacher_id' => (int) $record->TeacherID,
            'campus_id' => (int) $student->CampusID,
        ];
    }

    private function formatParentFeedback(?LearningRecordFeedback $f): ?array
    {
        if (!$f) return null;
        return [
            'id' => (int) $f->id,
            'learning_record_id' => (int) $f->learning_record_id,
            'content' => $f->content,
            'created_at' => optional($f->created_at)->toIso8601String(),
            'updated_at' => optional($f->updated_at)->toIso8601String(),
        ];
    }

    private function formatStaffFeedback(LearningRecordFeedback $f): array
    {
        $student = Student::find($f->student_id);
        $teacherName = \Illuminate\Support\Facades\DB::table('User')->where('id', $f->teacher_id)->value('Name');
        $record = $f->learningRecord;

        return [
            'id' => (int) $f->id,
            'learning_record_id' => (int) $f->learning_record_id,
            'student_id' => (int) $f->student_id,
            'student_name' => $student->name ?? null,
            'teacher_id' => (int) $f->teacher_id,
            'teacher_name' => $teacherName,
            'campus_id' => (int) $f->campus_id,
            'session_date' => $record?->SessionDate,
            'subject' => $record?->Subject,
            'content' => $f->content,
            'updated_at' => optional($f->updated_at)->toIso8601String(),
            'unread_for_teacher' => !$f->last_read_by_teacher_at || $f->last_read_by_teacher_at->lt($f->updated_at),
            'unread_for_director' => !$f->last_read_by_director_at || $f->last_read_by_director_at->lt($f->updated_at),
        ];
    }
}
