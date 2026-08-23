<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\StudentSignIn;
use App\Services\SessionDeductionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminDuplicateSessionController extends Controller
{
    private function allowedCampusIds(Request $request): array
    {
        return $request->attributes->get('auth_role') === 'super_admin'
            ? []
            : array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));
    }

    /**
     * allowedCampusIds() plus the optional ?campus_id= filter (in-app #216: the
     * dropdown sent this param but no caller ever read it, so picking a campus
     * silently did nothing for super_admin, who has no other restriction).
     * Non-super_admin can only narrow within their own allowed campuses, never escape it.
     */
    private function effectiveCampusIds(Request $request): array
    {
        $allowed = $this->allowedCampusIds($request);
        $requested = (int) $request->query('campus_id', '0');
        if ($requested <= 0) {
            return $allowed;
        }
        if ($request->attributes->get('auth_role') === 'super_admin') {
            return [$requested];
        }

        return in_array($requested, $allowed, true) ? [$requested] : $allowed;
    }

    private function encodeGroupId(int $studentId, string $date, string $time): string
    {
        return rtrim(strtr(base64_encode("{$studentId}:{$date}:{$time}"), '+/', '-_'), '=');
    }

    /**
     * @return array{int,string,string}
     */
    private function decodeGroupId(string $groupId): array
    {
        $decoded = base64_decode(strtr($groupId, '-_', '+/'));
        $parts = explode(':', $decoded, 3);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException('Invalid groupId format');
        }
        return [(int) $parts[0], $parts[1], $parts[2]];
    }

    /**
     * GET /api/v1/admin/duplicate-sessions/p2-review
     * Return cross-SC duplicate groups for director review.
     */
    public function p2Review(Request $request)
    {
        $groups = $this->crossScDuplicateGroups($this->effectiveCampusIds($request));
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
                    'teacher_name' => (string) ($rows[0]->teacher_name ?? ''),
                    'subject_name' => (string) ($rows[0]->subject_name ?? ''),
                    'session_count' => (int) $rows[0]->SessionCount,
                    'remaining_sessions' => $rows[0]->RemainingSessions !== null ? (int) $rows[0]->RemainingSessions : null,
                    'schedule_mode' => (string) ($rows[0]->ScheduleMode ?? ''),
                    'start_date' => (string) ($rows[0]->StartDate ?? ''),
                    'stop' => (int) $rows[0]->Stop,
                    'session_ids' => $sessionIds,
                    'has_live_lr' => count(array_intersect($sessionIds, $liveLr)) > 0,
                    'statuses' => array_values(array_unique(array_map(
                        fn ($r) => strtolower((string) ($r->session_status ?? '')),
                        $rows
                    ))),
                ];
            }

            $p2Groups[] = [
                'id' => $this->encodeGroupId($g['student_id'], $g['date'], $g['hm']),
                'student_id' => $g['student_id'],
                'student_name' => (string) ($g['student_name'] ?? ''),
                'session_date' => $g['date'],
                'start_time' => $g['hm'],
                'sides' => $sides,
                'resolved_keeper_sc_id' => null,
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
     * PATCH /api/v1/admin/duplicate-sessions/p2-review/{groupId}
     * Combined decide + execute in one step.
     */
    public function patchP2Review(Request $request, string $groupId)
    {
        $request->validate([
            'keep_student_class_id' => 'required|integer',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            [$studentId, $date, $time] = $this->decodeGroupId($groupId);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => '無效的群組識別碼'], 400);
        }

        $keepScId = (int) $request->input('keep_student_class_id');
        $campusIds = $this->allowedCampusIds($request);

        $rows = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->where('sc.StudentID', $studentId)
            ->where('cs.SessionDate', $date)
            ->whereRaw('SUBSTRING(cs.StartTime,1,5) = ?', [$time])
            ->whereRaw("LOWER(cs.Status) IN ('attended','completed')")
            ->when(!empty($campusIds), fn ($q) => $q->whereIn('s.CampusID', $campusIds))
            ->select('cs.id', 'cs.StudentClassID', 'cs.Status', 'cs.Note')
            ->get();

        if ($rows->isEmpty()) {
            return response()->json(['message' => '找不到對應的重複群組'], 404);
        }
        if (!$rows->contains(fn ($row) => (int) $row->StudentClassID === $keepScId)) {
            return response()->json(['message' => 'keep_student_class_id 不在此重複群組內'], 422);
        }

        $cancelledCount = 0;
        $keptSessionIds = [];
        $reversedCount = 0;
        $voidedLearningRecordCount = 0;
        $voidedSignInCount = 0;
        $affectedStudentClassIds = [];
        $actorId = $request->attributes->get('auth_user')?->id;
        $reason = trim((string) $request->input('reason', '')) ?: '跨合約重複課程審核';

        DB::transaction(function () use (
            $rows,
            $keepScId,
            $actorId,
            $reason,
            &$cancelledCount,
            &$keptSessionIds,
            &$reversedCount,
            &$voidedLearningRecordCount,
            &$voidedSignInCount,
            &$affectedStudentClassIds
        ) {
            foreach ($rows as $row) {
                if ((int) $row->StudentClassID === $keepScId) {
                    $keptSessionIds[] = (int) $row->id;
                    continue;
                }

                $session = ClassSession::query()->whereKey((int) $row->id)->lockForUpdate()->first();
                if (!$session || strtolower((string) $session->Status) === 'cancelled') {
                    continue;
                }

                $studentClassId = (int) $session->StudentClassID;
                $affectedStudentClassIds[$studentClassId] = true;
                $voidReason = '跨合約重複課程：' . $reason;

                // Cancelling a duplicate must undo every usage artifact that
                // belongs to the dropped contract. A raw ClassSession update
                // leaves the attendance ledger at +1, which is exactly why a
                // later unpaid-contract correction still sees the old count.
                StudentSignIn::query()
                    ->where('ClassSessionID', (int) $session->id)
                    ->active()
                    ->get()
                    ->each(function (StudentSignIn $signIn) use ($actorId, $voidReason, &$voidedSignInCount): void {
                        $signIn->VoidedAt = now();
                        $signIn->VoidedByUserID = $actorId ?: null;
                        $signIn->VoidReason = $voidReason;
                        $signIn->save();
                        $voidedSignInCount++;
                    });

                LearningRecord::query()
                    ->where('ClassSessionID', (int) $session->id)
                    ->active()
                    ->get()
                    ->each(function (LearningRecord $record) use ($actorId, $voidReason, &$voidedLearningRecordCount): void {
                        $record->VoidedAt = now();
                        $record->VoidedByUserID = $actorId ?: null;
                        $record->VoidReason = $voidReason;
                        $record->save();
                        $voidedLearningRecordCount++;
                    });

                $session->Status = 'cancelled';
                $session->Note = trim(((string) $session->Note) . ' 資料修復 #1130 — 主任審核決策保留 SC' . $keepScId);
                $session->save();

                if (SessionDeductionService::reverseForSession(
                    $studentClassId,
                    (int) $session->id,
                    'duplicate_session',
                    $actorId ? (int) $actorId : null,
                    $voidReason
                )) {
                    $reversedCount++;
                }
                $cancelledCount++;
            }

            foreach (array_keys($affectedStudentClassIds) as $studentClassId) {
                SessionDeductionService::recomputeCounters((int) $studentClassId);
            }
        });

        $decision = [
            'student_id' => $studentId,
            'date' => $date,
            'time' => $time,
            'keep_student_class_id' => $keepScId,
            'reason' => $reason,
            'decided_by' => $actorId,
            'decided_at' => now()->toIso8601String(),
        ];
        $path = storage_path('logs/duplicate-session-decisions.jsonl');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode($decision, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        Log::info('duplicate_session_decision', $decision);

        Log::info('duplicate_session_executed', [
            'student_id' => $studentId,
            'date' => $date,
            'time' => $time,
            'keep_sc_id' => $keepScId,
            'cancelled_count' => $cancelledCount,
            'reversed_count' => $reversedCount,
            'voided_learning_record_count' => $voidedLearningRecordCount,
            'voided_sign_in_count' => $voidedSignInCount,
            'executed_by' => $actorId,
        ]);

        return response()->json([
            'data' => [
                'cancelled_count' => $cancelledCount,
                'kept_session_ids' => $keptSessionIds,
                'reversed_count' => $reversedCount,
                'voided_learning_record_count' => $voidedLearningRecordCount,
                'voided_sign_in_count' => $voidedSignInCount,
            ],
        ]);
    }

    // ── Helpers (mirror RepairDuplicateSessionSlots logic) ──

    private function crossScDuplicateGroups(array $campusIds = []): array
    {
        $rows = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->leftJoin('User as u', 'u.ID', '=', 'sc.TeacherID')
            ->leftJoin('Subject as sub', 'sub.id', '=', 'sc.SubjectID')
            ->whereRaw("LOWER(cs.Status) IN ('attended','completed')")
            ->when(!empty($campusIds), fn ($q) => $q->whereIn('s.CampusID', $campusIds))
            ->selectRaw('
                cs.id, cs.StudentClassID, cs.SessionDate, SUBSTRING(cs.StartTime,1,5) as hm,
                sc.StudentID, sc.SessionCount, sc.Stop, sc.RemainingSessions, sc.ScheduleMode, sc.StartDate,
                s.name as student_name, u.Name as teacher_name, sub.Subject_Name as subject_name,
                cs.Status as session_status
            ')
            ->orderBy('sc.StudentID')->orderBy('cs.SessionDate')->orderBy('cs.id')
            ->get();

        $groups = [];
        foreach ($rows as $row) {
            $key = $row->StudentID . '|' . $row->SessionDate . '|' . $row->hm;
            $groups[$key]['student_name'] = (string) ($row->student_name ?? '');
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
