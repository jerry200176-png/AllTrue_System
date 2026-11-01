<?php

/**
 * in-app #419 / #4275 read-only probe: does a shared package (course_packages) that shows more sessions used than its
 * total hold a real overage (attendance is never blocked by balance, G-011) or the same lesson debited twice?
 *
 * Run only by .github/workflows/inapp-package-ledger-probe.yml from main. The entry point at the bottom bootstraps the
 * deployed Laravel app (its own DB connection; this file reads no environment file), makes the MySQL session READ ONLY so the server
 * refuses any write, runs inapp_package_ledger_probe() inside a transaction and rolls it back. The function issues
 * SELECTs only (InappPackageLedgerProbeTest asserts this) and returns ids, counts, dates, times, statuses and reason
 * codes: never names, amounts, notes or request ids (public repo, #3605).
 */

use Illuminate\Database\ConnectionInterface;

/** @return array<string, mixed> */
function inapp_package_ledger_probe(ConnectionInterface $db, int $campusId, int $packageId, bool $allPackages = false): array
{
    $rows = fn (string $sql, array $bindings): array => array_map(fn ($row) => (array) $row, $db->select($sql, $bindings));
    // A package belongs to the campus by its campus_id or through a member course whose student is in the campus (a
    // package can carry another campus id). Targets: the given package; else, with $allPackages, every such package;
    // else those with a session total whose ledger has used more than that total.
    $targets = array_map(fn (array $row): int => (int) $row['id'], $rows(
        'SELECT p.id FROM course_packages p'
        . ' LEFT JOIN (SELECT package_id, SUM(delta) AS net FROM package_session_ledger GROUP BY package_id) l ON l.package_id = p.id'
        . ' WHERE (p.campus_id = ? OR p.id IN (SELECT sc.PackageID FROM StudentClass sc JOIN Student s ON s.id = sc.StudentID'
        . ' WHERE s.CampusID = ? AND sc.PackageID IS NOT NULL))'
        . ' AND (p.id = ? OR (? = 0 AND (? = 1 OR (p.total_sessions > 0 AND -COALESCE(l.net, 0) > p.total_sessions))))'
        . ' ORDER BY p.id LIMIT 200',
        [$campusId, $campusId, $packageId, $packageId, $allPackages ? 1 : 0]
    ));
    $result = ['read_only' => true, 'campus_id' => $campusId, 'package_id' => $packageId, 'all_packages' => $allPackages, 'target_package_ids' => $targets];
    if ($targets === []) {
        return $result;
    }
    $in = implode(',', array_fill(0, count($targets), '?'));

    $result['packages'] = $rows(
        'SELECT p.id, p.campus_id, p.billing_mode, p.class_type, p.total_sessions, p.used_sessions, p.remaining_sessions, p.stop,'
        . ' COALESCE(l.net, 0) AS ledger_net, COALESCE(l.n, 0) AS ledger_rows, COALESCE(l.debits, 0) AS debits, COALESCE(l.credits, 0) AS credits'
        . ' FROM course_packages p LEFT JOIN (SELECT package_id, SUM(delta) AS net, COUNT(*) AS n,'
        . ' SUM(CASE WHEN delta < 0 THEN 1 ELSE 0 END) AS debits, SUM(CASE WHEN delta > 0 THEN 1 ELSE 0 END) AS credits'
        . " FROM package_session_ledger WHERE package_id IN ($in) GROUP BY package_id) l ON l.package_id = p.id"
        . " WHERE p.id IN ($in) ORDER BY p.id",
        [...$targets, ...$targets]
    );
    $result['by_reason'] = $rows(
        'SELECT package_id, reason, delta, COUNT(*) AS ledger_rows, COUNT(DISTINCT class_session_id) AS sessions,'
        . ' SUM(CASE WHEN class_session_id IS NULL THEN 1 ELSE 0 END) AS rows_without_session'
        . " FROM package_session_ledger WHERE package_id IN ($in) GROUP BY package_id, reason, delta ORDER BY package_id, reason, delta",
        $targets
    );
    // The same ClassSession with a net debit of more than one (different reasons get past uq_pkg_session_reason).
    $result['same_session_debited_twice'] = $rows(
        "SELECT l.package_id, l.class_session_id, SUM(l.delta) AS net, GROUP_CONCAT(CONCAT(l.reason, ':', l.delta) ORDER BY l.id SEPARATOR ',') AS reasons,"
        . ' c.SessionDate AS session_date, SUBSTRING(c.StartTime, 1, 5) AS start_time, c.Status AS status'
        . ' FROM package_session_ledger l LEFT JOIN ClassSession c ON c.id = l.class_session_id'
        . " WHERE l.package_id IN ($in) AND l.class_session_id IS NOT NULL"
        . ' GROUP BY l.package_id, l.class_session_id, c.SessionDate, c.StartTime, c.Status HAVING SUM(l.delta) < -1'
        . ' ORDER BY l.package_id, c.SessionDate, c.StartTime LIMIT 200',
        $targets
    );
    // One lesson slot (date + start) debited through more than one ClassSession (e.g. two member courses).
    $result['same_slot_debited_twice'] = $rows(
        'SELECT l.package_id, c.SessionDate AS session_date, SUBSTRING(c.StartTime, 1, 5) AS start_time, SUM(l.delta) AS net,'
        . " COUNT(DISTINCT l.class_session_id) AS sessions, GROUP_CONCAT(DISTINCT l.class_session_id ORDER BY l.class_session_id SEPARATOR ',') AS session_ids"
        . ' FROM package_session_ledger l JOIN ClassSession c ON c.id = l.class_session_id'
        . " WHERE l.package_id IN ($in) GROUP BY l.package_id, c.SessionDate, SUBSTRING(c.StartTime, 1, 5)"
        . ' HAVING SUM(l.delta) < -1 AND COUNT(DISTINCT l.class_session_id) > 1 ORDER BY l.package_id, c.SessionDate LIMIT 200',
        $targets
    );
    // Net debit by the debited session's current status: a debit on a session no longer taught is over-deduction.
    $result['net_debit_by_session_status'] = $rows(
        "SELECT l.package_id, COALESCE(c.Status, '(missing)') AS status, COUNT(DISTINCT l.class_session_id) AS sessions, SUM(l.delta) AS net"
        . ' FROM package_session_ledger l LEFT JOIN ClassSession c ON c.id = l.class_session_id'
        . " WHERE l.package_id IN ($in) AND l.class_session_id IS NOT NULL GROUP BY l.package_id, c.Status ORDER BY l.package_id, c.Status",
        $targets
    );
    // #4740: debits on lessons that no longer exist, per package. Founder rule 1 (2026-10-23): the pool follows the course
    // charge, so a deleted lesson's pool debit still matched by its course ledger (deduct minus reverse) is consistent;
    // `pool_only` is the part no course charge backs. `delete_logged`: lessons whose removal the schedule audit recorded (a row with new_data null).
    $result['deleted_lesson_debits'] = $rows(
        'SELECT s.package_id, COUNT(*) AS sessions, SUM(GREATEST(-s.pool_net, 0)) AS pool_debits,'
        . ' SUM(LEAST(GREATEST(-s.pool_net, 0), GREATEST(s.course_net, 0))) AS course_charged,'
        . ' SUM(GREATEST(-s.pool_net, 0) - LEAST(GREATEST(-s.pool_net, 0), GREATEST(s.course_net, 0))) AS pool_only,'
        . ' SUM(s.delete_logged) AS delete_logged'
        . ' FROM (SELECT l.package_id, l.class_session_id, SUM(l.delta) AS pool_net,'
        . " (SELECT COALESCE(SUM(CASE WHEN d.event_type = 'deduct' THEN 1 WHEN d.event_type = 'reverse' THEN -1 ELSE 0 END), 0)"
        . ' FROM session_deduction_ledger d WHERE d.class_session_id = l.class_session_id) AS course_net,'
        . " EXISTS (SELECT 1 FROM schedule_audit_logs a WHERE a.session_id = l.class_session_id AND a.new_data IS NULL) AS delete_logged"
        . ' FROM package_session_ledger l LEFT JOIN ClassSession c ON c.id = l.class_session_id'
        . " WHERE l.package_id IN ($in) AND l.class_session_id IS NOT NULL AND c.id IS NULL GROUP BY l.package_id, l.class_session_id) s"
        . ' GROUP BY s.package_id ORDER BY s.package_id',
        $targets
    );
    // What the lessons say: rebuildLedgerFromSessions() counts 'attended' slots; the single-to-shared conversion seeds
    // 'attended', 'completed' and 'late'.
    $result['member_lessons'] = $rows(
        'SELECT sc.PackageID AS package_id, COUNT(DISTINCT sc.ID) AS member_courses,'
        . " COUNT(DISTINCT CASE WHEN cs.Status = 'attended' THEN CONCAT(cs.SessionDate, ' ', SUBSTRING(cs.StartTime, 1, 5)) END) AS attended_slots,"
        . " COUNT(DISTINCT CASE WHEN cs.Status IN ('attended', 'completed', 'late') THEN CONCAT(cs.SessionDate, ' ', SUBSTRING(cs.StartTime, 1, 5)) END) AS taught_slots,"
        . " COUNT(DISTINCT CASE WHEN LOWER(cs.Status) = 'absent' THEN CONCAT(cs.SessionDate, ' ', SUBSTRING(cs.StartTime, 1, 5)) END) AS absent_slots,"
        . " COUNT(DISTINCT CASE WHEN LOWER(cs.Status) = 'scheduled' THEN CONCAT(cs.SessionDate, ' ', SUBSTRING(cs.StartTime, 1, 5)) END) AS scheduled_slots,"
        // Future planned as SharedPackagePlanningService counts it: active members, from today; a group slot once, else each lesson.
        . " COUNT(DISTINCT CASE WHEN cs.SessionDate >= ? AND cs.Status IN ('scheduled', 'rescheduled', 'leave_requested') AND (sc.Stop = 0 OR sc.Stop IS NULL)"
        . " THEN (CASE WHEN p.class_type IN ('one_on_two', 'one_on_three', 'one_on_many', 'tutoring') THEN CONCAT(cs.SessionDate, ' ', SUBSTRING(cs.StartTime, 1, 5))"
        . " ELSE CONCAT('#', cs.id) END) END) AS future_planned"
        . " FROM StudentClass sc JOIN course_packages p ON p.id = sc.PackageID LEFT JOIN ClassSession cs ON cs.StudentClassID = sc.ID WHERE sc.PackageID IN ($in)"
        . ' GROUP BY sc.PackageID ORDER BY sc.PackageID',
        [\Carbon\Carbon::today()->toDateString(), ...$targets]
    );
    // Repair candidates for #4275: a member lesson still charged on its course ledger (deduct minus reverse > 0) whose
    // package debit is missing. Group class types debit one (date + start) slot for all members, so they are reconciled per
    // slot; every other package debits each lesson, so it is reconciled per lesson (PackageDeductionService::groupSlot()).
    // The package ledger used to skip a lesson reverted and then attended again. Not every row is a gap:
    // `charged_before_package` = 1 marks lessons last charged before the package was made (a single-to-shared conversion
    // seeds only attended, completed and late lessons per session; the rest become session-less `conversion_seed_orphan`
    // rows, see `debits_without_session`), and a status that is not chargeable is a course-ledger question, not a pool gap.
    $groupTypes = "'one_on_two', 'one_on_three', 'one_on_many', 'tutoring'";
    $isGroup = "p.class_type IN ($groupTypes)";
    $packageNet = "CASE WHEN $isGroup THEN COALESCE(ps.net, 0) ELSE COALESCE(pss.net, 0) END";
    $candidates = $rows(
        'SELECT sc.PackageID AS package_id, cs.id AS class_session_id, cs.SessionDate AS session_date,'
        . " SUBSTRING(cs.StartTime, 1, 5) AS start_time, cs.Status AS status, CASE WHEN $isGroup THEN 1 ELSE 0 END AS group_slot,"
        . " $packageNet AS package_net, CASE WHEN d.last_deduct_at < p.created_at THEN 1 ELSE 0 END AS charged_before_package"
        . ' FROM StudentClass sc JOIN course_packages p ON p.id = sc.PackageID JOIN ClassSession cs ON cs.StudentClassID = sc.ID'
        . " JOIN (SELECT student_class_id, class_session_id, SUM(CASE WHEN event_type = 'deduct' THEN 1"
        . " WHEN event_type = 'reverse' THEN -1 ELSE 0 END) AS net, MAX(CASE WHEN event_type = 'deduct' THEN created_at END) AS last_deduct_at"
        . ' FROM session_deduction_ledger'
        . " WHERE student_class_id IN (SELECT ID FROM StudentClass WHERE PackageID IN ($in)) AND class_session_id IS NOT NULL"
        . ' GROUP BY student_class_id, class_session_id) d ON d.student_class_id = sc.ID AND d.class_session_id = cs.id AND d.net > 0'
        . ' LEFT JOIN (SELECT l.package_id, c2.SessionDate, c2.StartTime, SUM(l.delta) AS net'
        . ' FROM package_session_ledger l JOIN ClassSession c2 ON c2.id = l.class_session_id'
        . " WHERE l.package_id IN ($in) GROUP BY l.package_id, c2.SessionDate, c2.StartTime) ps"
        . ' ON ps.package_id = sc.PackageID AND ps.SessionDate = cs.SessionDate AND ps.StartTime = cs.StartTime'
        . ' LEFT JOIN (SELECT package_id, class_session_id, SUM(delta) AS net FROM package_session_ledger'
        . " WHERE package_id IN ($in) AND class_session_id IS NOT NULL GROUP BY package_id, class_session_id) pss"
        . ' ON pss.package_id = sc.PackageID AND pss.class_session_id = cs.id'
        . " WHERE sc.PackageID IN ($in) AND $packageNet >= 0"
        . ' ORDER BY sc.PackageID, cs.SessionDate, cs.StartTime, cs.id LIMIT 200',
        [...$targets, ...$targets, ...$targets, ...$targets]
    );
    // One row per group slot (its charged lessons together) or per lesson.
    $gaps = [];
    foreach ($candidates as $row) {
        $group = (int) $row['group_slot'] === 1;
        $key = $row['package_id'] . '|' . ($group ? $row['session_date'] . ' ' . $row['start_time'] : 's' . $row['class_session_id']);
        $gap = $gaps[$key] ?? [
            'package_id' => (int) $row['package_id'], 'scope' => $group ? 'slot' : 'lesson', 'session_date' => (string) $row['session_date'],
            'start_time' => (string) $row['start_time'], 'charged_session_ids' => [], 'statuses' => [], 'package_net' => (int) $row['package_net'],
            'charged_before_package' => 1,
        ];
        $gap['charged_session_ids'][] = (int) $row['class_session_id'];
        $gap['statuses'][(string) $row['status']] = true;
        $gap['charged_before_package'] = min($gap['charged_before_package'], (int) $row['charged_before_package']);
        $gaps[$key] = $gap;
    }
    $result['charged_slots_not_debited'] = array_values(array_map(function (array $gap): array {
        $statuses = array_keys($gap['statuses']);
        sort($statuses);

        return [
            'package_id' => $gap['package_id'], 'scope' => $gap['scope'], 'session_date' => $gap['session_date'], 'start_time' => $gap['start_time'],
            'charged_session_ids' => implode(',', $gap['charged_session_ids']), 'statuses' => implode(',', $statuses),
            'package_net' => $gap['package_net'], 'charged_before_package' => $gap['charged_before_package'],
        ];
    }, $gaps));
    $result['members'] = $rows(
        'SELECT sc.PackageID AS package_id, sc.ID AS student_class_id, sc.StudentID AS student_id, sc.SubjectID AS subject_id,'
        . " sc.Stop AS stop, sc.ScheduleMode AS schedule_mode FROM StudentClass sc WHERE sc.PackageID IN ($in)"
        . ' ORDER BY sc.PackageID, sc.ID LIMIT 200',
        $targets
    );

    // One line per package to read the verdict from: what the ledger used against the lessons taught, and the debits
    // the lessons do not explain. A no-show marked absent is charged too (ClassSessionController), so it is a chargeable
    // status here. The twice-debited lists stop at 200 rows (`lists_capped`).
    // The charged-not-debited list stops at 200 rows too; a package past the cap counts 0 there.
    $chargeable = ['attended', 'completed', 'late', 'absent'];
    // Rows keyed by their package: `packages` rows carry it as `id`, every other section as `package_id`.
    $byPackage = fn (string $section): array => array_reduce($result[$section], function (array $carry, array $row): array {
        $carry[(int) ($row['package_id'] ?? $row['id'])][] = $row;

        return $carry;
    }, []);
    [$packages, $lessons, $sessionsTwice, $slotsTwice, $byStatus, $byReason, $notDebited] = array_map($byPackage,
        ['packages', 'member_lessons', 'same_session_debited_twice', 'same_slot_debited_twice', 'net_debit_by_session_status', 'by_reason',
            'charged_slots_not_debited']);
    $summary = array_map(fn (int $id): array => [
        'package_id' => $id,
        'total_sessions' => (int) ($packages[$id][0]['total_sessions'] ?? 0),
        'ledger_used' => -(int) ($packages[$id][0]['ledger_net'] ?? 0),
        'attended_slots' => (int) ($lessons[$id][0]['attended_slots'] ?? 0),
        'taught_slots' => (int) ($lessons[$id][0]['taught_slots'] ?? 0),
        'absent_slots' => (int) ($lessons[$id][0]['absent_slots'] ?? 0),
        // Lessons on the calendar past what the pool has left: pre-planned per subject before #4730.
        'future_planned' => $planned = (int) ($lessons[$id][0]['future_planned'] ?? 0),
        // Session-count pools only, as summarizeMany(): a monthly (date) package has no bought-lesson limit.
        'overplanned' => ($packages[$id][0]['billing_mode'] ?? 'count') !== 'count' ? 0
            : max(0, $planned - max(0, (int) ($packages[$id][0]['total_sessions'] ?? 0) + (int) ($packages[$id][0]['ledger_net'] ?? 0))),
        'sessions_debited_twice' => count($sessionsTwice[$id] ?? []),
        'slots_debited_twice' => count($slotsTwice[$id] ?? []),
        'debits_on_unchargeable_sessions' => array_sum(array_map(
            fn (array $row): int => in_array(strtolower((string) $row['status']), $chargeable, true) ? 0 : max(0, -(int) $row['net']),
            $byStatus[$id] ?? []
        )),
        'debits_without_session' => array_sum(array_map(fn (array $row): int => (int) $row['delta'] < 0 ? (int) $row['rows_without_session'] : 0, $byReason[$id] ?? [])),
        // Gaps since the package was made; rows flagged `charged_before_package` are listed but not counted.
        'charged_since_package_not_debited' => count(array_filter($notDebited[$id] ?? [], fn (array $row): bool => (int) $row['charged_before_package'] === 0)),
        'lists_capped' => count($result['same_session_debited_twice']) >= 200 || count($result['same_slot_debited_twice']) >= 200
            || count($candidates) >= 200,
    ], $targets);

    if ($packageId > 0 && $targets === [$packageId]) {
        $result += inapp_package_ledger_rows($rows, $packageId, $chargeable);
    }

    // The summary first, so a long all-packages output cannot push it out of a truncated job summary.
    return array_slice($result, 0, 5, true) + ['summary' => $summary] + $result;
}

