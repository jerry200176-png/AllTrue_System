<?php

namespace App\Http\Controllers;

use App\Models\LearningRecord;
use App\Models\LearningRecordFeedback;
use App\Models\ParentSession;
use App\Models\Student;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Http\Request;

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
        if ($role === 'teacher') {
            $teacherId = (int) $request->attributes->get('auth_teacher_id');
            if ($teacherId !== (int) $feedback->teacher_id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $feedback->last_read_by_teacher_at = now();
        } else {
            $campusIds = $role === 'super_admin' ? [] : array_map('intval', $request->attributes->get('auth_campus_ids', []));
            if ($role !== 'super_admin' && !in_array((int) $feedback->campus_id, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $feedback->last_read_by_director_at = now();
        }
        $feedback->save();

        return response()->json(['message' => 'ok']);
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
