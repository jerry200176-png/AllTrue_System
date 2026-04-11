<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SubjectController extends Controller
{
    private const CANONICAL_SUBJECTS = ['國文', '英文', '數學', '社會', '理化', '物理', '化學', '生物'];

    public static function normalizeSubjectName(string $name): string
    {
        $raw = trim($name);
        if ($raw === '') return '';
        $k = mb_strtolower(str_replace([' ', '_', '-'], '', $raw));
        return match ($k) {
            'chinese', '國文' => '國文',
            'english', '英文' => '英文',
            'math', 'mathematics', '數學' => '數學',
            'social', '社會' => '社會',
            'science', '理化' => '理化',
            'physics', '物理' => '物理',
            'chemistry', '化學' => '化學',
            'biology', '生物' => '生物',
            default => $raw,
        };
    }

    /**
     * Ensure the 8 canonical subjects exist as shared rows (CampusID = NULL).
     */
    private function bootstrapCanonical(): void
    {
        $rows = DB::table('Subject')->whereNull('CampusID')->get(['id', 'Subject_Name']);
        $existing = $rows->map(fn ($r) => self::normalizeSubjectName($r->Subject_Name))
            ->filter()->unique()->values()->all();
        $missing = array_values(array_diff(self::CANONICAL_SUBJECTS, $existing));
        if (!empty($missing)) {
            DB::table('Subject')->insert(array_map(fn ($n) => [
                'School_id' => 1, 'Grade_no' => 0, 'Subject_Name' => $n, 'CampusID' => null,
            ], $missing));
        }
    }

    /**
     * Public listing (no auth). Returns shared subjects only.
     */
    public function indexPublic()
    {
        if (!Schema::hasTable('Subject')) {
            return response()->json([]);
        }
        $this->bootstrapCanonical();
        return response()->json($this->buildList(null));
    }

    /**
     * Authenticated listing. Returns shared + campus-scoped subjects.
     */
    public function index(Request $request)
    {
        if (!Schema::hasTable('Subject')) {
            return response()->json([]);
        }
        $this->bootstrapCanonical();

        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : ($request->attributes->get('auth_campus_ids', []) ?: []);

        if ($request->filled('branch_id')) {
            $cid = (int) $request->input('branch_id');
            if ($role !== 'super_admin' && !empty($campusIds) && !in_array($cid, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            return response()->json($this->buildList([$cid]));
        }

        if ($role === 'super_admin') {
            return response()->json($this->buildList(null));
        }

        return response()->json($this->buildList($campusIds));
    }

    /**
     * Create a new subject. Director only.
     */
    public function store(Request $request)
    {
        $name = trim((string) ($request->input('name') ?? ''));
        if ($name === '') {
            return response()->json(['message' => '科目名稱不能為空'], 422);
        }
        if (mb_strlen($name) > 16) {
            return response()->json(['message' => '科目名稱最多 16 字'], 422);
        }

        $normalized = self::normalizeSubjectName($name);
        $display = $normalized !== '' ? $normalized : $name;

        $campusId = null;
        if ($request->filled('branch_id')) {
            $campusId = (int) $request->input('branch_id');
        }

        $query = DB::table('Subject')->whereRaw('LOWER(Subject_Name) = LOWER(?)', [$display]);
        if ($campusId !== null) {
            $query->where(function ($q) use ($campusId) {
                $q->whereNull('CampusID')->orWhere('CampusID', $campusId);
            });
        }
        if ($query->exists()) {
            return response()->json(['message' => '此科目已存在'], 409);
        }

        $id = DB::table('Subject')->insertGetId([
            'School_id'    => 0,
            'Grade_no'     => 0,
            'Subject_Name' => mb_substr($display, 0, 16),
            'CampusID'     => $campusId,
        ]);

        return response()->json([
            'id' => $id,
            'name' => $display,
            'campus_id' => $campusId,
        ], 201);
    }

    /**
     * Rename a subject. Director only.
     */
    public function update(Request $request, $id)
    {
        $subject = DB::table('Subject')->find((int) $id);
        if (!$subject) {
            return response()->json(['message' => '科目不存在'], 404);
        }

        $name = trim((string) ($request->input('name') ?? ''));
        if ($name === '') {
            return response()->json(['message' => '科目名稱不能為空'], 422);
        }
        if (mb_strlen($name) > 16) {
            return response()->json(['message' => '科目名稱最多 16 字'], 422);
        }

        $normalized = self::normalizeSubjectName($name);
        $display = $normalized !== '' ? $normalized : $name;

        $dup = DB::table('Subject')
            ->where('id', '!=', (int) $id)
            ->whereRaw('LOWER(Subject_Name) = LOWER(?)', [$display])
            ->where(function ($q) use ($subject) {
                $q->whereNull('CampusID');
                if ($subject->CampusID !== null) {
                    $q->orWhere('CampusID', $subject->CampusID);
                }
            })
            ->exists();

        if ($dup) {
            return response()->json(['message' => '此科目名稱已被使用'], 409);
        }

        DB::table('Subject')->where('id', (int) $id)->update([
            'Subject_Name' => mb_substr($display, 0, 16),
        ]);

        return response()->json([
            'id' => (int) $id,
            'name' => $display,
            'campus_id' => $subject->CampusID,
        ]);
    }

    /**
     * Delete a subject. Director only.
     * Shared subjects (CampusID IS NULL) can only be deleted by super_admin.
     */
    public function destroy(Request $request, $id)
    {
        $subject = DB::table('Subject')->find((int) $id);
        if (!$subject) {
            return response()->json(['message' => '科目不存在'], 404);
        }

        if ($subject->CampusID === null) {
            $role = $request->attributes->get('auth_role');
            if ($role !== 'super_admin') {
                return response()->json(['message' => '共用科目僅 super_admin 可刪除'], 403);
            }
        }

        $inUse = DB::table('StudentClass')->where('SubjectID', (int) $id)->exists();
        if ($inUse) {
            return response()->json(['message' => '此科目已有課程使用中，無法刪除'], 409);
        }

        DB::table('Subject')->where('id', (int) $id)->delete();
        return response()->json(null, 204);
    }

    /**
     * Build the subject list array for JSON response.
     * @param array|null $campusIds  null = all (super_admin or public shared-only)
     */
    private function buildList(?array $campusIds): array
    {
        $query = DB::table('Subject');
        if ($campusIds === null) {
            // super_admin or public: all rows
        } else {
            $query->where(function ($q) use ($campusIds) {
                $q->whereNull('CampusID');
                if (!empty($campusIds)) {
                    $q->orWhereIn('CampusID', $campusIds);
                }
            });
        }

        return $query->get(['id', 'Subject_Name', 'CampusID'])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => self::normalizeSubjectName($r->Subject_Name),
                'campus_id' => $r->CampusID !== null ? (int) $r->CampusID : null,
            ])
            ->filter(fn ($r) => $r['name'] !== '')
            ->sortBy('name')
            ->values()
            ->all();
    }
}
