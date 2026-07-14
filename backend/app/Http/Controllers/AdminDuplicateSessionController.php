<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminDuplicateSessionController extends Controller
{
    /**
     * GET /api/v1/admin/duplicate-sessions/p2-review
     * Return cross-SC duplicate groups for director review.
     */
    public function p2Review(Request $request)
    {
        $groups = $this->crossScDuplicateGroups();
        $p2Groups = [];

        foreach ($groups as $g) {
            $bySc = [];
            foreach ($g['rows'] as $row) {
                $bySc[(int) $row->StudentClassID][] = $row;
            }
            if (count($bySc) === 2) {
                $counts = array_map(fn ($rows) => (int) $rows[0]->SessionCount, array_values($bySc));
                sort($counts);
                if ($counts[0] === 0 && $counts[1] > 0) {
                    continue; // ghost pair → handled by p1-ghost
                }
            }

            $liveLr = $this->liveLearningRecordSessionIds(
                array_map(fn ($r) => (int) $r->id, $g['rows'])
            );

            $sides = [];
            foreach ($bySc as $scId => $rows) {
                $sessionIds = array_map(fn ($r) => (int) $r->id, $rows);
                $sides[] = [
                    'student_class_id' => $scId,
                    'session_count' => (int) $rows[0]->SessionCount,
                    'stop' => (int) $rows[0]->Stop,
                    'session_ids' => $sessionIds,
                    'has_learning_record' => count(array_intersect($sessionIds, $liveLr)) > 0,
                ];
            }

            $p2Groups[] = [
                'student_id' => $g['student_id'],
                'date' => $g['date'],
                'time' => $g['hm'],
                'sides' => $sides,
            ];
        }

        return response()->json([
            'data' => [
                'groups' => $p2Groups,
                'total' => count($p2Groups),
            ],
        ]);
    }

    /**
     * POST /api/v1/admin/duplicate-sessions/decide
     * Record director's decision on which contract to keep.
     */
    public function decide(Request $request)
    {
        $request->validate([
            'student_id' => 'required|integer',
            'date' => 'required|date',
            'time' => 'required|string',
            'keep_student_class_id' => 'required|integer',
            'reason' => 'nullable|string|max:500',
        ]);

        // Store decision in a decisions log
        $decision = [
            'student_id' => $request->input('student_id'),
            'date' => $request->input('date'),
            'time' => $request->input('time'),
            'keep_student_class_id' => $request->input('keep_student_class_id'),
            'reason' => $request->input('reason', ''),
            'decided_by' => $request->attributes->get('auth_user')?->id,
            'decided_at' => now()->toIso8601String(),
        ];

        $path = storage_path('logs/duplicate-session-decisions.jsonl');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode($decision, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

        Log::info('duplicate_session_decision', $decision);

        return response()->json([
            'data' => ['ok' => true, 'decision' => $decision],
        ]);
    }

    /**
     * POST /api/v1/admin/duplicate-sessions/execute
     * Execute the repair: cancel non-kept-side sessions for the decided group.
     */
    public function execute(Request $request)
    {
        $request->validate([
            'student_id' => 'required|integer',
            'date' => 'required|date',
            'time' => 'required|string',
            'keep_student_class_id' => 'required|integer',
        ]);

        $studentId = (int) $request->input('student_id');
        $date = $request->input('date');
        $time = $request->input('time');
        $keepScId = (int) $request->input('keep_student_class_id');

        // Find the duplicate group
        $rows = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->where('sc.StudentID', $studentId)
            ->where('cs.SessionDate', $date)
            ->whereRaw('SUBSTRING(cs.StartTime,1,5) = ?', [$time])
            ->whereRaw("LOWER(cs.Status) IN ('attended','completed')")
            ->select('cs.id', 'cs.StudentClassID', 'cs.Status')
            ->get();

        if ($rows->isEmpty()) {
            return response()->json(['message' => '找不到對應的重複群組'], 404);
        }

        $cancelledCount = 0;
        foreach ($rows as $row) {
            if ((int) $row->StudentClassID === $keepScId) {
                continue; // skip the kept side
            }
            DB::table('ClassSession')->where('id', $row->id)->update([
                'Status' => 'cancelled',
                'Note' => trim(($row->Note ?? '') . ' 資料修復 #1130 — 主任審核決策保留 SC' . $keepScId),
                'updated_at' => now(),
            ]);
            $cancelledCount++;
        }

        Log::info('duplicate_session_executed', [
            'student_id' => $studentId,
            'date' => $date,
            'time' => $time,
            'keep_sc_id' => $keepScId,
            'cancelled_count' => $cancelledCount,
            'executed_by' => $request->attributes->get('auth_user')?->id,
        ]);

        return response()->json([
            'data' => [
                'cancelled_count' => $cancelledCount,
                'kept_student_class_id' => $keepScId,
            ],
        ]);
    }

    // ── Helpers (mirror RepairDuplicateSessionSlots logic) ──

    private function crossScDuplicateGroups(): array
    {
        $rows = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->whereRaw("LOWER(cs.Status) IN ('attended','completed')")
            ->selectRaw('cs.id, cs.StudentClassID, cs.SessionDate, SUBSTRING(cs.StartTime,1,5) as hm, sc.StudentID, sc.SessionCount, sc.Stop')
            ->orderBy('sc.StudentID')->orderBy('cs.SessionDate')->orderBy('cs.id')
            ->get();

        $groups = [];
        foreach ($rows as $row) {
            $key = $row->StudentID . '|' . $row->SessionDate . '|' . $row->hm;
            $groups[$key]['student_id'] = (int) $row->StudentID;
            $groups[$key]['date'] = (string) $row->SessionDate;
            $groups[$key]['hm'] = (string) $row->hm;
            $groups[$key]['rows'][] = $row;
        }

        return array_values(array_filter($groups, function ($g) {
            if (count($g['rows']) < 2) {
                return false;
            }
            $scIds = array_unique(array_map(fn ($r) => (int) $r->StudentClassID, $g['rows']));
            return count($scIds) > 1;
        }));
    }

    private function liveLearningRecordSessionIds(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }
        return DB::table('LearningRecord')
            ->whereIn('ClassSessionID', $sessionIds)
            ->whereNull('VoidedAt')
            ->pluck('ClassSessionID')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
