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
 * 學生學習計畫 API (#4970 step 4a). Campus-scoped; archive, never delete. Units are copied from a template;
 * dates come from LearningPlanScheduler and the exam periods inside the plan range (any term).
 * Reads: director, super_admin, and a teacher for courses visible to them (StudentClass::visibleToTeacher).
 * Writes: director, super_admin, and a teacher only for courses they own (StudentClass.TeacherID), not as a
 * substitute or makeup teacher.
 */
class StudentLearningPlanController extends Controller
{
    private const DEFAULT_WEEKS = 18;

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
            $visible = $this->visibleCourseIds($request, $student);
            if ($visible === []) {
                return $this->forbidden();
            }
            $q->whereIn('student_class_id', $visible);
        }
        if ($request->filled('status')) {
            $request->validate(['status' => [Rule::in(StudentLearningPlan::STATUSES)]]);
            $q->where('status', (string) $request->input('status'));
        } else {
            $q->whereIn('status', ['draft', 'active']);
        }

        return response()->json(['data' => $q->with('units')->orderByDesc('id')->get()
            ->map(fn ($p) => $this->present($p, $student))->all()]);
    }

    public function store(Request $request, Student $student): JsonResponse
    {
        if (!$this->campusAllowed($request, (int) $student->CampusID)) {
            return $this->forbidden();
        }
        $data = $request->validate([
            'student_class_id' => ['required', 'integer', 'min:1'],
            'template_id' => ['required', 'integer', 'min:1'],
            'plan_start' => ['required', ...self::DATE],
            'plan_end' => ['nullable', ...self::DATE, 'after_or_equal:plan_start'],
            'title' => ['nullable', 'string', 'max:120'],
        ]);
        // The student's own course; the student's campus was checked above (by1 is a class-type code, not a campus).
        $course = StudentClass::query()->where('ID', $data['student_class_id'])->where('StudentID', $student->id)->first();
        if ($course === null) {
            return response()->json(['message' => '找不到這位學生的這門課'], 422);
        }
        if (!$this->courseWritable($request, (int) $course->ID)) {
            return $this->forbidden();
        }
        $template = CurriculumTemplate::query()->whereKey($data['template_id'])
            ->where('campus_id', $student->CampusID)->where('status', 'active')->first();
        if ($template === null) {
            return response()->json(['message' => '找不到可用的教材範本（需為使用中）'], 422);
        }
        $start = $this->day((string) $data['plan_start']);
        $end = isset($data['plan_end']) ? $this->day((string) $data['plan_end']) : $start->copy()->addWeeks(self::DEFAULT_WEEKS);
        $title = trim((string) ($data['title'] ?? '')) ?: $template->title;

        $plan = null;
        $error = null;
        DB::transaction(function () use ($request, $student, $course, $template, $start, $end, $title, &$plan, &$error) {
            // Same lock as restoring an archived plan: the course row, so two requests cannot both pass the check.
            $locked = StudentClass::query()->where('ID', $course->ID)->lockForUpdate()->first();
            if ($locked === null || (int) $locked->Stop !== 0) {
                $error = '這門課已停課，不能建立學習計畫';
            } elseif ($this->hasOpenPlan((int) $course->ID)) {
                $error = '這門課已經有學習計畫';
            }
            if ($error !== null) {
                return;
            }
            $plan = StudentLearningPlan::create([
                'campus_id' => (int) $student->CampusID, 'student_id' => $student->id, 'student_class_id' => (int) $course->ID,
                'source_template_id' => $template->id, 'title' => $title, 'status' => 'active',
                'plan_start' => $start->toDateString(), 'plan_end' => $end->toDateString(), 'created_by' => $this->actorId($request),
            ]);
            $units = $template->units()->get();
            $dates = LearningPlanScheduler::schedule($units->map(fn ($u) => ['exam_sequence' => $u->exam_sequence])->all(),
                $start->toDateString(), $end->toDateString(), $this->examRows($student, $start, $end));
            foreach ($units->values() as $i => $u) {
                StudentPlanUnit::create([
                    'plan_id' => $plan->id, 'sort_order' => $i + 1, 'title' => $u->title, 'note' => $u->note,
                    'exam_sequence' => $u->exam_sequence,
                    'planned_start' => $dates[$i]['start'] ?? $start->toDateString(), 'planned_end' => $dates[$i]['end'] ?? $end->toDateString(),
                ]);
            }
        });
        if ($plan === null) {
            return response()->json(['message' => $error], 422);
        }

        return response()->json(['data' => $this->present($plan->load('units'), $student)], 201);
    }

    public function update(Request $request, StudentLearningPlan $plan): JsonResponse
    {
        if ($denied = $this->planDenied($request, $plan)) {
            return $denied;
        }
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'min:1', 'max:120'],
            'status' => ['sometimes', Rule::in(StudentLearningPlan::STATUSES)],
            'plan_start' => ['sometimes', ...self::DATE],
            'plan_end' => ['sometimes', ...self::DATE],
        ]);

        $error = null;
        DB::transaction(function () use ($plan, $data, &$error) {
            // Course row first (same lock as create), then the plan.
            StudentClass::query()->where('ID', $plan->student_class_id)->lockForUpdate()->first();
            $locked = StudentLearningPlan::query()->whereKey($plan->id)->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }
            if ($locked->status === 'archived' && array_diff(array_keys($data), ['status']) !== []) {
                $error = '已封存的計畫只能改回使用中，其他欄位請先還原再修改';

                return;
            }
            $start = $this->day((string) ($data['plan_start'] ?? $locked->plan_start->toDateString()));
            $end = $this->day((string) ($data['plan_end'] ?? $locked->plan_end->toDateString()));
            if ($start->gt($end)) {
                $error = '計畫結束日不能早於開始日';

                return;
            }
            if (($data['status'] ?? 'archived') !== 'archived' && $locked->status === 'archived'
                && $this->hasOpenPlan((int) $locked->student_class_id)) {
                $error = '這門課已經有另一份學習計畫';

                return;
            }
            $datesChanged = !$start->equalTo($locked->plan_start) || !$end->equalTo($locked->plan_end);
            if ($datesChanged) {
                $outside = $locked->units()->get()->filter(fn ($u) => $u->dates_manual
                    && ($u->planned_start->lt($start) || $u->planned_end->gt($end)))->count();
                if ($outside > 0) {
                    $error = "有 {$outside} 個手動排好日期的單元會落在新的計畫期間外，請先調整這些單元的日期";

                    return;
                }
            }
            $locked->update(array_filter([
                'title' => isset($data['title']) ? trim($data['title']) : null, 'status' => $data['status'] ?? null,
            ], fn ($v) => $v !== null) + ['plan_start' => $start->toDateString(), 'plan_end' => $end->toDateString()]);
            if ($datesChanged) {
                $this->applySchedule($locked);
            }
        });
        if ($error !== null) {
            return response()->json(['message' => $error], 422);
        }

        return $this->respond($plan->fresh());
    }

    public function updateUnit(Request $request, StudentLearningPlan $plan, StudentPlanUnit $unit): JsonResponse
    {
        if ($denied = $this->planDenied($request, $plan)) {
            return $denied;
        }
        if ((int) $unit->plan_id !== (int) $plan->id) {
            return response()->json(['message' => 'Not Found'], 404);
        }
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'min:1', 'max:120'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'planned_start' => ['sometimes', ...self::DATE],
            'planned_end' => ['sometimes', ...self::DATE],
            'completed' => ['sometimes', 'boolean'],
            'dates_manual' => ['sometimes', 'boolean'],
        ]);
        $hasDates = isset($data['planned_start']) || isset($data['planned_end']);
        if ($hasDates && array_key_exists('dates_manual', $data) && !$data['dates_manual']) {
            return response()->json(['message' => '不能同時改日期又要求自動排程'], 422);
        }

        $error = null;
        DB::transaction(function () use ($request, $plan, $unit, $data, $hasDates, &$error) {
            $locked = StudentLearningPlan::query()->whereKey($plan->id)->lockForUpdate()->first();
            $u = StudentPlanUnit::query()->whereKey($unit->id)->where('plan_id', $plan->id)->first();
            if ($locked === null || $u === null) {
                return;
            }
            if ($locked->status === 'archived') {
                $error = '已封存的計畫不能再修改單元';

                return;
            }
            $changes = [];
            if (isset($data['title'])) {
                $changes['title'] = trim($data['title']);
            }
            if (array_key_exists('note', $data)) {
                $note = trim((string) $data['note']);
                $changes['note'] = $note === '' ? null : $note;
            }
            if ($hasDates) {
                $from = $this->day((string) ($data['planned_start'] ?? $u->planned_start->toDateString()));
                $to = $this->day((string) ($data['planned_end'] ?? $u->planned_end->toDateString()));
                if ($from->gt($to) || $from->lt($locked->plan_start) || $to->gt($locked->plan_end)) {
                    $error = '單元日期要在計畫期間內，且開始日不能晚於結束日';

                    return;
                }
                $changes += ['planned_start' => $from->toDateString(), 'planned_end' => $to->toDateString(), 'dates_manual' => true];
            } elseif (array_key_exists('dates_manual', $data)) {
                $changes['dates_manual'] = (bool) $data['dates_manual'];
            }
            if (array_key_exists('completed', $data)) {
                if ($data['completed'] && $u->completed_at === null) {
                    $changes += ['completed_at' => now(), 'completed_by' => $this->actorId($request)];
                } elseif (!$data['completed']) {
                    $changes += ['completed_at' => null, 'completed_by' => null];
                }
            }
            $u->update($changes);
            if (!$hasDates && array_key_exists('dates_manual', $data) && !$data['dates_manual']) {
                $this->applySchedule($locked);
            }
        });
        if ($error !== null) {
            return response()->json(['message' => $error], 422);
        }

        return $this->respond($plan->fresh());
    }

    public function recalculate(Request $request, StudentLearningPlan $plan): JsonResponse
    {
        if ($denied = $this->planDenied($request, $plan)) {
            return $denied;
        }
        $archived = false;
        DB::transaction(function () use ($plan, &$archived) {
            $locked = StudentLearningPlan::query()->whereKey($plan->id)->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }
            if ($locked->status === 'archived') {
                $archived = true;

                return;
            }
            $this->applySchedule($locked);
        });
        if ($archived) {
            return response()->json(['message' => '已封存的計畫不能重新排程'], 422);
        }

        return $this->respond($plan->fresh());
    }

    /** Re-date every non-manual unit from the plan range and the exam periods inside it. */
    private function applySchedule(StudentLearningPlan $plan): void
    {
        $units = $plan->units()->get()->values();
        $student = Student::query()->find($plan->student_id);
        $dates = LearningPlanScheduler::schedule(
            $units->map(fn ($u) => ['exam_sequence' => $u->exam_sequence, 'dates_manual' => $u->dates_manual])->all(),
            $plan->plan_start->toDateString(), $plan->plan_end->toDateString(),
            $student ? $this->examRows($student, $plan->plan_start, $plan->plan_end) : [],
        );
        foreach ($units as $i => $u) {
            if (isset($dates[$i])) {
                $u->update(['planned_start' => $dates[$i]['start'], 'planned_end' => $dates[$i]['end']]);
            }
        }
    }

    private function respond(?StudentLearningPlan $plan): JsonResponse
    {
        abort_if($plan === null, 404);
        $student = Student::query()->find($plan->student_id);

        return response()->json(['data' => $this->present($plan->load('units'), $student)]);
    }

    /** Stored fields plus derived (never stored) percent_done, units_behind and next_exam. */
    private function present(StudentLearningPlan $plan, ?Student $student): array
    {
        $today = now()->startOfDay();
        $units = $plan->units;
        $total = $units->count();
        $done = $units->filter(fn ($u) => $u->completed_at !== null)->count();
        $next = collect($student ? $this->examRows($student, $plan->plan_start, $plan->plan_end) : [])
            ->first(fn ($e) => $this->day($e['start_date'])->gte($today));

        return $plan->toArray() + [
            'percent_done' => $total === 0 ? 0 : (int) round($done * 100 / $total),
            'units_behind' => $units->filter(fn ($u) => $u->completed_at === null && $u->planned_end->lt($today))->count(),
            'next_exam' => $next === null ? null : $next + ['days_left' => (int) $today->diffInDays($this->day($next['start_date']))],
        ];
    }

    /** @return list<array{sequence: int, label: string, start_date: string, academic_year: int, semester: int}> in date order */
    private function examRows(Student $student, Carbon $from, Carbon $to): array
    {
        return $this->exams->forRange($student, $from, $to)->map(fn ($p) => [
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

    private function planDenied(Request $request, StudentLearningPlan $plan): ?JsonResponse
    {
        if (!$this->campusAllowed($request, (int) $plan->campus_id) || !$this->courseWritable($request, (int) $plan->student_class_id)) {
            return $this->forbidden();
        }

        return null;
    }

    private function teacherId(Request $request): ?int
    {
        if ($request->attributes->get('auth_role') !== 'teacher') {
            return null;
        }

        return max(0, (int) $request->attributes->get('auth_teacher_id'));
    }

    /** Director/super_admin: any course in their campus. Teacher: only the courses they own. */
    private function courseWritable(Request $request, int $courseId): bool
    {
        $teacherId = $this->teacherId($request);

        return $teacherId === null || ($teacherId > 0
            && StudentClass::query()->where('ID', $courseId)->where('TeacherID', $teacherId)->exists());
    }

    /** @return list<int> courses a teacher may read (visible to them, incl. substitute/makeup). */
    private function visibleCourseIds(Request $request, Student $student): array
    {
        $teacherId = (int) $this->teacherId($request);

        return $teacherId <= 0 ? [] : StudentClass::query()->where('StudentID', $student->id)
            ->visibleToTeacher($teacherId)->pluck('ID')->map(fn ($id) => (int) $id)->all();
    }

    private function actorId(Request $request): ?int
    {
        $id = (int) ($request->attributes->get('auth_user')?->id ?? 0);

        return $id > 0 ? $id : null;
    }

    private function campusAllowed(Request $request, int $campusId): bool
    {
        $role = (string) $request->attributes->get('auth_role', '');

        return $campusId > 0 && ($role === 'super_admin'
            || in_array($campusId, array_map('intval', (array) $request->attributes->get('auth_campus_ids', [])), true));
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['message' => 'Forbidden'], 403);
    }
}
