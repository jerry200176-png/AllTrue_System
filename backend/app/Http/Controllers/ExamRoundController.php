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

/**
 * School midterm exam rounds (段考). Separate from Assessment diagnostic ledger.
 */
class ExamRoundController extends Controller
{
    private const DEFAULT_SUBJECTS = ['國文', '英文', '數學', '社會', '自然'];

    public function index(Request $request)
    {
        $campusId = (int) $request->input('campus_id');
        if ($campusId <= 0 || !$this->campusAllowed($request, $campusId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $query = ExamRound::query()
            ->with(['subjects'])
            ->where('campus_id', $campusId);

        $role = $this->role($request);
        if ($role === 'teacher') {
            $query->whereIn('status', ['published', 'closed']);
        }

        $rows = $query->latest('exam_date')->latest('id')
            ->paginate(min((int) $request->input('per_page', 50), 100));

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

        $data = $this->validatedRoundPayload($request);
        $campusId = (int) $data['campus_id'];
        if (!$this->campusAllowed($request, $campusId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $subjects = $data['subjects'] ?? null;
        if (!is_array($subjects) || count($subjects) === 0) {
            $subjects = array_map(fn ($name, $i) => [
                'name' => $name,
                'sort_order' => $i,
                'max_score' => 100,
            ], self::DEFAULT_SUBJECTS, array_keys(self::DEFAULT_SUBJECTS));
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
                $name = is_array($subject) ? (string) ($subject['name'] ?? '') : (string) $subject;
                $name = trim($name);
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
        $examRound->load('subjects');

        return response()->json(['data' => $this->serializeRound($examRound)]);
    }

    public function update(Request $request, ExamRound $examRound)
    {
        if (!$this->isDirector($request) || !$this->campusAllowed($request, (int) $examRound->campus_id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($examRound->status === 'closed') {
            return response()->json(['message' => 'Closed round cannot be updated'], 409);
        }

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'exam_date' => ['nullable', 'date'],
            'entry_deadline' => ['nullable', 'date'],
            'grade_ids' => ['sometimes', 'array', 'min:1'],
            'grade_ids.*' => ['integer', 'min:1'],
        ]);

        $before = $this->serializeRound($examRound->load('subjects'));
        if (isset($data['grade_ids'])) {
            $data['grade_ids'] = array_values(array_map('intval', $data['grade_ids']));
        }
        $examRound->update($data);
        $this->audit($request, $examRound, null, 'round_updated', $before, $this->serializeRound($examRound->fresh('subjects')));

        return response()->json(['data' => $this->serializeRound($examRound->fresh('subjects'))]);
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

        $before = $this->serializeRound($examRound);
        $examRound->update(['status' => 'published', 'published_at' => now()]);
        $this->audit($request, $examRound, null, 'published', $before, $this->serializeRound($examRound->fresh('subjects')));

        return response()->json(['data' => $this->serializeRound($examRound->fresh('subjects'))]);
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

        $before = $this->serializeRound($examRound);
        $examRound->update(['status' => 'closed', 'closed_at' => now()]);
        $this->audit($request, $examRound, null, 'closed', $before, $this->serializeRound($examRound->fresh('subjects')));

        return response()->json(['data' => $this->serializeRound($examRound->fresh('subjects'))]);
    }

    public function students(Request $request, ExamRound $examRound)
    {
        if (!$this->canAccessRound($request, $examRound)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $teacherId = $this->role($request) === 'teacher' ? $this->userId($request) : null;
        $eligible = $this->eligibleStudentRows($examRound, $teacherId);
        $subjectIds = $examRound->subjects()->pluck('id')->all();
        $scores = ExamScore::query()
            ->where('exam_round_id', $examRound->id)
            ->whereIn('student_id', $eligible->pluck('student_id')->all() ?: [0])
            ->get()
            ->groupBy('student_id');

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
                    'score' => $s ? ($s->score !== null ? (float) $s->score : null) : null,
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
        if (!$isDirector && $examRound->status === 'published' && $examRound->entry_deadline) {
            if (now()->startOfDay()->gt($examRound->entry_deadline->copy()->startOfDay())) {
                return response()->json(['message' => 'Entry deadline has passed'], 409);
            }
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
        $eligibleIds = $this->eligibleStudentRows($examRound, $teacherId)->pluck('student_id')->all();
        $eligibleSet = array_fill_keys($eligibleIds, true);

        $normalized = [];
        foreach ($payload['scores'] as $row) {
            $studentId = (int) $row['student_id'];
            $subjectId = (int) $row['exam_round_subject_id'];
            if (!isset($eligibleSet[$studentId])) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $subject = $subjectMap->get($subjectId);
            if (!$subject) {
                throw ValidationException::withMessages([
                    'scores' => ['Unknown subject for this round'],
                ]);
            }

            $isAbsent = (bool) ($row['is_absent'] ?? false);
            $score = array_key_exists('score', $row) ? $row['score'] : null;
            if ($isAbsent) {
                $score = null;
            } elseif ($score === null) {
                throw ValidationException::withMessages([
                    'scores' => ['Score is required unless marked absent'],
                ]);
            } else {
                $score = round((float) $score, 2);
                $max = (float) $subject->max_score;
                if ($score < 0 || $score > $max) {
                    throw ValidationException::withMessages([
                        'scores' => ["Score must be between 0 and {$max}"],
                    ]);
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
            foreach ($normalized as $row) {
                $record = ExamScore::query()->firstOrNew([
                    'exam_round_id' => $examRound->id,
                    'student_id' => $row['student_id'],
                    'exam_round_subject_id' => $row['exam_round_subject_id'],
                ]);
                $before = $record->exists ? $record->only(['score', 'is_absent', 'notes']) : null;
                $record->score = $row['score'];
                $record->is_absent = $row['is_absent'];
                $record->notes = $row['notes'];
                $record->recorded_by_user_id = $this->userId($request);
                $record->recorded_at = now();
                $record->save();

                $action = $before === null ? 'score_created' : 'score_updated';
                if ($isDirector && $examRound->status === 'closed') {
                    $action = 'score_corrected';
                }
                $this->audit(
                    $request,
                    $examRound,
                    $record->id,
                    $action,
                    $before,
                    $record->only(['score', 'is_absent', 'notes', 'student_id', 'exam_round_subject_id']),
                    $payload['reason'] ?? null
                );
                $saved[] = $this->serializeScore($record);
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
        $scores = ExamScore::query()
            ->where('exam_round_id', $examRound->id)
            ->get()
            ->groupBy('student_id');

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

        $rounds = ExamRound::query()
            ->with('subjects')
            ->where('status', 'published')
            ->when(!$this->isSuperAdmin($request), fn ($q) => $q->whereIn('campus_id', $campusIds ?: [0]))
            ->orderBy('entry_deadline')
            ->orderBy('id')
            ->get();

        $pendingRounds = [];
        $total = 0;
        foreach ($rounds as $round) {
            if ($round->entry_deadline && now()->startOfDay()->gt($round->entry_deadline->copy()->startOfDay())) {
                continue;
            }
            $teacherFilter = $this->role($request) === 'teacher' ? $userId : null;
            $eligible = $this->eligibleStudentRows($round, $teacherFilter);
            if ($eligible->isEmpty()) {
                continue;
            }
            $subjectIds = $round->subjects->pluck('id')->all();
            $subjectCount = count($subjectIds);
            if ($subjectCount === 0) {
                continue;
            }
            $scoreRows = ExamScore::query()
                ->where('exam_round_id', $round->id)
                ->whereIn('student_id', $eligible->pluck('student_id')->all())
                ->get()
                ->groupBy('student_id');

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

        return response()->json([
            'data' => [
                'count' => $total,
                'rounds' => $pendingRounds,
            ],
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{student_id:int,student_name:string,class_id:int,teacher_ids:array<int,int>}>
     */
    private function eligibleStudentRows(ExamRound $round, ?int $teacherId)
    {
        $gradeIds = array_values(array_map('intval', $round->grade_ids ?? []));
        if ($gradeIds === []) {
            return collect();
        }

        $studentQuery = Student::query()
            ->where('CampusID', (int) $round->campus_id)
            ->whereIn('ClassID', $gradeIds)
            ->where(function ($q) {
                $q->whereNull('enable')->orWhere('enable', 1);
            });

        $students = $studentQuery->get(['id', 'name', 'ClassID']);
        if ($students->isEmpty()) {
            return collect();
        }

        $classQuery = StudentClass::query()
            ->whereIn('StudentID', $students->pluck('id')->all())
            ->where(function ($q) {
                $q->whereNull('Stop')->orWhere('Stop', 0);
            });
        if ($teacherId !== null) {
            $classQuery->where('TeacherID', $teacherId);
        }
        $classes = $classQuery->get(['ID', 'StudentID', 'TeacherID']);
        if ($classes->isEmpty()) {
            return collect();
        }

        $teachersByStudent = $classes->groupBy('StudentID')->map(
            fn ($rows) => $rows->pluck('TeacherID')->map(fn ($id) => (int) $id)->unique()->values()->all()
        );

        return $students
            ->filter(fn ($s) => $teachersByStudent->has($s->id))
            ->map(fn ($s) => [
                'student_id' => (int) $s->id,
                'student_name' => (string) $s->name,
                'class_id' => (int) $s->ClassID,
                'teacher_ids' => $teachersByStudent->get($s->id, []),
            ])
            ->values();
    }

    private function validatedRoundPayload(Request $request): array
    {
        return $request->validate([
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
    }

    private function canAccessRound(Request $request, ExamRound $round): bool
    {
        if (!$this->campusAllowed($request, (int) $round->campus_id)) {
            return false;
        }
        if ($this->role($request) === 'teacher' && !in_array($round->status, ['published', 'closed'], true)) {
            return false;
        }

        return true;
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

    private function serializeScore(ExamScore $score): array
    {
        return [
            'id' => (int) $score->id,
            'exam_round_id' => (int) $score->exam_round_id,
            'exam_round_subject_id' => (int) $score->exam_round_subject_id,
            'student_id' => (int) $score->student_id,
            'score' => $score->score !== null ? (float) $score->score : null,
            'is_absent' => (bool) $score->is_absent,
            'notes' => $score->notes,
            'recorded_by_user_id' => $score->recorded_by_user_id ? (int) $score->recorded_by_user_id : null,
            'recorded_at' => optional($score->recorded_at)->toIso8601String(),
        ];
    }

    private function audit(
        Request $request,
        ExamRound $round,
        ?int $scoreId,
        string $action,
        $before,
        $after,
        ?string $reason = null
    ): void {
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
        if ($this->isSuperAdmin($request)) {
            return true;
        }

        return in_array($campusId, $this->campusIds($request), true);
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
