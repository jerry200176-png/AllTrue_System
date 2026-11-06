<?php

namespace App\Http\Controllers;

use App\Models\SchoolExamPeriod;
use App\Models\Student;
use App\Services\SchoolNameGrouper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** 各校段考日期 (#4970 step 1). Reads: director+teacher; writes: director. Campus-scoped. */
class SchoolExamPeriodController extends Controller
{
    public function __construct(private SchoolNameGrouper $grouper)
    {
    }

    public function schools(Request $request): JsonResponse
    {
        $campusId = (int) $request->input('campus_id');
        if (!$this->campusAllowed($request, $campusId)) {
            return $this->forbidden();
        }
        $groups = [];
        $rows = Student::query()->where('CampusID', $campusId)->where('enable', 1)
            ->whereNotNull('SchoolName')->where('SchoolName', '!=', '')
            ->selectRaw('SchoolName as n, COUNT(*) as c')->groupBy('SchoolName')->get();
        foreach ($rows as $row) {
            $raw = trim((string) $row->n);
            $key = $this->grouper->key($raw);
            if ($key === '') {
                continue;
            }
            $g = &$groups[$key];
            $g['school_name'] = $g['school_name'] ?? $this->grouper->display($raw);
            $g['student_count'] = ($g['student_count'] ?? 0) + (int) $row->c;
            $g['variants'] = array_values(array_unique([...($g['variants'] ?? []), $raw]));
            unset($g);
        }
        $data = array_values($groups);
        usort($data, fn ($a, $b) => $b['student_count'] <=> $a['student_count']);

        return response()->json(['data' => $data]);
    }

    public function index(Request $request): JsonResponse
    {
        $campusId = (int) $request->input('campus_id');
        if (!$this->campusAllowed($request, $campusId)) {
            return $this->forbidden();
        }
        $q = SchoolExamPeriod::query()->where('campus_id', $campusId);
        foreach (['academic_year', 'semester'] as $f) {
            if ($request->filled($f)) {
                $q->where($f, (int) $request->input($f));
            }
        }

        return response()->json(['data' => $q->orderBy('school_name')->orderBy('seq')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->isDirector($request)) {
            return $this->forbidden();
        }
        $data = $this->validated($request, true);
        if (!$this->campusAllowed($request, (int) $data['campus_id'])) {
            return $this->forbidden();
        }
        $data = $this->normalizeRow($data) + ['created_by' => (int) ($request->attributes->get('auth_user')->id ?? 0)];
        if ($this->duplicate($data)) {
            return response()->json(['message' => '這個學校、學期、場次已設定'], 422);
        }

        return response()->json(['data' => SchoolExamPeriod::create($data)], 201);
    }

    public function update(Request $request, SchoolExamPeriod $period): JsonResponse
    {
        if (!$this->isDirector($request) || !$this->campusAllowed($request, (int) $period->campus_id)) {
            return $this->forbidden();
        }
        $data = $this->validated($request, false);
        $merged = $this->normalizeRow(array_merge($period->only([
            'campus_id', 'school_name', 'academic_year', 'semester', 'seq', 'label', 'start_date', 'end_date', 'grade_ids',
        ]), $data, ['start_date' => $data['start_date'] ?? $period->start_date->toDateString(),
            'end_date' => $data['end_date'] ?? $period->end_date->toDateString()]));
        if (strcmp($merged['start_date'], $merged['end_date']) > 0) {
            return response()->json(['message' => '結束日不能早於開始日'], 422);
        }
        if ($this->duplicate($merged, $period->id)) {
            return response()->json(['message' => '這個學校、學期、場次已設定'], 422);
        }
        $period->update($merged);

        return response()->json(['data' => $period->fresh()]);
    }

    public function destroy(Request $request, SchoolExamPeriod $period): JsonResponse
    {
        if (!$this->isDirector($request) || !$this->campusAllowed($request, (int) $period->campus_id)) {
            return $this->forbidden();
        }
        $period->delete();

        return response()->json(['message' => 'ok']);
    }

    /** Periods of the current academic year that apply to this student's school and grade. */
    public function forStudent(Request $request, Student $student): JsonResponse
    {
        if (!$this->campusAllowed($request, (int) $student->CampusID)) {
            return $this->forbidden();
        }
        [$year, $semester] = $this->currentTerm();
        $key = $this->grouper->key((string) $student->SchoolName);
        $rows = $key === '' ? collect() : SchoolExamPeriod::query()
            ->where('campus_id', $student->CampusID)->where('academic_year', $year)->where('semester', $semester)
            ->orderBy('seq')->get()
            ->filter(fn ($p) => $this->grouper->key($p->school_name) === $key
                && ($p->grade_ids === null || in_array((int) $student->ClassID, array_map('intval', $p->grade_ids), true)))
            ->values();

        return response()->json(['data' => $rows, 'academic_year' => $year, 'semester' => $semester]);
    }

    /** ROC year + semester: Aug–Jan = 1st, Feb–Jul = 2nd. */
    public function currentTerm(): array
    {
        $now = now();
        $m = (int) $now->format('n');
        $y = (int) $now->format('Y') - 1911;

        return $m >= 8 ? [$y, 1] : ($m === 1 ? [$y - 1, 1] : [$y - 1, 2]);
    }

    private function validated(Request $request, bool $full): array
    {
        $req = $full ? 'required' : 'sometimes';

        return $request->validate([
            'campus_id' => $full ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'school_name' => [$req, 'string', 'max:64'],
            'academic_year' => [$req, 'integer', 'between:100,200'],
            'semester' => [$req, Rule::in([1, 2])],
            'seq' => [$req, 'integer', 'between:1,3'],
            'label' => [$req, 'string', 'max:40'],
            'start_date' => [$req, 'date'],
            'end_date' => $full ? ['required', 'date', 'after_or_equal:start_date'] : ['sometimes', 'date'],
            'grade_ids' => ['nullable', 'array', 'max:12'],
            'grade_ids.*' => ['integer', 'min:1'],
        ]);
    }

    private function normalizeRow(array $d): array
    {
        $grades = isset($d['grade_ids']) && $d['grade_ids'] !== []
            ? array_values(array_unique(array_map('intval', $d['grade_ids']))) : null;
        if ($grades) {
            sort($grades);
        }
        $d['grade_ids'] = $grades;
        $d['grade_key'] = $grades ? implode(',', $grades) : 'all';
        $d['school_name'] = $this->grouper->display((string) $d['school_name']);
        foreach (['start_date', 'end_date'] as $f) {
            $d[$f] = substr((string) $d[$f], 0, 10);
        }

        return $d;
    }

    private function duplicate(array $d, ?int $exceptId = null): bool
    {
        return SchoolExamPeriod::query()->where($this->only($d, ['campus_id', 'school_name', 'academic_year', 'semester', 'seq', 'grade_key']))
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists();
    }

    private function only(array $d, array $keys): array
    {
        return array_intersect_key($d, array_flip($keys));
    }

    private function campusAllowed(Request $request, int $campusId): bool
    {
        $role = (string) $request->attributes->get('auth_role', '');

        return $campusId > 0 && ($role === 'super_admin'
            || in_array($campusId, array_map('intval', (array) $request->attributes->get('auth_campus_ids', [])), true));
    }

    private function isDirector(Request $request): bool
    {
        return in_array($request->attributes->get('auth_role'), ['director', 'super_admin'], true);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['message' => 'Forbidden'], 403);
    }
}