/**
 * One named package, row by row (#4455 over-debits): every ledger row on a lesson that is not chargeable or no longer
 * exists, joined to each live chargeable member lesson on the same date at any start (one output row per such lesson,
 * twin_* null when there is none; a package creation seed sits at a default start, so its re-created lesson can be at
 * another; twin_start_time tells the same slot apart), and every ledger row of a slot debited through more than one
 * lesson. A deleted lesson's slot comes from its last schedule audit snapshot, else the date of its sign-in (start
 * unknown). course_net: the lesson's course ledger deduct minus reverse.
 *
 * @param list<string> $chargeable
 * @return array{unchargeable_session_rows: list<array<string, mixed>>, shared_slot_rows: list<array<string, mixed>>}
 */
function inapp_package_ledger_rows(callable $rows, int $packageId, array $chargeable): array
{
    $courseNet = fn (string $id): string => "(SELECT COALESCE(SUM(CASE WHEN d.event_type = 'deduct' THEN 1 WHEN d.event_type = 'reverse' THEN -1"
        . " ELSE 0 END), 0) FROM session_deduction_ledger d WHERE d.class_session_id = $id)";
    $ledger = $rows(
        'SELECT l.id AS ledger_id, l.class_session_id, l.student_class_id, l.reason, l.delta, l.created_at, c.id AS live_id,'
        . ' LOWER(c.Status) AS status, c.SessionDate AS session_date, SUBSTRING(c.StartTime, 1, 5) AS start_time, '
        . $courseNet('l.class_session_id') . ' AS course_net'
        . ' FROM package_session_ledger l LEFT JOIN ClassSession c ON c.id = l.class_session_id'
        . ' WHERE l.package_id = ? AND l.class_session_id IS NOT NULL ORDER BY l.id',
        [$packageId]
    );
    $gone = array_values(array_unique(array_map(fn (array $r): int => (int) $r['class_session_id'], array_filter($ledger, fn (array $r): bool => $r['live_id'] === null))));
    $known = [];
    if ($gone !== []) {
        $in = implode(',', array_fill(0, count($gone), '?'));
        foreach ($rows("SELECT ClassSessionID AS id, MIN(DATE(SignInDT)) AS session_date FROM StudentSingIn WHERE ClassSessionID IN ($in) GROUP BY ClassSessionID", $gone) as $r) {
            $known[(int) $r['id']] = ['session_date' => (string) $r['session_date'], 'start_time' => null, 'last_status' => null, 'deleted_at' => null, 'slot_source' => 'sign_in'];
        }
        // The newest audit row: a deletion keeps the lesson in old_data (new_data null), a new or edited lesson in new_data.
        $field = fn (string $key): string => "JSON_UNQUOTE(JSON_EXTRACT(COALESCE(a.new_data, a.old_data), '$.$key'))";
        foreach ($rows(
            'SELECT a.session_id AS id, SUBSTRING(' . $field('SessionDate') . ', 1, 10) AS session_date, SUBSTRING(' . $field('StartTime') . ', 1, 5) AS start_time,'
            . ' LOWER(' . $field('Status') . ') AS last_status, CASE WHEN a.new_data IS NULL THEN a.created_at END AS deleted_at'
            . " FROM schedule_audit_logs a WHERE a.id IN (SELECT MAX(id) FROM schedule_audit_logs WHERE session_id IN ($in) GROUP BY session_id)",
            $gone
        ) as $r) {
            $known[(int) $r['id']] = ['session_date' => $r['session_date'], 'start_time' => $r['start_time'], 'last_status' => $r['last_status'],
                'deleted_at' => $r['deleted_at'], 'slot_source' => 'audit'];
        }
    }
    $lessons = $rows(
        'SELECT c.id, c.StudentClassID AS student_class_id, c.SessionDate AS session_date, SUBSTRING(c.StartTime, 1, 5) AS start_time,'
        . ' LOWER(c.Status) AS status, (SELECT COALESCE(SUM(p.delta), 0) FROM package_session_ledger p WHERE p.package_id = ? AND p.class_session_id = c.id) AS package_net, '
        . $courseNet('c.id') . ' AS course_net FROM ClassSession c JOIN StudentClass sc ON sc.ID = c.StudentClassID'
        . ' WHERE sc.PackageID = ? AND LOWER(c.Status) IN (' . implode(',', array_fill(0, count($chargeable), '?')) . ') ORDER BY c.id',
        [$packageId, $packageId, ...$chargeable]
    );
    $sessionNet = [];
    foreach ($ledger as $r) {
        $sessionNet[(int) $r['class_session_id']] = ($sessionNet[(int) $r['class_session_id']] ?? 0) + (int) $r['delta'];
    }
    $at = fn (array $r): array => $r['live_id'] !== null
        ? ['status' => (string) $r['status'], 'last_status' => null, 'deleted_at' => null, 'session_date' => (string) $r['session_date'],
            'start_time' => (string) $r['start_time'], 'slot_source' => 'session']
        : ['status' => 'deleted'] + ($known[(int) $r['class_session_id']]
            ?? ['last_status' => null, 'deleted_at' => null, 'session_date' => null, 'start_time' => null, 'slot_source' => null]);
    $unchargeable = $slots = [];
    foreach ($ledger as $r) {
        $cs = (int) $r['class_session_id'];
        $where = $at($r);
        $row = ['package_id' => $packageId, 'ledger_id' => (int) $r['ledger_id'], 'class_session_id' => $cs, 'student_class_id' => (int) $r['student_class_id'],
            'reason' => (string) $r['reason'], 'delta' => (int) $r['delta'], 'created_at' => (string) $r['created_at'], 'status' => $where['status'],
            'last_status' => $where['last_status'], 'deleted_at' => $where['deleted_at'], 'session_date' => $where['session_date'],
            'start_time' => $where['start_time'], 'slot_source' => $where['slot_source'], 'session_net' => $sessionNet[$cs], 'course_net' => (int) $r['course_net']];
        if ($where['session_date'] !== null && $where['start_time'] !== null) {
            $slots[$where['session_date'] . ' ' . $where['start_time']][] = $row;
        }
        if (in_array($where['status'], $chargeable, true)) {
            continue;
        }
        $twins = array_filter($lessons, fn (array $l): bool => (int) $l['id'] !== $cs && $where['session_date'] !== null
            && (string) $l['session_date'] === $where['session_date']);
        foreach ($twins ?: [null] as $l) {
            $unchargeable[] = $row + ['twin_session_id' => $l ? (int) $l['id'] : null, 'twin_student_class_id' => $l ? (int) $l['student_class_id'] : null,
                'twin_start_time' => $l['start_time'] ?? null, 'twin_status' => $l['status'] ?? null, 'twin_package_net' => $l ? (int) $l['package_net'] : null,
                'twin_course_net' => $l ? (int) $l['course_net'] : null];
        }
    }
    $shared = [];
    foreach ($slots as $slotRows) {
        if (count(array_unique(array_column($slotRows, 'class_session_id'))) > 1) {
            $net = array_sum(array_column($slotRows, 'delta'));
            foreach ($slotRows as $row) {
                $shared[] = array_intersect_key($row, array_flip(['package_id', 'session_date', 'start_time', 'ledger_id', 'class_session_id', 'student_class_id',
                    'status', 'reason', 'delta', 'created_at', 'course_net'])) + ['slot_net' => $net];
            }
        }
    }

    return ['unchargeable_session_rows' => $unchargeable, 'shared_slot_rows' => $shared];
}

