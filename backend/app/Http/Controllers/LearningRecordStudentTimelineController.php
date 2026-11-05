<?php

namespace App\Http\Controllers;

use App\Models\AssessmentResult;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Support\AttendanceStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET students/{student}/learning-timeline (#4928 step 1): staff read-only view that merges three existing
 * sources into one newest-first list: LearningRecord evaluations (with their lesson's sign-in status),
 * formal assessment scores, and sign-ins for lessons that have no evaluation. No new fields, no writes.
 * Access = viewing the student (campus) plus, for a teacher, only courses visible to them.
 */
class LearningRecordStudentTimelineController extends Controller
{
    private const PER_SOURCE_LIMIT = 300;

    public function show(Request $request, Student $student): JsonResponse
    {
        $role = $request->attributes->get('auth_role');
        if (!in_array($role, ['director', 'teacher', 'super_admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($role !== 'super_admin'
            && !in_array((int) $student->CampusID, array_map('intval', (array) $request->attributes->get('auth_campus_ids', [])), true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $courses = StudentClass::query()->where('StudentID', $student->id)
            ->when($role === 'teacher', fn ($q) => $q->visibleToTeacher((int) $request->attributes->get('auth_teacher_id')))
            ->get(['ID', 'SubjectID']);
        if ($role === 'teacher' && $courses->isEmpty()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $courseIds = $courses->pluck('ID')->all();
        $subjectNames = DB::table('Subject')->pluck('Subject_Name', 'id');
        $courseSubject = $courses->pluck('SubjectID', 'ID');

        $records = LearningRecord::active()->whereIn('StudentClassID', $courseIds)
            ->orderByDesc('SessionDate')->orderByDesc('id')->limit(self::PER_SOURCE_LIMIT)->get();
        $signIns = DB::table('StudentSingIn')->where('StudentID', $student->id)->whereNull('VoidedAt')
            ->whereNotNull('ClassSessionID')->whereIn('StudentClassID', $courseIds)
            ->pluck('Status', 'ClassSessionID');
        $statusLabel = fn ($code) => $code === null ? null : AttendanceStatus::label((string) $code);

        $items = [];
        foreach ($records as $r) {
            $subjectId = $courseSubject[$r->StudentClassID] ?? null;
            $items[] = [
                'type' => 'evaluation', 'id' => $r->id, 'date' => substr((string) $r->SessionDate, 0, 10),
                'subject_id' => $subjectId, 'subject' => $subjectNames[$subjectId] ?? ($r->Subject ?: null),
                'homework_status' => $r->HomeworkStatus, 'quiz' => $r->QuizScore, 'next_week_scope' => $r->NextWeekTestScope,
                'approval_status' => $r->Status, 'attendance' => $statusLabel($signIns[$r->ClassSessionID] ?? null),
            ];
        }

        $evaluated = $records->pluck('ClassSessionID')->filter()->all();
        $sessions = DB::table('ClassSession')->whereIn('id', $signIns->keys()->diff($evaluated)->all())
            ->orderByDesc('SessionDate')->limit(self::PER_SOURCE_LIMIT)->get(['id', 'StudentClassID', 'SessionDate', 'SubjectID']);
        foreach ($sessions as $s) {
            $subjectId = $s->SubjectID ?: ($courseSubject[$s->StudentClassID] ?? null);
            $items[] = [
                'type' => 'attendance', 'id' => $s->id, 'date' => substr((string) $s->SessionDate, 0, 10),
                'subject_id' => $subjectId, 'subject' => $subjectNames[$subjectId] ?? null,
                'attendance' => $statusLabel($signIns[$s->id] ?? null),
            ];
        }

        $results = AssessmentResult::query()->with('assessment')->where('student_id', $student->id)
            ->when($role === 'teacher', fn ($q) => $q->whereIn('student_class_id', $courseIds))
            ->orderByDesc('id')->limit(self::PER_SOURCE_LIMIT)->get();
        foreach ($results as $res) {
            $a = $res->assessment;
            $subjectId = $a?->subject_id ?: ($courseSubject[$res->student_class_id] ?? null);
            $items[] = [
                'type' => 'assessment', 'id' => $res->id,
                'date' => substr((string) ($res->recorded_at ?? $a->scheduled_for ?? $res->created_at), 0, 10),
                'subject_id' => $subjectId, 'subject' => $subjectNames[$subjectId] ?? null,
                'title' => $a?->title, 'score' => (float) $res->score, 'max_score' => (float) $res->max_score_snapshot,
                'percent' => (float) $res->percent, 'approval_status' => $res->status,
            ];
        }

        // ponytail: newest-first merge capped at PER_SOURCE_LIMIT per source; add paging if a student exceeds it.
        usort($items, fn ($x, $y) => [$y['date'], $y['id']] <=> [$x['date'], $x['id']]);

        return response()->json(['items' => $items]);
    }
}
