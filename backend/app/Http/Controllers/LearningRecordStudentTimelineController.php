<?php

namespace App\Http\Controllers;

use App\Models\AssessmentResult;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Support\AttendanceStatus;
use App\Support\LearningRecordMutableOwnership;
use App\Services\TeacherScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET students/{student}/learning-timeline (#4970 step3a): staff read-only view that merges four existing
 * sources into one newest-first list: LearningRecord evaluations (with their lesson's sign-in status),
 * formal assessment scores, sign-ins and visible school exam scores. No business writes.
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
        if ($teacherId !== null && $teacherId <= 0) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $capped = [];
        $courses = StudentClass::query()->where('StudentID', $student->id)->get(['ID', 'SubjectID', 'TeacherID', 'Stop', 'by1']);
        $courseIds = $courses->pluck('ID')->all();
        $subjectNames = DB::table('Subject')->pluck('Subject_Name', 'id');
        $courseSubject = $courses->pluck('SubjectID', 'ID');

        $recordQuery = LearningRecord::active()->with('classSession:id,Status')->whereIn('StudentClassID', $courseIds);
        $sessionIds = null;
        if ($teacherId !== null) {
            // Same rule as GET learning-records for a teacher; sign-ins follow the lessons that rule lets them see.
            LearningRecordController::applyTeacherScopedLearningRecordConstraint($recordQuery, $teacherId);
            $recordQuery->excludeCancelledClassSessionForTeacherList();
            $sessionIds = $this->lessonsTaughtBy($teacherId, $courseIds);
        }
        $evaluated = (clone $recordQuery)->whereNotNull('ClassSessionID')->pluck('ClassSessionID')->all(); // all of them, not just the capped page
        $records = $recordQuery->orderByDesc('SessionDate')->orderByDesc('id')->limit(self::PER_SOURCE_LIMIT + 1)->get();
        if ($records->count() > self::PER_SOURCE_LIMIT) $capped[] = 'evaluation';
        $records = $records->take(self::PER_SOURCE_LIMIT);
        $ownCourseIds = $courses->where('TeacherID', $teacherId)->pluck('ID')->all();
        if ($teacherId !== null && $records->isEmpty() && $sessionIds === [] && $ownCourseIds === []) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        // Same latest-active MAX(id) precedence as BackfillSessionStatus; legacy schemas can contain duplicates.
        $latestSignIns = DB::table('StudentSingIn')->where('StudentID', $student->id)->whereNull('VoidedAt')
            ->whereNotNull('ClassSessionID')->whereIn('StudentClassID', $courseIds)
            ->when($sessionIds !== null, fn ($q) => $q->whereIn('ClassSessionID', array_merge($sessionIds, $records->pluck('ClassSessionID')->filter()->all())))
            ->selectRaw('MAX(id)')->groupBy('ClassSessionID');
        $signIns = DB::table('StudentSingIn')->whereIn('id', $latestSignIns)->pluck('Status', 'ClassSessionID');
        $statusLabel = fn ($code) => $code === null ? null : AttendanceStatus::label((string) $code);

        $items = [];
        foreach ($records as $r) {
            $subjectId = $courseSubject[$r->StudentClassID] ?? null;
            $items[] = [
                'type' => 'evaluation', 'id' => $r->id, 'date' => substr((string) $r->SessionDate, 0, 10),
                'subject_id' => $subjectId, 'subject' => $subjectNames[$subjectId] ?? ($r->Subject ?: null),
                'homework_status' => $r->HomeworkStatus, 'quiz' => $r->QuizScore, 'next_week_scope' => $r->NextWeekTestScope,
                'progress' => $r->Progress, 'content' => $r->Content, 'session_status' => $r->classSession?->Status,
                'has_substantive_progress' => LearningRecordMutableOwnership::hasSubstantiveProgress($r),
                'approval_status' => $r->Status, 'attendance' => $statusLabel($signIns[$r->ClassSessionID] ?? null),
            ];
        }

        $sessions = DB::table('ClassSession')->whereIn('id', $signIns->keys()->diff($evaluated)->all())
            ->orderByDesc('SessionDate')->limit(self::PER_SOURCE_LIMIT + 1)->get(['id', 'StudentClassID', 'SessionDate', 'SubjectID']);
        if ($sessions->count() > self::PER_SOURCE_LIMIT) $capped[] = 'attendance';
        $sessions = $sessions->take(self::PER_SOURCE_LIMIT);
        foreach ($sessions as $s) {
            $subjectId = $s->SubjectID ?: ($courseSubject[$s->StudentClassID] ?? null);
            $items[] = [
                'type' => 'attendance', 'id' => $s->id, 'date' => substr((string) $s->SessionDate, 0, 10),
                'subject_id' => $subjectId, 'subject' => $subjectNames[$subjectId] ?? null,
                'attendance' => $statusLabel($signIns[$s->id] ?? null),
            ];
        }

        $results = AssessmentResult::query()->with('assessment')->where('student_id', $student->id)->where('status', '!=', 'voided')
            ->when($teacherId !== null, fn ($q) => $q->whereIn('student_class_id', $ownCourseIds))
            ->orderByDesc('id')->limit(self::PER_SOURCE_LIMIT + 1)->get();
        if ($results->count() > self::PER_SOURCE_LIMIT) $capped[] = 'assessment';
        $results = $results->take(self::PER_SOURCE_LIMIT);
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

        // School exam scores are a separate source from AssessmentResult. Never
        // widen teacher visibility merely because this student has a past lesson.
        $scores = DB::table('exam_scores as score_item')
            ->join('exam_rounds as round', 'round.id', '=', 'score_item.exam_round_id')
            ->join('exam_round_subjects as part', fn ($join) => $join->on('part.id', '=', 'score_item.exam_round_subject_id')
                ->on('part.exam_round_id', '=', 'round.id'))
            ->where('score_item.student_id', $student->id)->where('round.campus_id', $student->CampusID)
            ->whereIn('round.status', ['published', 'closed'])
            ->where(fn ($q) => $q->whereNotNull('score_item.score')->orWhere('score_item.is_absent', true));
        if ($teacherId !== null) {
            $names = $courses->filter(fn ($c) => (int) $c->TeacherID === $teacherId && !(int) $c->Stop
                && (int) $c->by1 === (int) $student->CampusID)
                ->flatMap(fn ($c) => TeacherScopeService::subjectNameAliases((string) ($subjectNames[$c->SubjectID] ?? '')))
                ->unique()->values()->all();
            $scores->whereIn('part.name', $names)->whereJsonContains('round.grade_ids', (int) $student->ClassID);
            if ((int) ($student->getAttribute('enable') ?? 1) !== 1) $scores->whereRaw('1 = 0');
        }
        $scoreRows = $scores->orderByRaw('COALESCE(round.exam_date, score_item.recorded_at, score_item.created_at) DESC')
            ->orderByDesc('score_item.id')->limit(self::PER_SOURCE_LIMIT + 1)
            ->get(['score_item.id', 'score_item.score', 'score_item.is_absent', 'score_item.recorded_at', 'score_item.created_at',
                'round.exam_date', 'round.title', 'part.name', 'part.max_score']);
        if ($scoreRows->count() > self::PER_SOURCE_LIMIT) $capped[] = 'exam_score';
        foreach ($scoreRows->take(self::PER_SOURCE_LIMIT) as $s) {
            $items[] = ['type' => 'exam_score', 'id' => (int) $s->id,
                'date' => substr((string) ($s->exam_date ?? $s->recorded_at ?? $s->created_at), 0, 10),
                'title' => $s->title, 'subject' => $s->name, 'score' => $s->score === null ? null : (float) $s->score,
                'max_score' => (float) $s->max_score, 'is_absent' => (bool) $s->is_absent];
        }
        foreach ($items as &$item) {
            if ($item['subject'] ?? null) $item['subject'] = TeacherScopeService::normalizeSubjectName($item['subject']);
        }
        unset($item);

        // ponytail: newest-first merge capped at PER_SOURCE_LIMIT per source; add paging if a student exceeds it.
        usort($items, fn ($x, $y) => [$y['date'], $y['id']] <=> [$x['date'], $x['id']]);

        return response()->json(['items' => $items, 'coverage' => ['limit_per_source' => self::PER_SOURCE_LIMIT, 'capped_sources' => $capped]]);
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
            ->where('cs.Status', '!=', 'cancelled')
            ->where(function ($q) use ($teacherId, $sub) {
                $q->where(fn ($own) => $own->where('sc.TeacherID', $teacherId)->whereNotExists($sub))
                    ->orWhereExists(fn ($x) => $sub($x)->where('s.teacher_id', $teacherId));
            })->pluck('cs.id')->map(fn ($id) => (int) $id)->all();
    }
}