if (PHP_SAPI === 'cli' && getenv('INAPP_PACKAGE_LEDGER_PROBE') === '1') {
    // Any failure prints the exception class only: a message can carry SQL or connection details, and the log is public.
    // Catching here also keeps Laravel's handler from writing a log file or a Sentry event for it.
    try {
        $root = (string) getenv('APP_ROOT');
        require $root . '/vendor/autoload.php';
        $app = require $root . '/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $db = Illuminate\Support\Facades\DB::connection();
        $db->statement('SET SESSION TRANSACTION READ ONLY');
        try {
            $db->statement('SET SESSION MAX_EXECUTION_TIME = 30000'); // MySQL: a SELECT stops after 30 s
        } catch (Throwable) {
            $db->statement('SET SESSION max_statement_time = 30'); // MariaDB
        }
        // Read through the write PDO: that is the session the queries run on (a DB_READ_HOST would be another one).
        $sessionReadOnly = function () use ($db): int {
            try {
                return (int) ($db->selectOne('SELECT @@SESSION.transaction_read_only AS ro', [], false)->ro ?? 0);
            } catch (Throwable) { // MariaDB before 11.1 names it tx_read_only
                return (int) ($db->selectOne('SELECT @@SESSION.tx_read_only AS ro', [], false)->ro ?? 0);
            }
        };
        if ($sessionReadOnly() !== 1) {
            fwrite(STDERR, "refusing to run: the session is not read-only\n");
            exit(1);
        }
        $db->beginTransaction();
        try {
            // Checked again inside the transaction: a reconnect would have opened a new session without the mode.
            if ($sessionReadOnly() !== 1) {
                throw new RuntimeException('session lost read-only mode');
            }
            $out = inapp_package_ledger_probe($db, (int) getenv('CAMPUS_ID'), (int) getenv('PACKAGE_ID'), getenv('ALL_PACKAGES') === 'true');
        } finally {
            $db->rollBack();
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'probe_failed: ' . get_class($e) . "\n");
        exit(2);
    }
    echo json_encode($out + ['session_read_only' => 1], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
}
