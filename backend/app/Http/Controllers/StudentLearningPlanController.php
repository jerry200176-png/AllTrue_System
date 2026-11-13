<?php

namespace App\Http\Controllers;

use App\Models\CurriculumTemplate;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentLearningPlan;
use App\Models\StudentPlanUnit;
use App\Services\LearningPlanScheduler;
use App\Services\StudentExamPeriodResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * 學生學習計畫 API (#4970 step 4a). Campus-scoped; archive, never delete. Units are copied from a template and
 * dated by LearningPlanScheduler from the exam periods inside the plan range (any term).
 * Reads: director, super_admin, a teacher for courses visible to them (StudentClass::visibleToTeacher).
 * Writes: director, super_admin, a teacher only for courses they own (TeacherID), not as substitute/makeup.
 */
class StudentLearningPlanController extends Controller
{
    private const DATE = ['date_format:Y-m-d'];

    public function __construct(private StudentExamPeriodResolver $exams)
    {
    }

    public function index(Request $request, Student $student): JsonResponse
    {
        if (!$this->campusAllowed($request, (int) $student->CampusID)) {
            return $this->forbidden();
        }
        $q = StudentLearningPlan::query()->where('campus_id', $student->CampusID)->where('student_id', $student->id);
        if ($this->teacherId($request) !== null) {
            $visible = StudentClass::query()->where('StudentID', $student->id)->visibleToTeacher($this->teacherId($request))
                ->pluck('ID')->map(fn ($id) => (int) $id)->all();
            if ($visible === []) {
                return $this->forbidden();
            }
            $q->whereIn('student_class_id', $visible);
        }
        $request->validate(['status' => ['sometimes', Rule::in(StudentLearningPlan::STATUSES)]]);
        if ($request->filled('status')) {
            $q->where('status', (string) $request->input('status'));
        } else {
            $q->whereIn('status', ['draft', 'active']);
        }

        return response()->json(['data' => $q->with('units')->orderByDesc('id')->get()->map(fn ($p) => $this->present($p, $student))->all()]);
    }

    public function store(Request $request, Student $student): JsonResponse
    {
        if (!$this->campusAllowed($request, (int) $student->CampusID)) {
            return $this->forbidden();
        }
        $data = $request->validate([
            'student_class_id' => ['required', 'integer', 'min:1'], 'template_id' => ['required', 'integer', 'min:1'],
            'plan_start' => ['required', ...self::DATE], 'plan_end' => ['nullable', ...self::DATE, 'after_or_equal:plan_start'],
            'title' => ['nullable', 'string', 'max:120'],
        ]);
        // The student's own course; campus was checked on the student (StudentClass.by1 is a class-type code, not a campus).
        $course = StudentClass::query()->where('ID', $data['student_class_id'])->where('StudentID', $student->id)->first();
        if ($course === null) {
            return response()->json(['message' => '找不到這位學生的這門課'], 422);
        }
        if (!$this->courseWritable($request, (int) $course->ID)) {
            return $this->forbidden();
        }
        $template = CurriculumTemplate::query()->whereKey($data['template_id'])->where('campus_id', $student->CampusID)->where('status', 'active')->first();
        if ($template === null) {
            return response()->json(['message' => '找不到可用的教材範本（需為使用中）'], 422);
        }
        $start = $this->day($data['plan_start']);
        $end = isset($data['plan_end']) ? $this->day($data['plan_end']) : $start->copy()->addWeeks(18);

        $plan = null;
        $error = DB::transaction(function () use ($request, $student, $course, $template, $data, $start, $end, &$plan) {
            // Same lock as update(): the course row, so two requests cannot both pass the one-open-plan check.
            $locked = StudentClass::query()->where('ID', $course->ID)->lockForUpdate()->first();
            if ($locked === null || (int) $locked->Stop !== 0) {
                return '這門課已停課，不能建立學習計畫';
            }
            if ($this->hasOpenPlan((int) $course->ID)) {
                return '這門課已經有學習計畫';
            }
            $plan = StudentLearningPlan::create([
                'campus_id' => (int) $student->CampusID, 'student_id' => $student->id, 'student_class_id' => (int) $course->ID,
                'source_template_id' => $template->id, 'title' => trim((string) ($data['title'] ?? '')) ?: $template->title,
                'status' => 'active', 'plan_start' => $start->toDateString(), 'plan_end' => $end->toDateString(),
                'created_by' => $request->attributes->get('auth_user')?->id,
            ]);
            foreach ($template->units()->get()->values() as $i => $u) {
                StudentPlanUnit::create(['plan_id' => $plan->id, 'sort_order' => $i + 1, 'title' => $u->title, 'note' => $u->note,
                    'exam_sequence' => $u->exam_sequence, 'planned_start' => $start->toDateString(), 'planned_end' => $end->toDateString()]);
            }
            $this->applySchedule($plan);

            return null;
        });

        return $error !== null ? response()->json(['message' => $error], 422)
            : response()->json(['data' => $this->present($plan->load('units'), $student)], 201);
    }

    /** Title, status, and the plan range; touching either date re-dates every non-manual unit. */
    public function update(Request $request, StudentLearningPlan $plan): JsonResponse
    {
        $data = $this->planInput($request, $plan, [
            'title' => ['sometimes', 'string', 'min:1', 'max:120'], 'status' => ['sometimes', Rule::in(StudentLearningPlan::STATUSES)],
            'plan_start' => ['sometimes', ...self::DATE], 'plan_end' => ['sometimes', ...self::DATE],
        ]);

        return $this->mutate($plan, function (StudentLearningPlan $locked) use ($data) {
            if ($locked->status === 'archived' && array_diff(array_keys($data), ['status']) !== []) {
                return '已封存的計畫只能改回使用中，其他欄位請先還原再修改';
            }
            $start = $this->day($data['plan_start'] ?? $locked->plan_start->toDateString());
            $end = $this->day($data['plan_end'] ?? $locked->plan_end->toDateString());
            if ($start->gt($end)) {
                return '計畫結束日不能早於開始日';
            }
            if (($data['status'] ?? 'archived') !== 'archived' && $locked->status === 'archived' && $this->hasOpenPlan((int) $locked->student_class_id)) {
                return '這門課已經有另一份學習計畫';
            }
            $outside = $locked->units()->get()->filter(fn ($u) => $u->dates_manual && ($u->planned_start->lt($start) || $u->planned_end->gt($end)))->count();
            if ($outside > 0) {
                return "有 {$outside} 個手動排好日期的單元會落在新的計畫期間外，請先調整這些單元的日期";
            }
            $locked->update(array_filter(['title' => isset($data['title']) ? trim($data['title']) : null, 'status' => $data['status'] ?? null],
                fn ($v) => $v !== null) + ['plan_start' => $start->toDateString(), 'plan_end' => $end->toDateString()]);
            if (isset($data['plan_start']) || isset($data['plan_end'])) {
                $this->applySchedule($locked);
            }

            return null;
        });
    }

    /** Edit one unit. Dates make it manual; `dates_manual:false` hands it back to the scheduler; `completed` ticks it. */
    public function updateUnit(Request $request, StudentLearningPlan $plan, StudentPlanUnit $unit): JsonResponse
    {
        $data = $this->planInput($request, $plan, [
            'title' => ['sometimes', 'string', 'min:1', 'max:120'], 'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'planned_start' => ['sometimes', ...self::DATE], 'planned_end' => ['sometimes', ...self::DATE],
            'completed' => ['sometimes', 'boolean'], 'dates_manual' => ['sometimes', 'boolean'],
        ]);
        abort_if((int) $unit->plan_id !== (int) $plan->id, 404);
        $hasDates = isset($data['planned_start']) || isset($data['planned_end']);
        if ($hasDates && array_key_exists('dates_manual', $data) && !$data['dates_manual']) {
            return response()->json(['message' => '不能同時改日期又要求自動排程'], 422);
        }

        return $this->mutate($plan, function (StudentLearningPlan $locked) use ($request, $unit, $data, $hasDates) {
            if ($locked->status === 'archived') {
                return '已封存的計畫不能再修改單元';
            }
            $u = StudentPlanUnit::query()->whereKey($unit->id)->first();
            $changes = array_intersect_key($data, ['title' => 1, 'dates_manual' => 1]);
            if (array_key_exists('note', $data)) {
                $changes['note'] = trim((string) $data['note']) ?: null;
            }
            if ($hasDates) {
                $from = $this->day($data['planned_start'] ?? $u->planned_start->toDateString());
                $to = $this->day($data['planned_end'] ?? $u->planned_end->toDateString());
                if ($from->gt($to) || $from->lt($locked->plan_start) || $to->gt($locked->plan_end)) {
                    return '單元日期要在計畫期間內，且開始日不能晚於結束日';
                }
                $changes += ['planned_start' => $from->toDateString(), 'planned_end' => $to->toDateString(), 'dates_manual' => true];
            }
            if (isset($data['completed'])) {
                $changes += !$data['completed'] ? ['completed_at' => null, 'completed_by' => null]
                    : ($u->completed_at === null ? ['completed_at' => now(), 'completed_by' => $request->attributes->get('auth_user')?->id] : []);
            }
            $u->update($changes);
            if (!$hasDates && isset($data['dates_manual']) && !$data['dates_manual']) {
                $this->applySchedule($locked);
            }

            return null;
        });
    }

    /** Authorise, then validate. Unauthorised callers never learn what is wrong with their input. */
    private function planInput(Request $request, StudentLearningPlan $plan, array $rules): array
    {
        abort_unless($this->campusAllowed($request, (int) $plan->campus_id) && $this->courseWritable($request, (int) $plan->student_class_id), 403, 'Forbidden');

        return $request->validate($rules);
    }

    /** Run $fn on the locked plan (course row first, as store() does); a returned string is a 422 message. */
    private function mutate(StudentLearningPlan $plan, callable $fn): JsonResponse
    {
        $error = DB::transaction(function () use ($plan, $fn) {
            StudentClass::query()->where('ID', $plan->student_class_id)->lockForUpdate()->first();
            $locked = StudentLearningPlan::query()->whereKey($plan->id)->lockForUpdate()->first();
            abort_if($locked === null, 404);

            return $fn($locked);
        });
        if ($error !== null) {
            return response()->json(['message' => $error], 422);
        }
        $plan = StudentLearningPlan::query()->with('units')->findOrFail($plan->id);

        return response()->json(['data' => $this->present($plan, Student::query()->find($plan->student_id))]);
    }

    /** Re-date every non-manual unit from the plan range and the exam periods inside it. */
    private function applySchedule(StudentLearningPlan $plan): void
    {
        $units = $plan->units()->get()->values();
        $student = Student::query()->find($plan->student_id);
        $dates = LearningPlanScheduler::schedule(
            $units->map(fn ($u) => ['exam_sequence' => $u->exam_sequence, 'dates_manual' => $u->dates_manual])->all(),
            $plan->plan_start->toDateString(), $plan->plan_end->toDateString(), $this->examRows($student, $plan),
        );
        foreach ($units as $i => $u) {
            if (isset($dates[$i])) {
                $u->update(['planned_start' => $dates[$i]['start'], 'planned_end' => $dates[$i]['end']]);
            }
        }
    }

    /** Stored fields plus derived (never stored) percent_done, units_behind and next_exam. */
    private function present(StudentLearningPlan $plan, ?Student $student): array
    {
        $today = now()->startOfDay();
        $units = $plan->units;
        $done = $units->filter(fn ($u) => $u->completed_at !== null)->count();
        $next = collect($this->examRows($student, $plan))->first(fn ($e) => $this->day($e['start_date'])->gte($today));

        return $plan->toArray() + [
            'percent_done' => $units->isEmpty() ? 0 : (int) round($done * 100 / $units->count()),
            'units_behind' => $units->filter(fn ($u) => $u->completed_at === null && $u->planned_end->lt($today))->count(),
            'next_exam' => $next === null ? null : $next + ['days_left' => (int) $today->diffInDays($this->day($next['start_date']))],
        ];
    }

    /** @return list<array{sequence: int, label: string, start_date: string, academic_year: int, semester: int}> in date order */
    private function examRows(?Student $student, StudentLearningPlan $plan): array
    {
        return $student === null ? [] : $this->exams->forRange($student, $plan->plan_start, $plan->plan_end)->map(fn ($p) => [
            'sequence' => (int) $p->seq, 'label' => (string) $p->label, 'start_date' => $p->start_date->toDateString(),
            'academic_year' => (int) $p->academic_year, 'semester' => (int) $p->semester,
        ])->all();
    }

    private function day(string $date): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
    }

    private function hasOpenPlan(int $courseId): bool
    {
        return StudentLearningPlan::query()->where('student_class_id', $courseId)->where('status', '!=', 'archived')->exists();
    }

    private function teacherId(Request $request): ?int
    {
        return $request->attributes->get('auth_role') === 'teacher' ? max(0, (int) $request->attributes->get('auth_teacher_id')) : null;
    }

    /** Director/super_admin: any course in their campus. Teacher: only the courses they own. */
    private function courseWritable(Request $request, int $courseId): bool
    {
        $teacherId = $this->teacherId($request);

        return $teacherId === null || ($teacherId > 0 && StudentClass::query()->where('ID', $courseId)->where('TeacherID', $teacherId)->exists());
    }

    private function campusAllowed(Request $request, int $campusId): bool
    {
        return $campusId > 0 && ($request->attributes->get('auth_role') === 'super_admin'
            || in_array($campusId, array_map('intval', (array) $request->attributes->get('auth_campus_ids', [])), true));
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['message' => 'Forbidden'], 403);
    }
}
