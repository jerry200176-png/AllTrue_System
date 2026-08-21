<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\AssessmentAuditLog;
use App\Models\AssessmentRemediationAction;
use App\Models\AssessmentResult;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\AssessmentAttemptService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssessmentController extends Controller
{
    public function classOptions(Request $request)
    {
        $campusId = (int) ($request->query('campus_id') ?? 0);
        if (!$this->campusAllowed($request, $campusId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $query = StudentClass::query()->with(['student', 'subjectRecord'])
            ->whereHas('student', fn (Builder $q) => $q->where('CampusID', $campusId))
            ->where(function (Builder $q) {
                $q->where('Stop', 0)->orWhereNull('Stop');
            });
        if ($this->role($request) === 'teacher') {
            $query->where('TeacherID', $this->userId($request));
        }

        return response()->json(['data' => $query->limit(500)->get()->map(fn (StudentClass $class) => [
            'id' => (int) $class->getAttribute('ID'),
            'student_id' => (int) $class->getAttribute('StudentID'),
            'student_name' => (string) optional($class->student)->name,
            'subject' => (string) data_get($class->getRelationValue('subjectRecord'), 'Subject_Name', ''),
        ])->values()]);
    }

    public function index(Request $request)
    {
        $query = $this->accessibleAssessments($request)
            ->with(['studentClass.student'])
            ->withCount(['results as result_count' => fn (Builder $q) => $q->where('status', '!=', 'voided')]);

        if ($request->filled('status')) {
            $query->whereIn('status', $this->csv($request->input('status')));
        }
        if ($request->filled('campus_id')) {
            $campusId = (int) $request->input('campus_id');
            if (!$this->campusAllowed($request, $campusId)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $query->where('campus_id', $campusId);
        }

        $rows = $query->latest('scheduled_for')->latest('id')->paginate(min((int) $request->input('per_page', 50), 100));

        return response()->json([
            'data' => collect($rows->items())->map(fn (Assessment $assessment) => $this->assessmentPayload($assessment))->values(),
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
        $data = $this->validatedAssessment($request);
        $campusId = (int) $data['campus_id'];
        if (!$this->campusAllowed($request, $campusId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $studentClass = $this->resolveStudentClass($request, $data['student_class_id'] ?? null, $campusId);
        if ($this->role($request) === 'teacher' && !$studentClass) {
            throw ValidationException::withMessages(['student_class_id' => '老師建立檢測時必須指定課程範圍。']);
        }

        $assessment = DB::transaction(function () use ($data, $campusId, $request) {
            $assessment = Assessment::create(array_merge($data, [
                'campus_id' => $campusId,
                'created_by_user_id' => $this->userId($request),
                'status' => 'draft',
            ]));
            $this->audit($request, $assessment, null, 'created', null, $assessment->fresh()->toArray());
            return $assessment;
        });

        return response()->json(['data' => $this->assessmentPayload($assessment->load(['studentClass.student']))], 201);
    }

    public function show(Request $request, Assessment $assessment)
    {
        if (!$this->canAccessAssessment($request, $assessment)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json([
            'data' => $this->assessmentPayload($assessment->load(['studentClass.student', 'results.student'])),
        ]);
    }

    public function update(Request $request, Assessment $assessment)
    {
        if (!$this->canManageAssessment($request, $assessment)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (in_array($assessment->status, ['closed', 'archived'], true)) {
            return response()->json(['message' => '已關閉的檢測不可修改'], 409);
        }

        $data = $this->validatedAssessment($request, true, $assessment);
        $campusId = (int) ($data['campus_id'] ?? $assessment->campus_id);
        if (!$this->campusAllowed($request, $campusId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $requestedClassId = array_key_exists('student_class_id', $data)
            ? ($data['student_class_id'] !== null ? (int) $data['student_class_id'] : null)
            : ($assessment->student_class_id !== null ? (int) $assessment->student_class_id : null);
        if ($assessment->results()->exists()
            && ($campusId !== (int) $assessment->campus_id || $requestedClassId !== ($assessment->student_class_id !== null ? (int) $assessment->student_class_id : null))) {
            return response()->json(['message' => '已有結果的檢測不可變更分校或課程範圍'], 409);
        }
        $studentClass = $this->resolveStudentClass($request, $requestedClassId, $campusId);
        if ($this->role($request) === 'teacher' && !$studentClass) {
            throw ValidationException::withMessages(['student_class_id' => '老師建立檢測時必須指定課程範圍。']);
        }

        $assessment = DB::transaction(function () use ($assessment, $data, $campusId, $request) {
            $before = $assessment->fresh()->toArray();
            $assessment->fill($data);
            $assessment->campus_id = $campusId;
            $assessment->save();
            $this->audit($request, $assessment, null, 'updated', $before, $assessment->fresh()->toArray());
            return $assessment;
        });

        return response()->json(['data' => $this->assessmentPayload($assessment->load(['studentClass.student']))]);
    }

    public function publish(Request $request, Assessment $assessment)
    {
        if (!$this->canManageAssessment($request, $assessment)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ($assessment->status === 'published') {
            return response()->json(['data' => $this->assessmentPayload($assessment)]);
        }
        if ($assessment->status !== 'draft') {
            return response()->json(['message' => '目前狀態不可發布'], 409);
        }

        $assessment = DB::transaction(function () use ($assessment, $request) {
            $before = $assessment->fresh()->toArray();
            $assessment->update(['status' => 'published', 'published_at' => now()]);
            $this->audit($request, $assessment, null, 'published', $before, $assessment->fresh()->toArray());
            return $assessment;
        });

        return response()->json(['data' => $this->assessmentPayload($assessment->fresh())]);
    }

    public function close(Request $request, Assessment $assessment)
    {
        if (!$this->directorOnly($request) || !$this->canAccessAssessment($request, $assessment)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ($assessment->status === 'closed') {
            return response()->json(['data' => $this->assessmentPayload($assessment)]);
        }
        if ($assessment->status !== 'published') {
            return response()->json(['message' => '只有已發布的檢測可以關閉'], 409);
        }

        $assessment = DB::transaction(function () use ($assessment, $request) {
            $before = $assessment->fresh()->toArray();
            $assessment->update(['status' => 'closed', 'closed_at' => now()]);
            $this->audit($request, $assessment, null, 'closed', $before, $assessment->fresh()->toArray());
            return $assessment;
        });

        return response()->json(['data' => $this->assessmentPayload($assessment->fresh())]);
    }

    public function results(Request $request, Assessment $assessment)
    {
        if (!$this->canAccessAssessment($request, $assessment)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $query = $assessment->results()->with(['student', 'studentClass'])->withCount('remediationActions')->where('status', '!=', 'voided');
        if ($request->filled('student_id')) {
            $query->where('student_id', (int) $request->input('student_id'));
        }

        return response()->json(['data' => $query->orderBy('student_id')->orderBy('attempt_no')->get()->map(fn (AssessmentResult $result) => $this->resultPayload($result))]);
    }

    public function remediationActions(Request $request, AssessmentResult $assessmentResult)
    {
        $assessment = $assessmentResult->assessment;
        if (!$assessment || !$this->canAccessAssessment($request, $assessment)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $actions = $assessmentResult->remediationActions()
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'in_progress' THEN 1 WHEN 'completed' THEN 2 ELSE 3 END")
            ->orderBy('due_date')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $actions->map(fn (AssessmentRemediationAction $action) => $this->remediationPayload($action))->values()]);
    }

    public function storeRemediationAction(Request $request, AssessmentResult $assessmentResult)
    {
        $assessment = $assessmentResult->assessment;
        if (!$assessment || !$this->canManageAssessment($request, $assessment)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ($assessmentResult->status === 'voided') {
            return response()->json(['message' => '作廢結果不可建立補強行動'], 409);
        }

        $data = $request->validate([
            'knowledge_tag' => ['required', 'string', 'max:120'],
            'action_type' => ['nullable', 'in:practice,retake,teacher_followup,other'],
            'plan' => ['nullable', 'string', 'max:10000'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);

        $action = DB::transaction(function () use ($assessment, $assessmentResult, $data, $request) {
            $action = AssessmentRemediationAction::create([
                'assessment_id' => $assessment->id,
                'assessment_result_id' => $assessmentResult->id,
                'campus_id' => $assessment->campus_id,
                'student_id' => $assessmentResult->student_id,
                'student_class_id' => $assessmentResult->student_class_id,
                'knowledge_tag' => trim((string) $data['knowledge_tag']),
                'action_type' => $data['action_type'] ?? 'practice',
                'status' => AssessmentRemediationAction::STATUS_OPEN,
                'plan' => $data['plan'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $this->userId($request),
            ]);
            $this->audit($request, $assessment, $assessmentResult, 'remediation_created', null, $action->fresh()->toArray());
            return $action;
        });

        return response()->json(['data' => $this->remediationPayload($action)], 201);
    }

    public function updateRemediationAction(Request $request, AssessmentRemediationAction $remediationAction)
    {
        $assessment = $remediationAction->assessment;
        if (!$assessment || !$this->canManageAssessment($request, $assessment)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $data = $request->validate([
            'knowledge_tag' => ['sometimes', 'string', 'max:120'],
            'action_type' => ['sometimes', 'in:practice,retake,teacher_followup,other'],
            'status' => ['sometimes', 'in:open,in_progress,completed,cancelled'],
            'plan' => ['nullable', 'string', 'max:10000'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);
        $currentStatus = (string) $remediationAction->status;
        $nextStatus = (string) ($data['status'] ?? $currentStatus);
        if ($currentStatus !== $nextStatus && in_array($currentStatus, [
            AssessmentRemediationAction::STATUS_COMPLETED,
            AssessmentRemediationAction::STATUS_CANCELLED,
        ], true)) {
            return response()->json(['message' => '已結束的補強行動不可重新開啟'], 409);
        }

        $action = DB::transaction(function () use ($remediationAction, $assessment, $data, $request, $nextStatus) {
            $before = $remediationAction->fresh()->toArray();
            $remediationAction->fill($data);
            $remediationAction->status = $nextStatus;
            if ($nextStatus === AssessmentRemediationAction::STATUS_IN_PROGRESS && !$remediationAction->started_at) {
                $remediationAction->started_at = now();
            }
            if ($nextStatus === AssessmentRemediationAction::STATUS_COMPLETED) {
                $remediationAction->completed_at = $remediationAction->completed_at ?: now();
            }
            $remediationAction->save();
            $this->audit($request, $assessment, $remediationAction->assessmentResult, 'remediation_updated', $before, $remediationAction->fresh()->toArray());
            return $remediationAction;
        });

        return response()->json(['data' => $this->remediationPayload($action->fresh())]);
    }

    public function students(Request $request, Assessment $assessment)
    {
        if (!$this->canAccessAssessment($request, $assessment)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $query = StudentClass::query()->with('student')
            ->whereHas('student', fn (Builder $q) => $q->where('CampusID', $assessment->campus_id));
        if ($assessment->student_class_id) {
            $query->whereKey($assessment->student_class_id);
        } elseif ($this->role($request) === 'teacher') {
            $query->where('TeacherID', $this->userId($request));
        }

        $rows = $query->where(function (Builder $q) {
            $q->where('Stop', 0)->orWhereNull('Stop');
        })->limit(500)->get()->map(fn (StudentClass $class) => [
            'student_id' => (int) $class->getAttribute('StudentID'),
            'student_class_id' => (int) $class->getAttribute('ID'),
            'name' => (string) optional($class->student)->name,
        ])->values();

        return response()->json(['data' => $rows]);
    }

    public function questions(Request $request, Assessment $assessment, AssessmentAttemptService $attempts)
    {
        if (!$this->canAccessAssessment($request, $assessment)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['data' => $attempts->questionPayloads((int) $assessment->id)]);
    }

    public function configureQuestions(Request $request, Assessment $assessment, AssessmentAttemptService $attempts)
    {
        if (!$this->canManageAssessment($request, $assessment)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ($assessment->status !== 'draft') {
            return response()->json(['message' => '檢測發布後不可配置題目'], 409);
        }
        $data = $request->validate([
            'question_bank_item_ids' => ['required', 'array', 'min:1', 'max:100'],
            'question_bank_item_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
        ]);
        $questions = $attempts->configureQuestions((int) $assessment->id, $data['question_bank_item_ids'], (int) $assessment->campus_id);
        $this->audit($request, $assessment, null, 'questions_configured', null, ['question_count' => count($questions), 'question_ids' => array_column($questions, 'id')]);
        return response()->json(['data' => $questions], 201);
    }

    public function attempts(Request $request, Assessment $assessment, AssessmentAttemptService $attempts)
    {
        if (!$this->canAccessAssessment($request, $assessment)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['data' => $attempts->listAttempts((int) $assessment->id)]);
    }

    public function showAttempt(Request $request, int $assessmentAttempt, AssessmentAttemptService $attempts)
    {
        $attempt = DB::table('assessment_attempts')->where('id', $assessmentAttempt)->first();
        $assessment = $attempt ? Assessment::find($attempt->assessment_id) : null;
        if (!$assessment || !$this->canAccessAssessment($request, $assessment)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['data' => $attempts->getAttempt($assessmentAttempt)]);
    }

    public function storeAttempt(Request $request, Assessment $assessment, AssessmentAttemptService $attempts)
    {
        if (!$this->canManageAssessment($request, $assessment)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ($assessment->status !== 'published') {
            return response()->json(['message' => '檢測發布後才能開始作答'], 409);
        }
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'min:1'],
            'student_class_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $student = Student::query()->find((int) $data['student_id']);
        if (!$student || (int) $student->getAttribute('CampusID') !== (int) $assessment->campus_id) {
            return response()->json(['message' => '學生不屬於此檢測分校'], 403);
        }
        if (!$this->campusAllowed($request, (int) $assessment->campus_id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $classId = (int) ($data['student_class_id'] ?? $assessment->student_class_id ?? 0);
        if ($assessment->student_class_id && $classId !== (int) $assessment->student_class_id) {
            throw ValidationException::withMessages(['student_class_id' => '此檢測只允許指定課程的學生。']);
        }
        $studentClass = $this->resolveStudentClass($request, $classId ?: null, (int) $assessment->campus_id);
        if (!$studentClass || (int) $studentClass->getAttribute('StudentID') !== (int) $student->getKey()) {
            throw ValidationException::withMessages(['student_class_id' => '請提供與學生相符的課程。']);
        }
        $attempt = $attempts->createAttempt((int) $assessment->id, (int) $student->getKey(), (int) $studentClass->getAttribute('ID'), (int) $assessment->max_score, $this->userId($request));
        $this->audit($request, $assessment, null, 'attempt_created', null, ['attempt_id' => $attempt['id'], 'student_id' => $student->getKey(), 'attempt_no' => $attempt['attempt_no']]);
        return response()->json(['data' => $attempt], 201);
    }

    public function saveAttemptAnswers(Request $request, int $assessmentAttempt, AssessmentAttemptService $attempts)
    {
        $attempt = DB::table('assessment_attempts')->where('id', $assessmentAttempt)->first();
        $assessment = $attempt ? Assessment::find($attempt->assessment_id) : null;
        if (!$assessment || !$this->canManageAssessment($request, $assessment)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $data = $request->validate([
            'answers' => ['required', 'array', 'max:100'],
            'answers.*.question_id' => ['required', 'integer', 'min:1'],
            'answers.*.answer' => ['nullable'],
        ]);
        return response()->json(['data' => $attempts->saveAnswers($assessmentAttempt, $data['answers'])]);
    }

    public function submitAttempt(Request $request, int $assessmentAttempt, AssessmentAttemptService $attempts)
    {
        $attempt = DB::table('assessment_attempts')->where('id', $assessmentAttempt)->first();
        $assessment = $attempt ? Assessment::find($attempt->assessment_id) : null;
        if (!$assessment || !$this->canManageAssessment($request, $assessment)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['data' => $attempts->submit($assessmentAttempt)]);
    }

    public function reviewAttempt(Request $request, int $assessmentAttempt, AssessmentAttemptService $attempts)
    {
        if (!$this->directorOnly($request)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $attempt = DB::table('assessment_attempts')->where('id', $assessmentAttempt)->first();
        $assessment = $attempt ? Assessment::find($attempt->assessment_id) : null;
        if (!$assessment || !$this->canAccessAssessment($request, $assessment)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $data = $request->validate([
            'reviews' => ['required', 'array', 'max:100'],
            'reviews.*.answer_id' => ['required', 'integer', 'min:1'],
            'reviews.*.score' => ['required', 'numeric', 'min:0'],
            'reviews.*.review_note' => ['nullable', 'string', 'max:10000'],
        ]);
        $result = $attempts->review($assessmentAttempt, $data['reviews'], (int) $this->userId($request));
        $this->audit($request, $assessment, null, 'attempt_reviewed', null, ['attempt_id' => $assessmentAttempt, 'review_count' => count($data['reviews'])]);
        return response()->json(['data' => $result]);
    }

    public function storeResult(Request $request, Assessment $assessment)
    {
        if (!$this->canManageAssessment($request, $assessment)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ($assessment->status !== 'published') {
            return response()->json(['message' => '檢測發布後才能登錄結果'], 409);
        }

        $data = $request->validate([
            'student_id' => ['required', 'integer', 'min:1'],
            'student_class_id' => ['nullable', 'integer', 'min:1'],
            'attempt_no' => ['nullable', 'integer', 'min:1'],
            'score' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);
        $student = Student::query()->find((int) $data['student_id']);
        if (!$student || !$this->campusAllowed($request, (int) $student->getAttribute('CampusID'))) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ((int) $student->getAttribute('CampusID') !== (int) $assessment->campus_id) {
            return response()->json(['message' => '學生不屬於此檢測分校'], 403);
        }
        $requestedClassId = (int) ($data['student_class_id'] ?? $assessment->student_class_id ?? 0);
        if ($assessment->student_class_id && $requestedClassId !== (int) $assessment->student_class_id) {
            throw ValidationException::withMessages(['student_class_id' => '此檢測只允許指定課程的學生。']);
        }
        $studentClass = $this->resolveStudentClass($request, $requestedClassId ?: null, (int) $assessment->campus_id);
        if ($studentClass && (int) $studentClass->getAttribute('StudentID') !== (int) $student->getKey()) {
            throw ValidationException::withMessages(['student_class_id' => '課程與學生不一致。']);
        }
        if ($this->role($request) === 'teacher' && !$studentClass) {
            throw ValidationException::withMessages(['student_class_id' => '老師登錄結果時必須指定課程。']);
        }

        $maxScore = (float) $assessment->max_score;
        $score = (float) $data['score'];
        if ($score > $maxScore) {
            throw ValidationException::withMessages(['score' => '分數不可超過滿分。']);
        }
        $attemptNo = (int) ($data['attempt_no'] ?? ((int) $assessment->results()->where('student_id', $student->getKey())->max('attempt_no') + 1));
        if ($assessment->results()->where('student_id', $student->getKey())->where('attempt_no', $attemptNo)->exists()) {
            return response()->json(['message' => '此學生的測驗次數已存在，請指定新的 attempt_no。'], 409);
        }

        $result = DB::transaction(function () use ($assessment, $student, $studentClass, $attemptNo, $score, $maxScore, $data, $request) {
            $result = AssessmentResult::create([
                'assessment_id' => $assessment->id,
                'student_id' => $student->getKey(),
                'student_class_id' => $studentClass?->getAttribute('ID'),
                'attempt_no' => $attemptNo,
                'score' => $score,
                'max_score_snapshot' => $maxScore,
                'percent' => round(($score / $maxScore) * 100, 2),
                'status' => 'submitted',
                'notes' => $data['notes'] ?? null,
                'recorded_by_user_id' => $this->userId($request),
                'recorded_at' => now(),
            ]);
            $this->audit($request, $assessment, $result, 'result_created', null, $result->fresh()->toArray());

            return $result;
        });

        return response()->json(['data' => $this->resultPayload($result->load(['student', 'studentClass']))], 201);
    }

    public function updateResult(Request $request, AssessmentResult $assessmentResult)
    {
        $assessment = $assessmentResult->assessment;
        if (!$assessment || !$this->canManageAssessment($request, $assessment)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ($assessmentResult->status !== 'submitted') {
            return response()->json(['message' => '只有待審結果可以修改'], 409);
        }

        $data = $request->validate([
            'score' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);
        $score = array_key_exists('score', $data) ? (float) $data['score'] : (float) $assessmentResult->score;
        $maxScore = (float) $assessmentResult->max_score_snapshot;
        if ($score > $maxScore) {
            throw ValidationException::withMessages(['score' => '分數不可超過滿分。']);
        }

        $assessmentResult = DB::transaction(function () use ($assessmentResult, $data, $score, $maxScore, $request, $assessment) {
            $before = $assessmentResult->fresh()->toArray();
            $assessmentResult->fill($data);
            $assessmentResult->score = $score;
            $assessmentResult->percent = round(($score / $maxScore) * 100, 2);
            $assessmentResult->save();
            $this->audit($request, $assessment, $assessmentResult, 'result_updated', $before, $assessmentResult->fresh()->toArray());
            return $assessmentResult;
        });

        return response()->json(['data' => $this->resultPayload($assessmentResult->load(['student', 'studentClass']))]);
    }

    public function reviewResult(Request $request, AssessmentResult $assessmentResult)
    {
        return $this->transitionResult($request, $assessmentResult, 'reviewed', 'result_reviewed', 'reviewed_by_user_id', 'reviewed_at');
    }

    public function voidResult(Request $request, AssessmentResult $assessmentResult)
    {
        if (!$request->filled('reason')) {
            throw ValidationException::withMessages(['reason' => '作廢結果必須填寫原因。']);
        }
        return $this->transitionResult($request, $assessmentResult, 'voided', 'result_voided', null, null, (string) $request->input('reason'));
    }

    public function summary(Request $request)
    {
        $query = $this->accessibleAssessments($request)->with(['results' => fn ($q) => $q->where('status', '!=', 'voided')]);
        if ($request->filled('campus_id')) {
            $campusId = (int) $request->input('campus_id');
            if (!$this->campusAllowed($request, $campusId)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $query->where('campus_id', $campusId);
        }

        $assessments = $query->get();
        $results = $assessments->flatMap(fn (Assessment $assessment) => $assessment->results);
        $remediationActions = AssessmentRemediationAction::query()
            ->whereIn('assessment_id', $assessments->pluck('id'))
            ->get();

        return response()->json([
            'data' => [
                'assessment_count' => $assessments->count(),
                'result_count' => $results->count(),
                'average_percent' => $results->count() ? round((float) $results->avg('percent'), 2) : null,
                'reviewed_count' => $results->where('status', 'reviewed')->count(),
                'remediation_open_count' => $remediationActions->whereIn('status', ['open', 'in_progress'])->count(),
                'remediation_overdue_count' => $remediationActions
                    ->whereIn('status', ['open', 'in_progress'])
                    ->filter(fn (AssessmentRemediationAction $action) => $action->due_date && $action->due_date->isPast())
                    ->count(),
                'remediation_completed_count' => $remediationActions->where('status', 'completed')->count(),
            ],
        ]);
    }

    private function transitionResult(Request $request, AssessmentResult $result, string $to, string $action, ?string $userColumn, ?string $timeColumn, ?string $reason = null)
    {
        $assessment = $result->assessment;
        if (!$this->directorOnly($request) || !$assessment || !$this->canAccessAssessment($request, $assessment)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ($result->status === $to) {
            return response()->json(['data' => $this->resultPayload($result->load(['student', 'studentClass']))]);
        }
        if ($result->status !== 'submitted') {
            return response()->json(['message' => '目前結果狀態不可變更'], 409);
        }

        $result = DB::transaction(function () use ($result, $to, $userColumn, $timeColumn, $request, $assessment, $action, $reason) {
            $before = $result->fresh()->toArray();
            $result->status = $to;
            if ($userColumn) {
                $result->{$userColumn} = $this->userId($request);
            }
            if ($timeColumn) {
                $result->{$timeColumn} = now();
            }
            $result->save();
            $this->audit($request, $assessment, $result, $action, $before, $result->fresh()->toArray(), $reason);
            return $result;
        });

        return response()->json(['data' => $this->resultPayload($result->load(['student', 'studentClass']))]);
    }

    private function validatedAssessment(Request $request, bool $partial = false, ?Assessment $assessment = null): array
    {
        $rules = [
            'campus_id' => [$partial ? 'sometimes' : 'required', 'integer', 'min:1'],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'student_class_id' => ['nullable', 'integer', 'min:1'],
            'title' => [$partial ? 'sometimes' : 'required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:10000'],
            'assessment_type' => ['nullable', 'in:baseline,checkpoint,remediation,other'],
            'scheduled_for' => ['nullable', 'date'],
            'max_score' => [$partial ? 'sometimes' : 'required', 'numeric', 'gt:0'],
            'passing_score' => ['nullable', 'numeric', 'min:0'],
        ];
        $data = $request->validate($rules);
        $maxScore = (float) ($data['max_score'] ?? ($assessment !== null ? $assessment->max_score : 0));
        if (array_key_exists('passing_score', $data) && $data['passing_score'] !== null && $maxScore > 0 && (float) $data['passing_score'] > $maxScore) {
            throw ValidationException::withMessages(['passing_score' => '及格分不可超過滿分。']);
        }
        return $data;
    }

    private function accessibleAssessments(Request $request): Builder
    {
        $query = Assessment::query();
        if (!$this->isSuperAdmin($request)) {
            $campusIds = $this->campusIds($request);
            $query->whereIn('campus_id', $campusIds ?: [0]);
        }
        if ($this->role($request) === 'teacher') {
            $userId = $this->userId($request);
            $query->where(function (Builder $q) use ($userId) {
                $q->where('created_by_user_id', $userId)
                    ->orWhereHas('studentClass', fn (Builder $sc) => $sc->where('TeacherID', $userId));
            });
        }
        return $query;
    }

    private function canAccessAssessment(Request $request, Assessment $assessment): bool
    {
        return $this->accessibleAssessments($request)->whereKey($assessment->id)->exists();
    }

    private function canManageAssessment(Request $request, Assessment $assessment): bool
    {
        if (!$this->canAccessAssessment($request, $assessment)) {
            return false;
        }
        if ($this->isDirector($request)) {
            return true;
        }
        return (int) $assessment->created_by_user_id === $this->userId($request)
            || (int) data_get($assessment->studentClass, 'TeacherID', 0) === $this->userId($request);
    }

    private function resolveStudentClass(Request $request, $studentClassId, int $campusId): ?StudentClass
    {
        if (!$studentClassId) {
            return null;
        }
        $studentClass = StudentClass::query()->with('student')->find((int) $studentClassId);
        if (!$studentClass instanceof StudentClass) {
            abort(403, 'Forbidden');
        }
        $student = $studentClass->getRelationValue('student');
        if (!$student instanceof Student || (int) $student->getAttribute('CampusID') !== $campusId) {
            abort(403, 'Forbidden');
        }
        if ($this->role($request) === 'teacher' && (int) $studentClass->getAttribute('TeacherID') !== $this->userId($request)) {
            abort(403, 'Forbidden');
        }
        return $studentClass;
    }

    private function audit(Request $request, Assessment $assessment, ?AssessmentResult $result, string $action, ?array $before, ?array $after, ?string $reason = null): void
    {
        AssessmentAuditLog::create([
            'assessment_id' => $assessment->id,
            'assessment_result_id' => $result?->id,
            'campus_id' => $assessment->campus_id,
            'actor_user_id' => $this->userId($request),
            'action' => $action,
            'reason' => $reason,
            'before' => $before,
            'after' => $after,
        ]);
    }

    private function assessmentPayload(Assessment $assessment): array
    {
        return [
            'id' => (int) $assessment->id,
            'campus_id' => (int) $assessment->campus_id,
            'subject_id' => $assessment->subject_id !== null ? (int) $assessment->subject_id : null,
            'student_class_id' => $assessment->student_class_id !== null ? (int) $assessment->student_class_id : null,
            'title' => $assessment->title,
            'description' => $assessment->description,
            'assessment_type' => $assessment->assessment_type,
            'status' => $assessment->status,
            'scheduled_for' => optional($assessment->scheduled_for)->format('Y-m-d'),
            'max_score' => (float) $assessment->max_score,
            'passing_score' => $assessment->passing_score !== null ? (float) $assessment->passing_score : null,
            'result_count' => isset($assessment->result_count) ? (int) $assessment->result_count : $assessment->results->where('status', '!=', 'voided')->count(),
            'student_name' => data_get($assessment->getRelationValue('studentClass')?->getRelationValue('student'), 'name'),
            'created_at' => optional($assessment->created_at)->toISOString(),
        ];
    }

    private function resultPayload(AssessmentResult $result): array
    {
        return [
            'id' => (int) $result->id,
            'assessment_id' => (int) $result->assessment_id,
            'student_id' => (int) $result->student_id,
            'student_class_id' => $result->student_class_id !== null ? (int) $result->student_class_id : null,
            'attempt_no' => (int) $result->attempt_no,
            'score' => (float) $result->score,
            'max_score' => (float) $result->max_score_snapshot,
            'percent' => (float) $result->percent,
            'status' => $result->status,
            'notes' => $result->notes,
            'student_name' => optional($result->student)->name,
            'recorded_at' => optional($result->recorded_at)->toISOString(),
            'reviewed_at' => optional($result->reviewed_at)->toISOString(),
            'remediation_count' => isset($result->remediation_actions_count)
                ? (int) $result->remediation_actions_count
                : $result->remediationActions()->count(),
        ];
    }

    private function remediationPayload(AssessmentRemediationAction $action): array
    {
        return [
            'id' => (int) $action->id,
            'assessment_id' => (int) $action->assessment_id,
            'assessment_result_id' => (int) $action->assessment_result_id,
            'student_id' => (int) $action->student_id,
            'student_class_id' => $action->student_class_id !== null ? (int) $action->student_class_id : null,
            'knowledge_tag' => $action->knowledge_tag,
            'action_type' => $action->action_type,
            'status' => $action->status,
            'plan' => $action->plan,
            'due_date' => optional($action->due_date)->format('Y-m-d'),
            'notes' => $action->notes,
            'created_at' => optional($action->created_at)->toISOString(),
            'started_at' => optional($action->started_at)->toISOString(),
            'completed_at' => optional($action->completed_at)->toISOString(),
        ];
    }

    private function directorOnly(Request $request): bool
    {
        return in_array($this->role($request), ['director', 'super_admin'], true);
    }

    private function isDirector(Request $request): bool
    {
        return $this->directorOnly($request);
    }

    private function role(Request $request): string
    {
        return (string) $request->attributes->get('auth_role', '');
    }

    private function userId(Request $request): ?int
    {
        $user = $request->attributes->get('auth_user');
        return $user?->id ? (int) $user->id : null;
    }

    private function isSuperAdmin(Request $request): bool
    {
        return $this->role($request) === 'super_admin';
    }

    private function campusIds(Request $request): array
    {
        return array_values(array_filter(array_map('intval', (array) $request->attributes->get('auth_campus_ids', []))));
    }

    private function campusAllowed(Request $request, int $campusId): bool
    {
        return $this->isSuperAdmin($request) || in_array($campusId, $this->campusIds($request), true);
    }

    private function csv($value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value))));
    }
}
