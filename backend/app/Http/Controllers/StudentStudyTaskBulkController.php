<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesLearningPlanAccess;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentStudyTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 讀書任務的快速工具 (#5269 step 3): 複製上週、套用到同班其他學生. Same access rules as StudentStudyTaskController.
 *
 * STATE RULE
 *  - Copies are always NEW, not-done tasks (done_at / done_by empty, created_by = the caller). The source tasks are never changed.
 *  - Copy keeps title, kind, minutes and note. A copy within one student also keeps plan_unit_id; a copy to ANOTHER student
 *    drops it (the unit belongs to the source student's plan).
 *  - Copy last week: the source week is the 7 days before `week_start` (a Monday); each task lands on the same weekday.
 *  - Order inside a day is the id order; copies are created in source order, so the order carries over (no sort column needed).
 *
 * DUPLICATE RULE (a repeat click must not double the work)
 *  - A task is skipped when the target student already has a task on the target day with the same title and kind
 *    (title compared trimmed, case-insensitive per the database collation). This also covers two identical source rows.
 *  - The check and the insert run in one transaction after locking the target student row(s), so two clicks at once cannot both insert.
 *
 * APPLY TO CLASSMATES
 *  - Candidates = other enabled students of the same campus with an active course (Stop = 0) of the same teacher, subject and
 *    grade as an active course of the source student, and only those the caller may write for (`studentWritable`).
 *  - Each target gets one result: created | skipped (duplicate rule) | refused (not a classmate, not found, other campus, or the
 *    caller may not write for them; the three are not told apart so ids of other campuses are not probed).
 *  - A refused target never blocks the others. At most MAX_TARGETS targets per request.
 */
class StudentStudyTaskBulkController extends Controller
{
    use ScopesLearningPlanAccess;

    private const MAX_TARGETS = 30;

    private const MAX_COPY_ROWS = 100;

    /** Classmates the caller could apply a task to (id and name only). */
    public function classmates(Request $request, Student $student): JsonResponse
    {
        if (!$this->studentWritable($request, $student)) {
            return $this->forbidden();
        }
        $rows = Student::query()->whereIn('id', $this->classmateIds($student))->orderBy('name')->orderBy('id')->get(['id', 'name', 'CampusID']);
        $data = $rows->filter(fn (Student $s) => $this->studentWritable($request, $s))
            ->map(fn (Student $s) => ['id' => (int) $s->id, 'name' => (string) $s->name])->values()->all();

        return response()->json(['data' => $data, 'max_targets' => self::MAX_TARGETS]);
    }

    public function copyWeek(Request $request, Student $student): JsonResponse
    {
        if (!$this->studentWritable($request, $student)) {
            return $this->forbidden();
        }
        $data = $request->validate(['week_start' => ['required', 'date_format:Y-m-d']]);
        $start = Carbon::createFromFormat('!Y-m-d', $data['week_start']);
        if (!$start->isMonday()) {
            return response()->json(['message' => '請從週一開始的那一週複製'], 422);
        }
        $from = $start->copy()->subDays(7)->toDateString();
        $to = $start->copy()->subDay()->toDateString();
        $source = StudentStudyTask::query()->where('campus_id', $student->CampusID)->where('student_id', $student->id)
            ->whereBetween('due_date', [$from, $to])->orderBy('due_date')->orderBy('id')->limit(self::MAX_COPY_ROWS + 1)->get();
        if ($source->count() > self::MAX_COPY_ROWS) {
            return response()->json(['message' => '上週的任務太多，請分批安排'], 422);
        }
        $by = $request->attributes->get('auth_user')?->id;
        $created = [];
        $skipped = 0;
        DB::transaction(function () use ($student, $source, $by, &$created, &$skipped) {
            Student::query()->whereKey($student->id)->lockForUpdate()->first();
            $taken = $this->takenKeys((int) $student->CampusID, [(int) $student->id], $source->map(fn ($t) => $this->shift($t))->all());
            foreach ($source as $t) {
                $day = $this->shift($t);
                $key = $this->key((int) $student->id, $day, $t->kind, $t->title);
                if (isset($taken[$key])) {
                    $skipped++;

                    continue;
                }
                $taken[$key] = true;
                $created[] = $this->copyTo($t, $student, $day, $by, true);
            }
        });

        return response()->json(['data' => $created, 'skipped' => $skipped]);
    }

    public function applyToClassmates(Request $request, StudentStudyTask $task): JsonResponse
    {
        $source = Student::query()->where('id', $task->student_id)->where('CampusID', $task->campus_id)->first();
        abort_if($source === null, 404);
        abort_unless($this->studentWritable($request, $source), 403, 'Forbidden');
        $data = $request->validate(['student_ids' => ['required', 'array', 'min:1', 'max:' . self::MAX_TARGETS], 'student_ids.*' => ['integer', 'min:1']]);
        $ids = array_values(array_unique(array_map('intval', $data['student_ids'])));
        $mates = array_flip($this->classmateIds($source));
        $results = [];
        $by = $request->attributes->get('auth_user')?->id;

        DB::transaction(function () use ($ids, $mates, $request, $task, $by, &$results) {
            $targets = Student::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $day = $task->due_date->toDateString();
            $taken = $this->takenKeys((int) $task->campus_id, $ids, [$day]);
            foreach ($ids as $id) {
                $t = $targets->get($id);
                if ($t === null || !isset($mates[$id]) || (int) $t->CampusID !== (int) $task->campus_id || !$this->studentWritable($request, $t)) {
                    $results[] = ['student_id' => $id, 'status' => 'refused'];

                    continue;
                }
                $key = $this->key($id, $day, $task->kind, $task->title);
                if (isset($taken[$key])) {
                    $results[] = ['student_id' => $id, 'status' => 'skipped'];

                    continue;
                }
                $taken[$key] = true;
                $this->copyTo($task, $t, $day, $by, false);
                $results[] = ['student_id' => $id, 'status' => 'created'];
            }
        });
        $count = fn (string $s) => count(array_filter($results, fn ($r) => $r['status'] === $s));

        return response()->json(['data' => ['results' => $results, 'created' => $count('created'), 'skipped' => $count('skipped'), 'refused' => $count('refused')]]);
    }

    private function shift(StudentStudyTask $t): string
    {
        return $t->due_date->copy()->addDays(7)->toDateString();
    }

    private function key(int $studentId, string $day, string $kind, string $title): string
    {
        return $studentId . '|' . $day . '|' . $kind . '|' . mb_strtolower(trim($title));
    }

    /**
     * Keys of tasks the students already have on the given days.
     *
     * @param  list<int>  $studentIds
     * @param  list<string>  $days
     * @return array<string, true>
     */
    private function takenKeys(int $campusId, array $studentIds, array $days): array
    {
        $out = [];
        $rows = StudentStudyTask::query()->where('campus_id', $campusId)->whereIn('student_id', $studentIds)->whereIn('due_date', array_values(array_unique($days)))->get();
        foreach ($rows as $r) {
            $out[$this->key((int) $r->student_id, $r->due_date->toDateString(), $r->kind, $r->title)] = true;
        }

        return $out;
    }

    private function copyTo(StudentStudyTask $from, Student $to, string $day, ?int $by, bool $keepUnit): StudentStudyTask
    {
        return StudentStudyTask::create([
            'campus_id' => (int) $to->CampusID, 'student_id' => $to->id, 'plan_unit_id' => $keepUnit ? $from->plan_unit_id : null,
            'title' => $from->title, 'kind' => $from->kind, 'due_date' => $day, 'minutes' => $from->minutes, 'note' => $from->note,
            'created_by' => $by,
        ])->fresh();
    }

    /**
     * Other enabled students of the same campus sharing an active (teacher, subject, grade) course with this student.
     *
     * @return list<int>
     */
    private function classmateIds(Student $student): array
    {
        $own = StudentClass::query()->where('StudentID', $student->id)->where('Stop', 0)->get(['TeacherID', 'SubjectID', 'GradeID']);
        if ($own->isEmpty()) {
            return [];
        }
        $ids = StudentClass::query()->where('Stop', 0)->where('StudentID', '!=', $student->id)->where(function ($q) use ($own) {
            foreach ($own as $c) {
                $q->orWhere(fn ($w) => $w->where('TeacherID', $c->TeacherID)->where('SubjectID', $c->SubjectID)->where('GradeID', $c->GradeID));
            }
        })->pluck('StudentID')->unique()->all();

        return Student::query()->whereIn('id', $ids)->where('CampusID', $student->CampusID)->where('enable', 1)->pluck('id')->map(fn ($i) => (int) $i)->all();
    }
}
