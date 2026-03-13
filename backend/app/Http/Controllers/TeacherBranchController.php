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
    public function index()
    {
        $branches = UserCampus::all()->map(function ($uc) {
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
        $data = $raw !== '' && $raw !== null ? json_decode($raw, true) : null;
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return response()->json(['message' => 'Invalid JSON'], 400);
        }
        if (empty($data)) {
            return response()->json(['success' => true]);
        }

        $rows = isset($data[0]) && is_array($data[0]) ? $data : [$data];
        $hasApproved = Schema::hasColumn('UserCampus', 'Approved');

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
            return response()->json([
                'message' => 'Server error',
                'error' => $e->getMessage(),
            ], 500);
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
            UserCampus::where('UserID', $teacherId)->delete();
        }

        return response()->json(['success' => true]);
    }
}
