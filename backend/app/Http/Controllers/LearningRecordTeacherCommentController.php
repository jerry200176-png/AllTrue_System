<?php

namespace App\Http\Controllers;

use App\Models\LearningRecord;
use App\Models\LearningRecordTeacherComment;
use App\Models\Student;
use App\Models\StudentClass;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LearningRecordTeacherCommentController extends Controller
{
    public function upsert(Request $request, LearningRecord $learningRecord)
    {
        $role = (string) $request->attributes->get('auth_role');
        if (!in_array($role, ['director', 'admin', 'super_admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $ctx = $this->recordContext($learningRecord);
        if (!$ctx) {
            return response()->json(['message' => 'Learning record context missing'], 409);
        }
        $auth = $this->authorizeDirectorCampus($request, (int) $ctx['campus_id']);
        if ($auth !== true) {
            return $auth;
        }

        $content = trim((string) $request->input('content', ''));
        if ($content === '' || mb_strlen($content) > 500) {
            return response()->json(['message' => '主任評語需為 1-500 字'], 422);
        }

        $comment = LearningRecordTeacherComment::updateOrCreate(
            ['learning_record_id' => (int) $learningRecord->id],
            [
                'student_id' => $ctx['student_id'],
                'student_class_id' => $ctx['student_class_id'],
                'class_session_id' => $ctx['class_session_id'],
                'teacher_id' => $ctx['teacher_id'],
                'campus_id' => $ctx['campus_id'],
                'author_user_id' => $this->authUserId($request),
                'content' => $content,
                'last_read_by_teacher_at' => null,
            ]
        );

        return response()->json([
            'comment' => $this->formatComment($comment),
            'message' => '已儲存給老師的評語',
        ]);
    }

    public function markRead(Request $request, LearningRecordTeacherComment $comment)
    {
        $role = (string) $request->attributes->get('auth_role');
        if ($role !== 'teacher') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $teacherId = (int) $request->attributes->get('auth_teacher_id');
        if ($teacherId <= 0 || $teacherId !== (int) $comment->teacher_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        DB::table($comment->getTable())
            ->where('id', $comment->id)
            ->update(['last_read_by_teacher_at' => now()]);

        return response()->json(['message' => 'ok']);
    }

    private function authorizeDirectorCampus(Request $request, int $campusId)
    {
        $role = (string) $request->attributes->get('auth_role');
        if ($role === 'super_admin') {
            return true;
        }
        $campusIds = array_map('intval', $request->attributes->get('auth_campus_ids', []));
        if ($campusId <= 0 || !in_array($campusId, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return true;
    }

    private function authUserId(Request $request): int
    {
        $user = $request->attributes->get('auth_user');
        return (int) ($user->id ?? 0);
    }

    private function recordContext(LearningRecord $record): ?array
    {
        $class = StudentClass::find($record->StudentClassID);
        $studentId = (int) ($record->StudentID ?: ($class->StudentID ?? 0));
        $student = $studentId > 0 ? Student::find($studentId) : null;
        if (!$class || !$student) {
            return null;
        }

        return [
            'student_id' => $studentId,
            'student_class_id' => (int) $record->StudentClassID,
            'class_session_id' => $record->ClassSessionID ? (int) $record->ClassSessionID : null,
            'teacher_id' => (int) $record->TeacherID,
            'campus_id' => (int) $student->CampusID,
        ];
    }

    private function formatComment(LearningRecordTeacherComment $comment): array
    {
        $authorName = DB::table('User')->where('id', $comment->author_user_id)->value('Name');
        $teacherName = DB::table('Teacher')->where('id', $comment->teacher_id)->value('T_Name')
            ?? DB::table('User')->where('id', $comment->teacher_id)->value('Name');

        return [
            'id' => (int) $comment->id,
            'learning_record_id' => (int) $comment->learning_record_id,
            'teacher_id' => (int) $comment->teacher_id,
            'teacher_name' => $teacherName,
            'author_user_id' => (int) $comment->author_user_id,
            'author_name' => $authorName,
            'content' => $comment->content,
            'updated_at' => optional($comment->updated_at)->toIso8601String(),
            'unread_for_teacher' => !$comment->last_read_by_teacher_at || $comment->last_read_by_teacher_at->lt($comment->updated_at),
        ];
    }
}
