<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DuplicateSessionService
{
    /**
     * Encode a group composite key (student_id:date:time) into an id string.
     * Uses base64url-safe encoding.
     */
    public function encodeGroupId(int $studentId, string $date, string $time): string
    {
        return rtrim(strtr(base64_encode("{$studentId}:{$date}:{$time}"), '+/', '-_'), '=');
    }

    /**
     * Decode a group id back to [student_id, date, time].
     * @return array{int, string, string}
     */
    public function decodeGroupId(string $groupId): array
    {
        $decoded = base64_decode(strtr($groupId, '-_', '+/'));
        $parts = explode(':', $decoded, 3);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException('Invalid groupId format');
        }
        return [(int) $parts[0], $parts[1], $parts[2]];
    }

    /**
     * Cross-SC duplicate groups: same student, same date, same HH:MM start,
     * 2+ attended/completed sessions across 2+ different StudentClass rows.
     *
     * @return list<array{student_id:int, date:string, hm:string, rows:list<object>}>
     */
    public function crossScDuplicateGroups(): array
    {
        $rows = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->leftJoin('User as u', 'u.ID', '=', 'sc.TeacherID')
            ->leftJoin('Subject as sub', 'sub.id', '=', 'sc.SubjectID')
            ->whereRaw("LOWER(cs.Status) IN ('attended','completed')")
            ->selectRaw('
                cs.id, cs.StudentClassID, cs.SessionDate,
                SUBSTRING(cs.StartTime,1,5) as hm,
                sc.StudentID, sc.SessionCount, sc.Stop,
                sc.RemainingSessions, sc.ScheduleMode, sc.StartDate,
                s.name as student_name,
                u.Name as teacher_name,
                sub.Subject_Name as subject_name,
                cs.Status as session_status
            ')
            ->orderBy('sc.StudentID')->orderBy('cs.SessionDate')->orderBy('cs.id')
            ->get();

        $groups = [];
        foreach ($rows as $row) {
            $key = $row->StudentID . '|' . $row->SessionDate . '|' . $row->hm;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'student_id' => (int) $row->StudentID,
                    'student_name' => (string) $row->student_name,
                    'date' => (string) $row->SessionDate,
                    'hm' => (string) $row->hm,
                    'rows' => [],
                ];
            }
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

    /**
     * @param  list<int>  $sessionIds
     * @return list<int>
     */
    public function liveLearningRecordSessionIds(array $sessionIds): array
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

    /**
     * Build P2 review groups with enriched side data for frontend display.
     *
     * @return array{groups: list<array>, total: int}
     */
    public function p2ReviewGroups(): array
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
                $statuses = array_values(array_unique(array_map(
                    fn ($r) => strtolower($r->session_status),
                    $rows
                )));

                $sides[] = [
                    'student_class_id' => $scId,
                    'teacher_name' => (string) ($rows[0]->teacher_name ?? ''),
                    'subject_name' => (string) ($rows[0]->subject_name ?? ''),
                    'session_count' => (int) $rows[0]->SessionCount,
                    'remaining_sessions' => $rows[0]->RemainingSessions !== null
                        ? (int) $rows[0]->RemainingSessions : null,
                    'schedule_mode' => (string) ($rows[0]->ScheduleMode ?? ''),
                    'start_date' => (string) ($rows[0]->StartDate ?? ''),
                    'stop' => (int) $rows[0]->Stop,
                    'session_ids' => $sessionIds,
                    'has_live_lr' => count(array_intersect($sessionIds, $liveLr)) > 0,
                    'statuses' => $statuses,
                ];
            }

            $groupId = $this->encodeGroupId($g['student_id'], $g['date'], $g['hm']);

            $p2Groups[] = [
                'id' => $groupId,
                'student_id' => $g['student_id'],
                'student_name' => $g['student_name'],
                'session_date' => $g['date'],
                'start_time' => $g['hm'],
                'sides' => $sides,
                'resolved_keeper_sc_id' => null,
            ];
        }

        return [
            'groups' => $p2Groups,
            'total' => count($p2Groups),
        ];
    }

    /**
     * Record a director's decision.
     */
    public function recordDecision(
        int $studentId,
        string $date,
        string $time,
        int $keepStudentClassId,
        ?string $reason,
        ?int $decidedBy
    ): array {
        $decision = [
            'student_id' => $studentId,
            'date' => $date,
            'time' => $time,
            'keep_student_class_id' => $keepStudentClassId,
            'reason' => $reason ?? '',
            'decided_by' => $decidedBy,
            'decided_at' => now()->toIso8601String(),
        ];

        $path = storage_path('logs/duplicate-session-decisions.jsonl');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode($decision, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

        Log::info('duplicate_session_decision', $decision);

        return $decision;
    }

    /**
     * Execute the repair: cancel non-kept-side sessions for the decided group.
     *
     * @return array{cancelled_count: int, kept_session_ids: list<int>}
     */
    public function executeRepair(
        int $studentId,
        string $date,
        string $time,
        int $keepStudentClassId,
        ?int $executedBy
    ): array {
        $rows = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->where('sc.StudentID', $studentId)
            ->where('cs.SessionDate', $date)
            ->whereRaw('SUBSTRING(cs.StartTime,1,5) = ?', [$time])
            ->whereRaw("LOWER(cs.Status) IN ('attended','completed')")
            ->select('cs.id', 'cs.StudentClassID', 'cs.Status')
            ->get();

        if ($rows->isEmpty()) {
            throw new \RuntimeException('找不到對應的重複群組');
        }

        $cancelledCount = 0;
        $keptSessionIds = [];

        foreach ($rows as $row) {
            if ((int) $row->StudentClassID === $keepStudentClassId) {
                $keptSessionIds[] = (int) $row->id;
                continue;
            }
            DB::table('ClassSession')->where('id', $row->id)->update([
                'Status' => 'cancelled',
                'Note' => trim(($row->Note ?? '') . ' 資料修復 #1130 — 主任審核決策保留 SC' . $keepStudentClassId),
                'updated_at' => now(),
            ]);
            $cancelledCount++;
        }

        Log::info('duplicate_session_executed', [
            'student_id' => $studentId,
            'date' => $date,
            'time' => $time,
            'keep_sc_id' => $keepStudentClassId,
            'cancelled_count' => $cancelledCount,
            'executed_by' => $executedBy,
        ]);

        return [
            'cancelled_count' => $cancelledCount,
            'kept_session_ids' => $keptSessionIds,
        ];
    }

    /**
     * Decide + execute in one step (for PATCH endpoint).
     *
     * @return array{cancelled_count: int, kept_session_ids: list<int>, decision: array}
     */
    public function decideAndExecute(
        int $studentId,
        string $date,
        string $time,
        int $keepStudentClassId,
        ?string $reason,
        ?int $userId
    ): array {
        // 1. Record decision
        $decision = $this->recordDecision(
            $studentId, $date, $time, $keepStudentClassId, $reason, $userId
        );

        // 2. Execute repair
        $result = $this->executeRepair(
            $studentId, $date, $time, $keepStudentClassId, $userId
        );

        return [
            'cancelled_count' => $result['cancelled_count'],
            'kept_session_ids' => $result['kept_session_ids'],
            'decision' => $decision,
        ];
    }
}
