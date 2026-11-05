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

        $teacherId = $role === 'teacher' ? (int) $request->attributes->get('auth_teacher_id') : null;
        $courses = StudentClass::query()->where('StudentID', $student->id)->get(['ID', 'SubjectID', 'TeacherID']);
        $courseIds = $courses->pluck('ID')->all();
        $subjectNames = DB::table('Subject')->pluck('Subject_Name', 'id');
        $courseSubject = $courses->pluck('SubjectID', 'ID');

        $recordQuery = LearningRecord::active()->whereIn('StudentClassID', $courseIds);
        $sessionIds = null;
        if ($teacherId !== null) {
            // Same rule as GET learning-records for a teacher; sign-ins follow the lessons that rule lets them see.
            LearningRecordController::applyTeacherScopedLearningRecordConstraint($recordQuery, $teacherId);
            $sessionIds = $this->lessonsTaughtBy($teacherId, $courseIds);
        }
        $records = $recordQuery->orderByDesc('SessionDate')->orderByDesc('id')->limit(self::PER_SOURCE_LIMIT)->get();
        $ownCourseIds = $courses->where('TeacherID', $teacherId)->pluck('ID')->all();
        if ($teacherId !== null && $records->isEmpty() && $sessionIds === [] && $ownCourseIds === []) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $signIns = DB::table('StudentSingIn')->where('StudentID', $student->id)->whereNull('VoidedAt')
            ->whereNotNull('ClassSessionID')->whereIn('StudentClassID', $courseIds)
            ->when($sessionIds !== null, fn ($q) => $q->whereIn('ClassSessionID', array_merge($sessionIds, $records->pluck('ClassSessionID')->filter()->all())))
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
            ->when($teacherId !== null, fn ($q) => $q->whereIn('student_class_id', $ownCourseIds))
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

    /**
     * Lessons a teacher may see, mirroring the learning-record scope: their own course lessons that were not
     * handed to a substitute, plus the lessons they substituted for. Never the rest of someone else's course.
     *
     * @param  list<int>  $courseIds
     * @return list<int>
     */
    private function lessonsTaughtBy(int $teacherId, array $courseIds): array
    {
        $sub = fn ($q) => $q->select(DB::raw(1))->from('schedules as s')
            ->whereColumn('s.student_course_id', 'cs.StudentClassID')
            ->whereRaw('DATE(s.schedule_date) = DATE(cs.SessionDate)')
            ->whereRaw('SUBSTRING(s.start_time, 1, 5) = SUBSTRING(cs.StartTime, 1, 5)')
            ->where('s.status', 'scheduled')->whereNotNull('s.original_schedule_id')
            ->whereColumn('s.teacher_id', '!=', 'sc.TeacherID');

        return DB::table('ClassSession as cs')->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->whereIn('cs.StudentClassID', $courseIds)
            ->where(function ($q) use ($teacherId, $sub) {
                $q->where(fn ($own) => $own->where('sc.TeacherID', $teacherId)->whereNotExists($sub))
                    ->orWhereExists(fn ($x) => $sub($x)->where('s.teacher_id', $teacherId));
            })->pluck('cs.id')->map(fn ($id) => (int) $id)->all();
    }
}
