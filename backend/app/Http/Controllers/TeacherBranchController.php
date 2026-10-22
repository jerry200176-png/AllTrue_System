<?php

namespace App\Http\Controllers;

use App\Models\UserCampus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TeacherBranchController extends Controller
{
    /**
     * GET /api/v1/teacher_branches
     */
    public function index(Request $request)
    {
        $query = UserCampus::query();
        if ($request->attributes->get('auth_role') !== 'super_admin') {
            $query->whereIn('CampusID', $this->callerCampusIds($request));
        }
        $branches = $query->get()->map(function ($uc) {
            return [
                'teacher_id' => $uc->UserID,
                'branch_id' => $uc->CampusID
            ];
        });

        return response()->json($branches);
    }

    /**
     * POST /api/v1/teacher_branches
     * Assign permissions (teacher cross-branch support)
     */
    public function store(Request $request)
    {
        $raw = $request->getContent();
        $data = $raw !== '' ? json_decode($raw, true) : null;
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return response()->json(['message' => 'Invalid JSON'], 400);
        }
        if (empty($data)) {
            return response()->json(['success' => true]);
        }

        $rows = isset($data[0]) && is_array($data[0]) ? $data : [$data];
        $hasApproved = Schema::hasColumn('UserCampus', 'Approved');
        // Every target must be a teacher who already shares one of the caller's campuses; checked before any write.
        foreach ($rows as $row) {
            $userId = (int) ($row['teacher_id'] ?? 0);
            if ($userId && (int) ($row['branch_id'] ?? 0) && !$this->managesTeacher($request, $userId)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        try {
            foreach ($rows as $row) {
                $userId = (int) ($row['teacher_id'] ?? 0);
                $campusId = (int) ($row['branch_id'] ?? 0);
                if (!$userId || !$campusId) {
                    continue;
                }
                $exists = DB::table('UserCampus')
                    ->where('UserID', $userId)
                    ->where('CampusID', $campusId)
                    ->exists();
                if ($exists) {
                    $update = ['Admin' => 0];
                    if ($hasApproved) {
                        $update['Approved'] = true;
                    }
                    DB::table('UserCampus')
                        ->where('UserID', $userId)
                        ->where('CampusID', $campusId)
                        ->update($update);
                } else {
                    $insert = [
                        'UserID' => $userId,
                        'CampusID' => $campusId,
                        'Admin' => 0,
                    ];
                    if ($hasApproved) {
                        $insert['Approved'] = true;
                    }
                    DB::table('UserCampus')->insert($insert);
                }
            }
            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Server error'], 500);
        }
    }
    
    /**
     * DELETE /api/v1/teacher_branches
     * Remove permissions
     */
    public function destroy(Request $request)
    {
        // Frontend: .delete().eq('teacher_id', editingId.value)
        // Query param: teacher_id
        
        $teacherId = $request->query('teacher_id');
        if ($teacherId) {
            if (!$this->managesTeacher($request, (int) $teacherId)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            UserCampus::where('UserID', $teacherId)->delete();
        }

        return response()->json(['success' => true]);
    }

    /** @return list<int> */
    private function callerCampusIds(Request $request): array
    {
        return array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));
    }

    /** super_admin, or the target is a teacher (type T) with a UserCampus row in one of the caller's campuses. */
    private function managesTeacher(Request $request, int $userId): bool
    {
        if ($request->attributes->get('auth_role') === 'super_admin') {
            return true;
        }
        $campusIds = $this->callerCampusIds($request);
        if ($campusIds === [] || (string) DB::table('User')->where('id', $userId)->value('type') !== 'T') {
            return false;
        }

        return DB::table('UserCampus')->where('UserID', $userId)->whereIn('CampusID', $campusIds)->exists();
    }
}
