<?php

namespace App\Http\Controllers;

use App\Models\CurriculumTemplate;
use App\Models\CurriculumTemplateUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** 教材範本庫 (#4970 step 2). Reads: director+teacher; writes: director. Campus-scoped; archive, never delete. */
class CurriculumTemplateController extends Controller
{
    private const GRADES = ['P1', 'P2', 'P3', 'P4', 'P5', 'P6', 'J1', 'J2', 'J3', 'H1', 'H2', 'H3'];

    public function index(Request $request): JsonResponse
    {
        $campusId = (int) $request->input('campus_id');
        if (!$this->campusAllowed($request, $campusId)) {
            return $this->forbidden();
        }
        $q = CurriculumTemplate::query()->where('campus_id', $campusId)->withCount('units');
        // Archived templates only show when asked for explicitly.
        if ($request->filled('status')) {
            $request->validate(['status' => [Rule::in(CurriculumTemplate::STATUSES)]]);
            $q->where('status', (string) $request->input('status'));
        } else {
            $q->whereIn('status', ['draft', 'active']);
        }
        foreach (['subject', 'grade'] as $f) {
            if ($request->filled($f)) {
                $q->where($f, (string) $request->input($f));
            }
        }

        return response()->json(['data' => $q->orderBy('subject')->orderBy('grade')->orderByDesc('id')->get()]);
    }

    public function show(Request $request, CurriculumTemplate $template): JsonResponse
    {
        if (!$this->campusAllowed($request, (int) $template->campus_id)) {
            return $this->forbidden();
        }

        return response()->json(['data' => $template->load('units')]);
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
        $data['created_by'] = (int) ($request->attributes->get('auth_user')->id ?? 0);

        return response()->json(['data' => CurriculumTemplate::create($data)], 201);
    }

    public function update(Request $request, CurriculumTemplate $template): JsonResponse
    {
        if (!$this->isDirector($request) || !$this->campusAllowed($request, (int) $template->campus_id)) {
            return $this->forbidden();
        }
        $template->update($this->validated($request, false));

        return response()->json(['data' => $template->fresh()]);
    }

    /** Replace every unit in one call; the array order is the sort order. All or nothing. */
    public function replaceUnits(Request $request, CurriculumTemplate $template): JsonResponse
    {
        if (!$this->isDirector($request) || !$this->campusAllowed($request, (int) $template->campus_id)) {
            return $this->forbidden();
        }
        $units = $request->validate([
            'units' => ['present', 'array', 'max:80'],
            'units.*.title' => ['required', 'string', 'max:120'],
            'units.*.note' => ['nullable', 'string', 'max:255'],
            'units.*.exam_sequence' => ['nullable', 'integer', 'between:1,3'],
        ])['units'];
        foreach ($units as $u) {
            if (trim((string) $u['title']) === '') {
                return response()->json(['message' => '單元名稱不能空白'], 422);
            }
        }

        $blocked = false;
        DB::transaction(function () use ($template, $units, &$blocked) {
            // Lock the template row so concurrent saves serialize and archive state is read fresh.
            $locked = CurriculumTemplate::query()->whereKey($template->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status === 'archived') {
                $blocked = true;

                return;
            }
            CurriculumTemplateUnit::query()->where('template_id', $template->id)->delete();
            foreach (array_values($units) as $i => $u) {
                $note = trim((string) ($u['note'] ?? ''));
                CurriculumTemplateUnit::create([
                    'template_id' => $template->id,
                    'sort_order' => $i + 1,
                    'title' => trim((string) $u['title']),
                    'note' => $note === '' ? null : $note,
                    'exam_sequence' => $u['exam_sequence'] ?? null,
                ]);
            }
            $template->touch();
        });

        if ($blocked) {
            return response()->json(['message' => '已封存的範本不能再改單元，請先改回草稿或使用中'], 422);
        }

        return response()->json(['data' => $template->fresh()->load('units')]);
    }

    public function archive(Request $request, CurriculumTemplate $template): JsonResponse
    {
        if (!$this->isDirector($request) || !$this->campusAllowed($request, (int) $template->campus_id)) {
            return $this->forbidden();
        }
        $template->update(['status' => 'archived']);

        return response()->json(['data' => $template->fresh()]);
    }

    private function validated(Request $request, bool $full): array
    {
        $req = $full ? 'required' : 'sometimes';

        return $request->validate([
            'campus_id' => $full ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'subject' => [$req, 'string', 'max:40'],
            'grade' => [$req, Rule::in(self::GRADES)],
            'publisher' => ['nullable', 'string', 'max:40'],
            'academic_year' => ['nullable', 'integer', 'between:100,200'],
            'semester' => ['nullable', Rule::in([1, 2])],
            'title' => [$req, 'string', 'min:1', 'max:120'],
            // A new template starts as draft/active; archiving goes through archive() (update may restore).
            'status' => ['sometimes', Rule::in($full ? ['draft', 'active'] : CurriculumTemplate::STATUSES)],
        ]);
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
