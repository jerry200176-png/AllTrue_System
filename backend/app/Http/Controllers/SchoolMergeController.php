<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Services\SchoolMergeService;
use App\Services\SchoolNameSuggester;
use RuntimeException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 學校整理 (#517). Reads: campus staff (combobox); suggestions: director. Campus-scoped. */
class SchoolMergeController extends Controller
{
    public function __construct(private SchoolNameSuggester $suggester, private SchoolMergeService $merger)
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

    public function history(Request $request): JsonResponse
    {
        $campusId = (int) $request->input('campus_id');
        if (!$this->isDirector($request) || !$this->campusAllowed($request, $campusId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(['data' => $this->merger->history($campusId)]);
    }

    public function merge(Request $request): JsonResponse
    {
        $campusId = (int) $request->input('campus_id');
        if (!$this->isDirector($request) || !$this->campusAllowed($request, $campusId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $data = $request->validate([
            'canonical' => ['required', 'string', 'max:32'],
            'variants' => ['required', 'array', 'min:1', 'max:100'], // ponytail: one campus never has 100 spellings of a school; paginate if it does
            'variants.*' => ['required', 'string', 'max:32'],
        ]);
        $canonical = trim($data['canonical']);
        // Keep raw spellings (the service matches TRIM(SchoolName) and skips the exact canonical value).
        $variants = array_values(array_unique(array_filter(array_map('trim', $data['variants']), fn ($v) => $v !== '')));
        if ($canonical === '' || $variants === []) {
            return response()->json(['message' => '請選一個保留的校名和至少一個要合併的寫法'], 422);
        }

        return $this->run(fn () => $this->merger->merge($campusId, (int) $request->attributes->get('auth_user')->id, $canonical, $variants), 201);
    }

    public function undo(Request $request, string $requestId): JsonResponse
    {
        $campusId = (int) $request->input('campus_id');
        if (!$this->isDirector($request) || !$this->campusAllowed($request, $campusId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if (!\Illuminate\Support\Str::isUuid($requestId)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return $this->run(fn () => $this->merger->undo($campusId, (int) $request->attributes->get('auth_user')->id, $requestId));
    }

    private function run(\Closure $fn, int $ok = 200): JsonResponse
    {
        try {
            $result = $fn();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $result === null ? response()->json(['message' => 'Not found'], 404) : response()->json(['data' => $result], $ok);
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
