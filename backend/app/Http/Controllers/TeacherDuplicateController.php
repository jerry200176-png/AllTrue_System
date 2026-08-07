<?php

namespace App\Http\Controllers;

use App\Services\TeacherUserMergeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * R99 (in-app #219/#223) root-cause prevention: two teacher `User` rows sharing the
 * same display name is exactly what let a trial course silently disappear from the
 * calendar (SmartCalendar merges same-name accounts into one visible column, but the
 * course stayed tagged to the "losing" duplicate account's TeacherID). This surfaces
 * existing duplicates for cleanup and wraps the already-tested TeacherUserMergeService
 * over HTTP (super_admin only) so a merge can be run without direct Pi/SSH access —
 * all writes still go through the same reviewed, tested service used by the
 * `teachers:merge-users` artisan command.
 */
class TeacherDuplicateController extends Controller
{
    public function index(Request $request)
    {
        $rows = DB::table('User')
            ->select('id', 'Name', 'LoginName', 'status')
            ->where('type', 'T')
            ->get()
            ->groupBy(fn ($row) => trim((string) $row->Name));

        $duplicates = [];
        foreach ($rows as $name => $accounts) {
            if ($name === '' || $accounts->count() < 2) {
                continue;
            }
            $duplicates[] = [
                'name' => $name,
                'accounts' => $accounts->map(function ($row) {
                    return [
                        'id' => (int) $row->id,
                        'login_name' => $row->LoginName,
                        'status' => $row->status,
                        'session_count' => (int) DB::table('StudentClass')->where('TeacherID', $row->id)->count()
                            + (int) DB::table('ClassSession')
                                ->join('StudentClass', 'StudentClass.ID', '=', 'ClassSession.StudentClassID')
                                ->where('StudentClass.TeacherID', $row->id)
                                ->count(),
                    ];
                })->values(),
            ];
        }

        return response()->json(['data' => $duplicates]);
    }

    public function preview(Request $request, TeacherUserMergeService $mergeService)
    {
        $data = $request->validate([
            'keep_id' => 'required|integer',
            'merge_id' => 'required|integer',
        ]);

        try {
            $mergeService->assertMergeable((int) $data['keep_id'], (int) $data['merge_id']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'preview' => $mergeService->preview((int) $data['keep_id'], (int) $data['merge_id']),
        ]);
    }

    public function merge(Request $request, TeacherUserMergeService $mergeService)
    {
        $data = $request->validate([
            'keep_id' => 'required|integer',
            'merge_id' => 'required|integer',
            'confirm' => 'required|accepted',
        ]);

        try {
            $mergeService->assertMergeable((int) $data['keep_id'], (int) $data['merge_id']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $preview = $mergeService->preview((int) $data['keep_id'], (int) $data['merge_id']);
        $mergeService->merge((int) $data['keep_id'], (int) $data['merge_id']);

        return response()->json([
            'message' => '已合併帳號',
            'keep_id' => (int) $data['keep_id'],
            'merged_away_id' => (int) $data['merge_id'],
            'moved' => $preview,
        ]);
    }
}
