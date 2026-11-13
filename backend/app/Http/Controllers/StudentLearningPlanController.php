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
 * 學生學習計畫 API (#4970 step 4a). Reads and writes: director, super_admin, and a teacher for courses visible to
 * them (StudentClass::visibleToTeacher, same rule as the student-classes list). Campus-scoped; archive, never delete.
 * Units are copied from a template; dates come from LearningPlanScheduler and the student's school exam periods.
 */
class StudentLearningPlanController extends Controller
{
    private const DEFAULT_WEEKS = 18;

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
        $examRows = $this->examRows($student);

        return response()->json(['data' => $q->with('units')->orderByDesc('id')->get()
            ->map(fn ($p) => $this->present($p, $examRows))->all()]);
    }

    public function store(Request $request, Student $student): JsonResponse
    {
        if (!$this->campusAllowed($request, (int) $student->CampusID)) {
            return $this->forbidden();
        }
        $data = $request->validate([
            'student_class_id' => ['required', 'integer', 'min:1'],
            'template_id' => ['required', 'integer', 'min:1'],
            'plan_start' => ['required', 'date'],
            'plan_end' => ['nullable', 'date', 'after_or_equal:plan_start'],
            'title' => ['nullable', 'string', 'max:120'],
        ]);
        $course = StudentClass::query()->where('ID', $data['student_class_id'])
            ->where('StudentID', $student->id)->where('by1', $student->CampusID)->first();
        if ($course === null) {
            return response()->json(['message' => '找不到這位學生的這門課'], 422);
        }
        if (!$this->courseAllowed($request, (int) $course->ID)) {
            return $this->forbidden();
        }
        $template = CurriculumTemplate::query()->whereKey($data['template_id'])
            ->where('campus_id', $student->CampusID)->where('status', 'active')->first();
        if ($template === null) {
            return response()->json(['message' => '找不到可用的教材範本（需為使用中）'], 422);
        }
        $start = substr((string) $data['plan_start'], 0, 10);
        $end = isset($data['plan_end']) ? substr((string) $data['plan_end'], 0, 10)
            : Carbon::parse($start)->addWeeks(self::DEFAULT_WEEKS)->toDateString();
        $title = trim((string) ($data['title'] ?? '')) ?: $template->title;

        $plan = null;
        DB::transaction(function () use ($request, $student, $course, $template, $start, $end, $title, &$plan) {
            // Lock the course row so two requests for the same course cannot both pass the duplicate check.
            StudentClass::query()->where('ID', $course->ID)->lockForUpdate()->first();
            if ($this->hasOpenPlan((int) $course->ID)) {
                return;
            }
            $plan = StudentLearningPlan::create([
                'campus_id' => (int) $student->CampusID, 'student_id' => $student->id, 'student_class_id' => (int) $course->ID,
                'source_template_id' => $template->id, 'title' => $title, 'status' => 'active',
                'plan_start' => $start, 'plan_end' => $end, 'created_by' => $this->actorId($request),
            ]);
            $units = $template->units()->get();
            $dates = LearningPlanScheduler::schedule($units->map(fn ($u) => ['exam_sequence' => $u->exam_sequence])->all(),
                $start, $end, $this->examRows($student));
            foreach ($units->values() as $i => $u) {
                StudentPlanUnit::create([
                    'plan_id' => $plan->id, 'sort_order' => $i + 1, 'title' => $u->title, 'note' => $u->note,
                    'exam_sequence' => $u->exam_sequence,
                    'planned_start' => $dates[$i]['start'] ?? $start, 'planned_end' => $dates[$i]['end'] ?? $end,
                ]);
            }
        });
        if ($plan === null) {
            return response()->json(['message' => '這門課已經有學習計畫'], 422);
        }

        return response()->json(['data' => $this->present($plan->load('units'), $this->examRows($student))], 201);
    }

    public function update(Request $request, StudentLearningPlan $plan): JsonResponse
    {
        if ($denied = $this->planDenied($request, $plan)) {
            return $denied;
        }
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'min:1', 'max:120'],
            'status' => ['sometimes', Rule::in(StudentLearningPlan::STATUSES)],
            'plan_start' => ['sometimes', 'date'],
            'plan_end' => ['sometimes', 'date'],
        ]);

        $error = null;
        DB::transaction(function () use ($plan, $data, &$error) {
            $locked = StudentLearningPlan::query()->whereKey($plan->id)->lockForUpdate()->first();
            $start = substr((string) ($data['plan_start'] ?? $locked->plan_start->toDateString()), 0, 10);
            $end = substr((string) ($data['plan_end'] ?? $locked->plan_end->toDateString()), 0, 10);
            if (strcmp($start, $end) > 0) {
                $error = '計畫結束日不能早於開始日';

                return;
            }
            if (($data['status'] ?? 'archived') !== 'archived' && $locked->status === 'archived'
                && $this->hasOpenPlan((int) $locked->student_class_id)) {
                $error = '這門課已經有另一份學習計畫';

                return;
            }
            $datesChanged = $start !== $locked->plan_start->toDateString() || $end !== $locked->plan_end->toDateString();
            $locked->update(array_filter(['title' => isset($data['title']) ? trim($data['title']) : null,
                'status' => $data['status'] ?? null], fn ($v) => $v !== null) + ['plan_start' => $start, 'plan_end' => $end]);
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
            'planned_start' => ['sometimes', 'date'],
            'planned_end' => ['sometimes', 'date'],
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
            if ($locked->status === 'archived') {
                $error = '已封存的計畫不能再修改單元';

                return;
            }
            $u = StudentPlanUnit::query()->whereKey($unit->id)->first();
            $changes = [];
            if (isset($data['title'])) {
                $changes['title'] = trim($data['title']);
            }
            if (array_key_exists('note', $data)) {
                $note = trim((string) $data['note']);
                $changes['note'] = $note === '' ? null : $note;
            }
            if ($hasDates) {
                $from = substr((string) ($data['planned_start'] ?? $u->planned_start->toDateString()), 0, 10);
                $to = substr((string) ($data['planned_end'] ?? $u->planned_end->toDateString()), 0, 10);
                if (strcmp($from, $to) > 0 || strcmp($from, $locked->plan_start->toDateString()) < 0
                    || strcmp($to, $locked->plan_end->toDateString()) > 0) {
                    $error = '單元日期要在計畫期間內，且開始日不能晚於結束日';

                    return;
                }
                $changes += ['planned_start' => $from, 'planned_end' => $to, 'dates_manual' => true];
            } elseif (array_key_exists('dates_manual', $data)) {
                $changes['dates_manual'] = (bool) $data['dates_manual'];
            }
            if (array_key_exists('completed', $data)) {
                $done = (bool) $data['completed'];
                if ($done && $u->completed_at === null) {
                    $changes += ['completed_at' => now(), 'completed_by' => $this->actorId($request)];
                } elseif (!$done) {
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

    /** Re-date every non-manual unit from the plan range and the student's school exam periods. */
    private function applySchedule(StudentLearningPlan $plan): void
    {
        $units = $plan->units()->get()->values();
        $student = Student::query()->find($plan->student_id);
        $dates = LearningPlanScheduler::schedule(
            $units->map(fn ($u) => ['exam_sequence' => $u->exam_sequence, 'dates_manual' => $u->dates_manual])->all(),
            $plan->plan_start->toDateString(), $plan->plan_end->toDateString(),
            $student ? $this->examRows($student) : [],
        );
        foreach ($units as $i => $u) {
            if (isset($dates[$i])) {
                $u->update(['planned_start' => $dates[$i]['start'], 'planned_end' => $dates[$i]['end']]);
            }
        }
    }

    private function respond(StudentLearningPlan $plan): JsonResponse
    {
        $student = Student::query()->find($plan->student_id);

        return response()->json(['data' => $this->present($plan->load('units'), $student ? $this->examRows($student) : [])]);
    }

    /** Stored fields plus derived (never stored) percent_done, units_behind and next_exam. */
    private function present(StudentLearningPlan $plan, array $examRows): array
    {
        $today = now()->toDateString();
        $units = $plan->units;
        $total = $units->count();
        $done = $units->filter(fn ($u) => $u->completed_at !== null)->count();
        $next = collect($examRows)->first(fn ($e) => strcmp($e['start_date'], $today) >= 0);

        return $plan->toArray() + [
            'percent_done' => $total === 0 ? 0 : (int) round($done * 100 / $total),
            'units_behind' => $units->filter(fn ($u) => $u->completed_at === null && $u->planned_end->toDateString() < $today)->count(),
            'next_exam' => $next === null ? null : $next + [
                'days_left' => intdiv((int) strtotime($next['start_date'] . ' UTC') - (int) strtotime($today . ' UTC'), 86400),
            ],
        ];
    }

    /** @return list<array{sequence: int, label: string, start_date: string}> sorted by start date */
    private function examRows(Student $student): array
    {
        return $this->exams->forStudent($student)['rows']->map(fn ($p) => [
            'sequence' => (int) $p->seq, 'label' => (string) $p->label, 'start_date' => $p->start_date->toDateString(),
        ])->sortBy('start_date')->values()->all();
    }

    private function hasOpenPlan(int $courseId): bool
    {
        return StudentLearningPlan::query()->where('student_class_id', $courseId)->where('status', '!=', 'archived')->exists();
    }

    private function planDenied(Request $request, StudentLearningPlan $plan): ?JsonResponse
    {
        if (!$this->campusAllowed($request, (int) $plan->campus_id) || !$this->courseAllowed($request, (int) $plan->student_class_id)) {
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

    /** Director/super_admin: any course in their campus. Teacher: only courses visible to them. */
    private function courseAllowed(Request $request, int $courseId): bool
    {
        $teacherId = $this->teacherId($request);

        return $teacherId === null || ($teacherId > 0
            && StudentClass::query()->where('ID', $courseId)->visibleToTeacher($teacherId)->exists());
    }

    /** @return list<int> */
    private function visibleCourseIds(Request $request, Student $student): array
    {
        $teacherId = (int) $this->teacherId($request);

        return $teacherId <= 0 ? [] : StudentClass::query()->where('StudentID', $student->id)
            ->visibleToTeacher($teacherId)->pluck('ID')->map(fn ($id) => (int) $id)->all();
    }

    private function actorId(Request $request): int
    {
        return (int) ($request->attributes->get('auth_user')->id ?? 0);
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
