<?php

namespace App\Http\Controllers;

use App\Models\ExamRound;
use App\Models\ExamRoundSubject;
use App\Models\ExamScore;
use App\Models\ExamScoreAuditLog;
use App\Models\Student;
use App\Models\StudentClass;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** School midterm exam rounds (段考). Separate from Assessment / LearningRecord. */
class ExamRoundController extends Controller
{
    private const DEFAULT_SUBJECTS = ['國文', '英文', '數學', '社會', '自然'];

    public function index(Request $request)
    {
        $campusId = (int) $request->input('campus_id');
        if ($campusId <= 0 || !$this->campusAllowed($request, $campusId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $query = ExamRound::query()->with('subjects')->where('campus_id', $campusId);
        if ($this->role($request) === 'teacher') {
            $query->whereIn('status', ['published', 'closed']);
        }
        $perPage = max(1, min((int) $request->input('per_page', 50), 100));
        $rows = $query->latest('exam_date')->latest('id')->paginate($perPage);
        return response()->json([
            'data' => collect($rows->items())->map(fn (ExamRound $r) => $this->serializeRound($r))->values(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }
    public function store(Request $request)
    {
        if (!$this->isDirector($request)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $data = $request->validate([
            'campus_id' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'exam_date' => ['nullable', 'date'],
            'entry_deadline' => ['nullable', 'date'],
            'grade_ids' => ['required', 'array', 'min:1'],
            'grade_ids.*' => ['integer', 'min:1'],
            'subjects' => ['nullable', 'array', 'max:20'],
            'subjects.*.name' => ['required_with:subjects', 'string', 'max:40'],
            'subjects.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'subjects.*.max_score' => ['nullable', 'numeric', 'min:1', 'max:1000'],
        ]);
        $campusId = (int) $data['campus_id'];
        if (!$this->campusAllowed($request, $campusId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $subjects = $data['subjects'] ?? null;
        if (!is_array($subjects) || $subjects === []) {
            $subjects = array_map(
                fn ($name, $i) => ['name' => $name, 'sort_order' => $i, 'max_score' => 100],
                self::DEFAULT_SUBJECTS,
                array_keys(self::DEFAULT_SUBJECTS)
            );
        }
        $round = DB::transaction(function () use ($data, $campusId, $subjects, $request) {
            $round = ExamRound::create([
                'campus_id' => $campusId,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => 'draft',
                'exam_date' => $data['exam_date'] ?? null,
                'entry_deadline' => $data['entry_deadline'] ?? null,
                'grade_ids' => array_values(array_map('intval', $data['grade_ids'])),
                'created_by_user_id' => $this->userId($request),
            ]);
            foreach ($subjects as $i => $subject) {
                $name = trim(is_array($subject) ? (string) ($subject['name'] ?? '') : (string) $subject);
                if ($name === '') {
                    continue;
                }
                ExamRoundSubject::create([
                    'exam_round_id' => $round->id,
                    'name' => mb_substr($name, 0, 40),
                    'sort_order' => (int) (is_array($subject) ? ($subject['sort_order'] ?? $i) : $i),
                    'max_score' => (float) (is_array($subject) ? ($subject['max_score'] ?? 100) : 100),
                ]);
            }
            $this->audit($request, $round, null, 'round_created', null, $this->serializeRound($round->fresh('subjects')));
            return $round->fresh('subjects');
        });
        return response()->json(['data' => $this->serializeRound($round)], 201);
    }
    public function show(Request $request, ExamRound $examRound)
    {
        if (!$this->canAccessRound($request, $examRound)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['data' => $this->serializeRound($examRound->load('subjects'))]);
    }
    public function publish(Request $request, ExamRound $examRound)
    {
        if (!$this->isDirector($request) || !$this->campusAllowed($request, (int) $examRound->campus_id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($examRound->subjects()->count() < 1) {
            return response()->json(['message' => 'At least one subject is required'], 422);
        }
        if ($examRound->status === 'published') {
            return response()->json(['data' => $this->serializeRound($examRound->load('subjects'))]);
        }
        if ($examRound->status === 'closed') {
            return response()->json(['message' => 'Closed round cannot be published'], 409);
        }
        $after = DB::transaction(function () use ($request, $examRound) {
            $locked = ExamRound::whereKey($examRound->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'published') {
                return $this->serializeRound($locked->load('subjects'));
            }
            if ($locked->status === 'closed') {
                abort(response()->json(['message' => 'Closed round cannot be published'], 409));
            }
            $before = $this->serializeRound($locked);
            $locked->update(['status' => 'published', 'published_at' => now()]);
            $serialized = $this->serializeRound($locked->fresh('subjects'));
            $this->audit($request, $locked, null, 'published', $before, $serialized);
            return $serialized;
        });
        return response()->json(['data' => $after]);
    }
    public function close(Request $request, ExamRound $examRound)
    {
        if (!$this->isDirector($request) || !$this->campusAllowed($request, (int) $examRound->campus_id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($examRound->status === 'closed') {
            return response()->json(['data' => $this->serializeRound($examRound->load('subjects'))]);
        }
        if ($examRound->status !== 'published') {
            return response()->json(['message' => 'Only published rounds can be closed'], 409);
        }
        $after = DB::transaction(function () use ($request, $examRound) {
            $locked = ExamRound::whereKey($examRound->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'closed') {
                return $this->serializeRound($locked->load('subjects'));
            }
            if ($locked->status !== 'published') {
                abort(response()->json(['message' => 'Only published rounds can be closed'], 409));
            }
            $before = $this->serializeRound($locked);
            $locked->update(['status' => 'closed', 'closed_at' => now()]);
            $serialized = $this->serializeRound($locked->fresh('subjects'));
            $this->audit($request, $locked, null, 'closed', $before, $serialized);
            return $serialized;
        });
        return response()->json(['data' => $after]);
    }
    public function students(Request $request, ExamRound $examRound)
    {
        if (!$this->canAccessRound($request, $examRound)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $teacherId = $this->role($request) === 'teacher' ? $this->userId($request) : null;
        $eligible = $this->eligibleStudentRows($examRound, $teacherId);
        $subjectIds = $examRound->subjects()->pluck('id')->all();
        $scores = ExamScore::query()->where('exam_round_id', $examRound->id)
            ->whereIn('student_id', $eligible->pluck('student_id')->all() ?: [0])
            ->get()->groupBy('student_id');
        $data = $eligible->map(function ($row) use ($scores, $subjectIds) {
            $bySubject = collect($scores->get($row['student_id'], []))->keyBy('exam_round_subject_id');
            $filled = 0;
            $scorePayload = [];
            foreach ($subjectIds as $sid) {
                $s = $bySubject->get($sid);
                if ($s && ($s->is_absent || $s->score !== null)) {
                    $filled++;
                }
                $scorePayload[] = [
                    'exam_round_subject_id' => (int) $sid,
                    'score' => $s && $s->score !== null ? (float) $s->score : null,
                    'is_absent' => $s ? (bool) $s->is_absent : false,
                    'notes' => $s->notes ?? null,
                ];
            }
            return [
                'student_id' => (int) $row['student_id'],
                'student_name' => $row['student_name'],
                'class_id' => (int) $row['class_id'],
                'teacher_ids' => $row['teacher_ids'],
                'filled_subject_count' => $filled,
                'required_subject_count' => count($subjectIds),
                'is_complete' => count($subjectIds) > 0 && $filled >= count($subjectIds),
                'scores' => $scorePayload,
            ];
        })->values();
        return response()->json(['data' => $data]);
    }
    public function upsertScores(Request $request, ExamRound $examRound)
    {
        if (!$this->canAccessRound($request, $examRound)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $isDirector = $this->isDirector($request);
        if (!$isDirector && $examRound->status !== 'published') {
            return response()->json(['message' => 'Round is not open for entry'], 409);
        }
        if (!$isDirector && $examRound->entry_deadline
            && now()->startOfDay()->gt($examRound->entry_deadline->copy()->startOfDay())) {
            return response()->json(['message' => 'Entry deadline has passed'], 409);
        }
        if ($isDirector && $examRound->status === 'draft') {
            return response()->json(['message' => 'Publish the round before entering scores'], 409);
        }
        $payload = $request->validate([
            'scores' => ['required', 'array', 'min:1', 'max:200'],
            'scores.*.student_id' => ['required', 'integer', 'min:1'],
            'scores.*.exam_round_subject_id' => ['required', 'integer', 'min:1'],
            'scores.*.score' => ['nullable', 'numeric', 'min:0'],
            'scores.*.is_absent' => ['sometimes', 'boolean'],
            'scores.*.notes' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $subjectMap = $examRound->subjects()->get()->keyBy('id');
        if ($subjectMap->isEmpty()) {
            return response()->json(['message' => 'Round has no subjects'], 422);
        }
        $teacherId = $isDirector ? null : $this->userId($request);
        $eligibleSet = array_fill_keys(
            $this->eligibleStudentRows($examRound, $teacherId)->pluck('student_id')->all(),
            true
        );
        $normalized = [];
        foreach ($payload['scores'] as $row) {
            $studentId = (int) $row['student_id'];
            $subjectId = (int) $row['exam_round_subject_id'];
            if (!isset($eligibleSet[$studentId])) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $subject = $subjectMap->get($subjectId);
            if (!$subject) {
                throw ValidationException::withMessages(['scores' => ['Unknown subject for this round']]);
            }
            $isAbsent = (bool) ($row['is_absent'] ?? false);
            $score = array_key_exists('score', $row) ? $row['score'] : null;
            if ($isAbsent) {
                $score = null;
            } elseif ($score === null) {
                throw ValidationException::withMessages(['scores' => ['Score is required unless marked absent']]);
            } else {
                $score = round((float) $score, 2);
                $max = (float) $subject->max_score;
                if ($score < 0 || $score > $max) {
                    throw ValidationException::withMessages(['scores' => ["Score must be between 0 and {$max}"]]);
                }
            }
            $normalized[] = [
                'student_id' => $studentId,
                'exam_round_subject_id' => $subjectId,
                'score' => $score,
                'is_absent' => $isAbsent,
                'notes' => $row['notes'] ?? null,
            ];
        }
        $saved = [];
        DB::transaction(function () use ($normalized, $examRound, $request, $isDirector, $payload, &$saved) {
            $locked = ExamRound::whereKey($examRound->id)->lockForUpdate()->firstOrFail();
            if (!$isDirector && $locked->status !== 'published') {
                abort(response()->json(['message' => 'Round is not open for entry'], 409));
            }
            if ($isDirector && $locked->status === 'draft') {
                abort(response()->json(['message' => 'Publish the round before entering scores'], 409));
            }
            foreach ($normalized as $row) {
                $record = ExamScore::where('exam_round_id', $locked->id)
                    ->where('student_id', $row['student_id'])
                    ->where('exam_round_subject_id', $row['exam_round_subject_id'])
                    ->lockForUpdate()
                    ->first();
                if (!$record) {
                    $record = new ExamScore([
                        'exam_round_id' => $locked->id,
                        'student_id' => $row['student_id'],
                        'exam_round_subject_id' => $row['exam_round_subject_id'],
                    ]);
                }
                $before = $record->exists ? $record->only(['score', 'is_absent', 'notes']) : null;
                $record->fill([
                    'score' => $row['score'],
                    'is_absent' => $row['is_absent'],
                    'notes' => $row['notes'],
                    'recorded_by_user_id' => $this->userId($request),
                    'recorded_at' => now(),
                ])->save();
                $action = $before === null ? 'score_created' : 'score_updated';
                if ($isDirector && $locked->status === 'closed') {
                    $action = 'score_corrected';
                }
                $this->audit(
                    $request,
                    $locked,
                    $record->id,
                    $action,
                    $before,
                    $record->only(['score', 'is_absent', 'notes', 'student_id', 'exam_round_subject_id']),
                    $payload['reason'] ?? null
                );
                $saved[] = [
                    'id' => (int) $record->id,
                    'exam_round_id' => (int) $record->exam_round_id,
                    'exam_round_subject_id' => (int) $record->exam_round_subject_id,
                    'student_id' => (int) $record->student_id,
                    'score' => $record->score !== null ? (float) $record->score : null,
                    'is_absent' => (bool) $record->is_absent,
                    'notes' => $record->notes,
                ];
            }
        });
        return response()->json(['data' => $saved]);
    }
    public function progress(Request $request, ExamRound $examRound)
    {
        if (!$this->isDirector($request) || !$this->campusAllowed($request, (int) $examRound->campus_id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $examRound->load('subjects');
        $subjectCount = $examRound->subjects->count();
        $eligible = $this->eligibleStudentRows($examRound, null);
        $scores = ExamScore::query()->where('exam_round_id', $examRound->id)->get()->groupBy('student_id');
        $byTeacher = [];
        $complete = 0;
        foreach ($eligible as $row) {
            $filled = 0;
            $bySubject = collect($scores->get($row['student_id'], []))->keyBy('exam_round_subject_id');
            foreach ($examRound->subjects as $subject) {
                $s = $bySubject->get($subject->id);
                if ($s && ($s->is_absent || $s->score !== null)) {
                    $filled++;
                }
            }
            $isComplete = $subjectCount > 0 && $filled >= $subjectCount;
            if ($isComplete) {
                $complete++;
            }
            foreach ($row['teacher_ids'] as $tid) {
                if (!isset($byTeacher[$tid])) {
                    $byTeacher[$tid] = ['teacher_id' => $tid, 'expected' => 0, 'complete' => 0];
                }
                $byTeacher[$tid]['expected']++;
                if ($isComplete) {
                    $byTeacher[$tid]['complete']++;
                }
            }
        }
        $expected = $eligible->count();
        return response()->json([
            'data' => [
                'exam_round_id' => (int) $examRound->id,
                'expected_students' => $expected,
                'complete_students' => $complete,
                'completion_percent' => $expected > 0 ? round(($complete / $expected) * 100, 1) : 0,
                'by_teacher' => array_values($byTeacher),
            ],
        ]);
    }
    public function pendingSummary(Request $request)
    {
        $userId = $this->userId($request);
        $campusIds = $this->campusIds($request);
        if ($userId <= 0 || ($campusIds === [] && !$this->isSuperAdmin($request))) {
            return response()->json(['data' => ['count' => 0, 'rounds' => []]]);
        }
        $rounds = ExamRound::query()->with('subjects')->where('status', 'published')
            ->when(!$this->isSuperAdmin($request), fn ($q) => $q->whereIn('campus_id', $campusIds ?: [0]))
            ->orderBy('entry_deadline')->orderBy('id')->get();
        $pendingRounds = [];
        $total = 0;
        foreach ($rounds as $round) {
            if ($round->entry_deadline && now()->startOfDay()->gt($round->entry_deadline->copy()->startOfDay())) {
                continue;
            }
            $teacherFilter = $this->role($request) === 'teacher' ? $userId : null;
            $eligible = $this->eligibleStudentRows($round, $teacherFilter);
            $subjectIds = $round->subjects->pluck('id')->all();
            $subjectCount = count($subjectIds);
            if ($eligible->isEmpty() || $subjectCount === 0) {
                continue;
            }
            $scoreRows = ExamScore::query()->where('exam_round_id', $round->id)
                ->whereIn('student_id', $eligible->pluck('student_id')->all())
                ->get()->groupBy('student_id');
            $incomplete = 0;
            foreach ($eligible as $row) {
                $filled = 0;
                $bySubject = collect($scoreRows->get($row['student_id'], []))->keyBy('exam_round_subject_id');
                foreach ($subjectIds as $sid) {
                    $s = $bySubject->get($sid);
                    if ($s && ($s->is_absent || $s->score !== null)) {
                        $filled++;
                    }
                }
                if ($filled < $subjectCount) {
                    $incomplete++;
                }
            }
            if ($incomplete > 0) {
                $total += $incomplete;
                $pendingRounds[] = [
                    'exam_round_id' => (int) $round->id,
                    'title' => $round->title,
                    'campus_id' => (int) $round->campus_id,
                    'incomplete_students' => $incomplete,
                    'entry_deadline' => optional($round->entry_deadline)->format('Y-m-d'),
                ];
            }
        }
        return response()->json(['data' => ['count' => $total, 'rounds' => $pendingRounds]]);
    }
    private function eligibleStudentRows(ExamRound $round, ?int $teacherId)
    {
        $gradeIds = array_values(array_map('intval', $round->grade_ids ?? []));
        if ($gradeIds === []) {
            return collect();
        }
        // Keep students who already have scores so closed/historical rows stay visible
        // after grade moves or contract changes (Codex P1 roster stability).
        $scoredIds = ExamScore::query()->where('exam_round_id', $round->id)->distinct()->pluck('student_id')->all();
        $students = Student::query()
            ->where('CampusID', (int) $round->campus_id)
            ->where(function ($q) use ($gradeIds, $scoredIds) {
                $q->where(function ($inner) use ($gradeIds) {
                    $inner->whereIn('ClassID', $gradeIds)
                        ->where(fn ($e) => $e->whereNull('enable')->orWhere('enable', 1));
                });
                if ($scoredIds !== []) {
                    $q->orWhereIn('id', $scoredIds);
                }
            })
            ->get(['id', 'name', 'ClassID']);
        if ($students->isEmpty()) {
            return collect();
        }
        $classQuery = StudentClass::query()
            ->whereIn('StudentID', $students->pluck('id')->all())
            ->where(fn ($q) => $q->whereNull('Stop')->orWhere('Stop', 0));
        if ($teacherId !== null) {
            $classQuery->where('TeacherID', $teacherId);
        }
        $classes = $classQuery->get(['ID', 'StudentID', 'TeacherID']);
        $teachersByStudent = $classes->groupBy('StudentID')->map(
            fn ($rows) => $rows->pluck('TeacherID')->map(fn ($id) => (int) $id)->unique()->values()->all()
        );
        $scoredSet = array_fill_keys(array_map('intval', $scoredIds), true);
        return $students->filter(
            fn ($s) => $teachersByStudent->has($s->id) || isset($scoredSet[(int) $s->id])
        )->map(fn ($s) => [
            'student_id' => (int) $s->id,
            'student_name' => (string) $s->name,
            'class_id' => (int) $s->ClassID,
            'teacher_ids' => $teachersByStudent->get($s->id, []),
        ])->values();
    }
    private function canAccessRound(Request $request, ExamRound $round): bool
    {
        if (!$this->campusAllowed($request, (int) $round->campus_id)) {
            return false;
        }
        return $this->role($request) !== 'teacher' || in_array($round->status, ['published', 'closed'], true);
    }
    private function serializeRound(ExamRound $round): array
    {
        $round->loadMissing('subjects');
        return [
            'id' => (int) $round->id,
            'campus_id' => (int) $round->campus_id,
            'title' => $round->title,
            'description' => $round->description,
            'status' => $round->status,
            'exam_date' => optional($round->exam_date)->format('Y-m-d'),
            'entry_deadline' => optional($round->entry_deadline)->format('Y-m-d'),
            'grade_ids' => array_values(array_map('intval', $round->grade_ids ?? [])),
            'created_by_user_id' => $round->created_by_user_id ? (int) $round->created_by_user_id : null,
            'published_at' => optional($round->published_at)->toIso8601String(),
            'closed_at' => optional($round->closed_at)->toIso8601String(),
            'subjects' => $round->subjects->map(fn (ExamRoundSubject $s) => [
                'id' => (int) $s->id,
                'name' => $s->name,
                'sort_order' => (int) $s->sort_order,
                'max_score' => (float) $s->max_score,
            ])->values()->all(),
        ];
    }
    private function audit(Request $request, ExamRound $round, ?int $scoreId, string $action, $before, $after, ?string $reason = null): void
    {
        ExamScoreAuditLog::create([
            'exam_round_id' => $round->id,
            'exam_score_id' => $scoreId,
            'campus_id' => (int) $round->campus_id,
            'actor_user_id' => $this->userId($request),
            'action' => $action,
            'reason' => $reason,
            'before' => $before,
            'after' => $after,
        ]);
    }
    private function campusIds(Request $request): array
    {
        $ids = $request->attributes->get('auth_campus_ids', []);
        return array_values(array_map('intval', is_array($ids) ? $ids : []));
    }
    private function campusAllowed(Request $request, int $campusId): bool
    {
        return $this->isSuperAdmin($request) || in_array($campusId, $this->campusIds($request), true);
    }
    private function role(Request $request): string
    {
        return (string) $request->attributes->get('auth_role', '');
    }
    private function userId(Request $request): int
    {
        $user = $request->attributes->get('auth_user');
        return (int) (is_object($user) ? ($user->id ?? 0) : 0);
    }
    private function isSuperAdmin(Request $request): bool
    {
        return $this->role($request) === 'super_admin';
    }
    private function isDirector(Request $request): bool
    {
        return in_array($this->role($request), ['director', 'super_admin'], true);
    }
}
