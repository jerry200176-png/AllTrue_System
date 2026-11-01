<?php

namespace App\Services;

use App\Models\StudentClass;
use Illuminate\Database\ConnectionInterface;

/**
 * Count courses holding a lesson from the nightly forward generator (#1062), with the usage the app counts for them.
 * One scan shared by the read-only forward-overdeduct probe and the forward-extra cancel POP operation (#4758), so
 * both name the same courses. SELECTs only.
 */
final class ForwardSessionExtras
{
    /**
     * @return list<array{c: array<string, mixed>, u: ?array<string, mixed>, purchased_minutes: int, over: bool, full: bool, waiting: list<string>}>
     */
    public static function scan(ConnectionInterface $db, int $campusId): array
    {
        // Candidates: count courses holding a generated lesson (the note may carry appended audit suffixes).
        $candidates = array_map(fn ($row) => (array) $row, $db->select(
            'SELECT sc.ID AS class_id, s.CampusID AS campus_id, sc.SessionCount AS session_count, sc.UsedSessions AS used_sessions,'
            . ' sc.SessionDuration AS session_minutes, g.ids AS generated_session_ids, g.statuses AS generated_statuses,'
            . ' (SELECT GROUP_CONCAT(n.ID ORDER BY n.ID SEPARATOR \',\') FROM StudentClass n'
            . '   WHERE n.StudentID = sc.StudentID AND n.SubjectID = sc.SubjectID AND n.ID > sc.ID) AS later_course_ids'
            . ' FROM StudentClass sc JOIN Student s ON s.id = sc.StudentID'
            . ' JOIN (SELECT StudentClassID, GROUP_CONCAT(id ORDER BY SessionDate, id SEPARATOR \',\') AS ids,'
            . '   GROUP_CONCAT(LOWER(Status) ORDER BY SessionDate, id SEPARATOR \',\') AS statuses'
            . '   FROM ClassSession WHERE Note LIKE ? GROUP BY StudentClassID) g ON g.StudentClassID = sc.ID'
            . " WHERE LOWER(sc.ScheduleMode) = 'count' AND sc.SessionCount > 0 AND (? = 0 OR s.CampusID = ?)"
            . ' ORDER BY s.CampusID, sc.ID',
            ['系統向前生成 #1062%', $campusId, $campusId]
        ));
        // Usage exactly as the app counts it: ledger events, or ledger minutes once a partial-duration lesson exists.
        $usage = [];
        foreach (array_chunk(array_map(fn (array $c): int => (int) $c['class_id'], $candidates), 500) as $ids) {
            $usage += SessionDeductionService::batchExpectedUsedSessionDiagnostics($ids);
        }
        $out = [];
        foreach ($candidates as $c) {
            $u = $usage[(int) $c['class_id']] ?? null;
            $perSession = (int) $c['session_minutes'] >= 1 ? (int) $c['session_minutes'] : StudentClass::DEFAULT_SESSION_MINUTES;
            $purchasedMinutes = (int) $c['session_count'] * $perSession;
            $over = $u !== null && ($u['has_partial']
                ? $u['ledger_net_minutes'] > $purchasedMinutes
                : $u['uncapped_used'] > (int) $c['session_count']);
            // #4758: every bought lesson is used, yet a generated lesson may still be scheduled. Marking it would over-deduct.
            $full = $u !== null && ($u['has_partial'] ? $u['ledger_net_minutes'] >= $purchasedMinutes : $u['uncapped_used'] >= (int) $c['session_count']);
            $ids = explode(',', (string) $c['generated_session_ids']);
            $statuses = explode(',', (string) $c['generated_statuses']);
            $waiting = count($ids) === count($statuses)
                ? array_keys(array_filter(array_combine($ids, $statuses), fn (string $status): bool => $status === 'scheduled')) : [];
            $out[] = ['c' => $c, 'u' => $u, 'purchased_minutes' => $purchasedMinutes, 'over' => $over, 'full' => $full, 'waiting' => array_map('strval', $waiting)];
        }

        return $out;
    }
}
