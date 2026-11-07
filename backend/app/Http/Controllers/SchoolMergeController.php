<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Services\SchoolNameSuggester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 學校整理 (#517). Reads: campus staff (combobox); suggestions: director. Campus-scoped. */
class SchoolMergeController extends Controller
{
    public function __construct(private SchoolNameSuggester $suggester)
    {
    }

    /** Distinct school names of this campus with student counts. */
    public function names(Request $request): JsonResponse
    {
        if (!$this->campusAllowed($request, (int) $request->input('campus_id'))) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(['data' => $this->counts((int) $request->input('campus_id'))]);
    }

    public function suggestions(Request $request): JsonResponse
    {
        $campusId = (int) $request->input('campus_id');
        if (!$this->isDirector($request) || !$this->campusAllowed($request, $campusId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $counts = [];
        foreach ($this->counts($campusId) as $row) {
            $counts[$row['name']] = $row['count'];
        }

        return response()->json(['data' => $this->suggester->groups($counts)]);
    }

    /** @return list<array{name:string,count:int}> */
    private function counts(int $campusId): array
    {
        return Student::query()->where('CampusID', $campusId)->where('enable', 1)
            ->whereNotNull('SchoolName')->where('SchoolName', '!=', '')
            ->selectRaw('SchoolName as name, COUNT(*) as count')->groupBy('SchoolName')
            ->orderByDesc('count')->toBase()->get()
            ->map(fn ($r) => ['name' => (string) $r->name, 'count' => (int) $r->count])->all();
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
}
