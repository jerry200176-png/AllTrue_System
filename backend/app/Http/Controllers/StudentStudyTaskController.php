<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesLearningPlanAccess;
use App\Models\Student;
use App\Models\StudentLearningPlan;
use App\Models\StudentPlanUnit;
use App\Models\StudentStudyTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 學生讀書任務 API (#5269 step 1). Campus-scoped via ScopesLearningPlanAccess, same rules as the learning plans:
 * reads: director, super_admin, a teacher who can see one of the student's courses;
 * writes: director, super_admin, a teacher who owns one of the student's courses. Unauthorised callers get 403
 * before any validation. The parent portal read endpoint is a later step.
 */
class StudentStudyTaskController extends Controller
{
    use ScopesLearningPlanAccess;

    private const DATE = ['date_format:Y-m-d'];

    private const MAX_ROWS = 500;

    /** Tasks of one student, oldest due date first; optional from/to (due date, inclusive). */
    public function index(Request $request, Student $student): JsonResponse
    {
        if (!$this->studentReadable($request, $student)) {
            return $this->forbidden();
        }
        $data = $request->validate(['from' => ['sometimes', ...self::DATE], 'to' => ['sometimes', ...self::DATE, 'after_or_equal:from']]);
        $q = StudentStudyTask::query()->where('campus_id', $student->CampusID)->where('student_id', $student->id);
        if (isset($data['from'])) {
            $q->where('due_date', '>=', $data['from']);
        }
        if (isset($data['to'])) {
            $q->where('due_date', '<=', $data['to']);
        }

        return response()->json(['data' => $q->orderBy('due_date')->orderBy('id')->limit(self::MAX_ROWS)->get()->all()]);
    }

    public function store(Request $request, Student $student): JsonResponse
    {
        if (!$this->studentWritable($request, $student)) {
            return $this->forbidden();
        }
        $data = $request->validate([
            'title' => ['required', 'string', 'min:1', 'max:120'], 'kind' => ['sometimes', Rule::in(StudentStudyTask::KINDS)],
            'due_date' => ['required', ...self::DATE], 'minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'note' => ['nullable', 'string', 'max:255'], 'plan_unit_id' => ['nullable', 'integer', 'min:1'],
        ]);
        if (!empty($data['plan_unit_id']) && !$this->unitOfStudent((int) $data['plan_unit_id'], $student)) {
            return response()->json(['message' => '找不到這位學生計畫裡的這個單元'], 422);
        }
        $task = StudentStudyTask::create([
            'campus_id' => (int) $student->CampusID, 'student_id' => $student->id, 'plan_unit_id' => $data['plan_unit_id'] ?? null,
            'title' => trim($data['title']), 'kind' => $data['kind'] ?? 'other', 'due_date' => $data['due_date'],
            'minutes' => $data['minutes'] ?? null, 'note' => $this->cleanNote($data['note'] ?? null),
            'created_by' => $request->attributes->get('auth_user')?->id,
        ]);

        return response()->json(['data' => $task->fresh()], 201);
    }

    /** Edit, move to another day, or tick / untick (`done`). */
    public function update(Request $request, StudentStudyTask $task): JsonResponse
    {
        $student = $this->writableStudent($request, $task);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'min:1', 'max:120'], 'kind' => ['sometimes', Rule::in(StudentStudyTask::KINDS)],
            'due_date' => ['sometimes', ...self::DATE], 'minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1440'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'], 'plan_unit_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'done' => ['sometimes', 'boolean'],
        ]);
        if (!empty($data['plan_unit_id']) && !$this->unitOfStudent((int) $data['plan_unit_id'], $student)) {
            return response()->json(['message' => '找不到這位學生計畫裡的這個單元'], 422);
        }
        $changes = array_intersect_key($data, ['kind' => 1, 'due_date' => 1, 'minutes' => 1, 'plan_unit_id' => 1]);
        if (isset($data['title'])) {
            $changes['title'] = trim($data['title']);
        }
        if (array_key_exists('note', $data)) {
            $changes['note'] = $this->cleanNote($data['note']);
        }
        if (isset($data['done'])) {
            $changes += !$data['done'] ? ['done_at' => null, 'done_by' => null]
                : ($task->done_at === null ? ['done_at' => now(), 'done_by' => $request->attributes->get('auth_user')?->id] : []);
        }
        $task->update($changes);

        return response()->json(['data' => $task->fresh()]);
    }

    public function destroy(Request $request, StudentStudyTask $task): JsonResponse
    {
        $this->writableStudent($request, $task);
        $task->delete();

        return response()->json(['message' => 'ok']);
    }

    /** The task's student when the caller may write for them, else 403 (404 if the student is gone). */
    private function writableStudent(Request $request, StudentStudyTask $task): Student
    {
        $student = Student::query()->where('id', $task->student_id)->where('CampusID', $task->campus_id)->first();
        abort_if($student === null, 404);
        abort_unless($this->studentWritable($request, $student), 403, 'Forbidden');

        return $student;
    }

    /** The unit belongs to a plan of this student in the same campus. */
    private function unitOfStudent(int $unitId, Student $student): bool
    {
        $planId = StudentPlanUnit::query()->whereKey($unitId)->value('plan_id');

        return $planId !== null && StudentLearningPlan::query()->whereKey($planId)
            ->where('student_id', $student->id)->where('campus_id', $student->CampusID)->exists();
    }

    private function cleanNote(?string $note): ?string
    {
        return trim((string) $note) ?: null;
    }
}
