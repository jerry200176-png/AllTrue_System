<?php

namespace App\Http\Controllers;

use App\Models\UserCampus;
use App\Services\StaffCapabilityAuthorizer;
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
        $superAdmin = $request->attributes->get('auth_role') === 'super_admin';
        $callerCampusIds = $this->callerCampusIds($request);
        // Every target must be a teacher the caller manages, AND the branch must be one the caller manages:
        // granting a managed teacher a seat in an unmanaged campus is the campus director's decision, not this
        // caller's (independent review F2). Both checked for all rows before any write.
        foreach ($rows as $row) {
            $userId = (int) ($row['teacher_id'] ?? 0);
            $branchId = (int) ($row['branch_id'] ?? 0);
            if (!$userId || !$branchId) {
                continue;
            }
            if (!$this->managesTeacher($request, $userId)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            if (!$superAdmin && !in_array($branchId, $callerCampusIds, true)) {
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
        
        // One integer is checked and the same integer is deleted. teacher_id[]=<id> used to make (int) check User 1
        // while the delete matched <id> (security review, 2026-10-22).
        $raw = $request->query('teacher_id');
        if ($raw !== null && $raw !== '' && (!is_scalar($raw) || !ctype_digit((string) $raw))) {
            return response()->json(['message' => 'teacher_id must be one user id'], 422);
        }
        $teacherId = (int) $raw;
        if ($teacherId > 0) {
            if (!$this->managesTeacher($request, $teacherId)) {
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

    /**
     * super_admin, or the target is a teacher (type T) with an approved link to one of the caller's campuses
     * (StaffCapabilityAuthorizer::hasApprovedCampusLink, shared with ProfileController::canManageTeacher()).
     * A director-grant holder is a director even as type T (Founder policy 3): only super_admin manages it.
     */
    private function managesTeacher(Request $request, int $userId): bool
    {
        if ($request->attributes->get('auth_role') === 'super_admin') {
            return true;
        }
        $campusIds = $this->callerCampusIds($request);
        $authorizer = app(StaffCapabilityAuthorizer::class);
        if ($campusIds === [] || (string) DB::table('User')->where('id', $userId)->value('type') !== 'T'
            || $authorizer->holdsDirectorGrant($userId)) {
            return false;
        }

        // #4433: only an approved link counts; a pending request to the caller's campus grants no management.
        return $authorizer->hasApprovedCampusLink($userId, $campusIds);
    }
}
