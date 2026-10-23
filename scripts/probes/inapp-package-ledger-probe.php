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
function inapp_package_ledger_probe(ConnectionInterface $db, int $campusId, int $packageId): array
{
    $rows = fn (string $sql, array $bindings): array => array_map(fn ($row) => (array) $row, $db->select($sql, $bindings));
    // The given package if it belongs to the campus; else every campus package with a session total whose ledger has
    // used more than that total.
    $targets = array_map(fn (array $row): int => (int) $row['id'], $rows(
        'SELECT p.id FROM course_packages p'
        . ' LEFT JOIN (SELECT package_id, SUM(delta) AS net FROM package_session_ledger GROUP BY package_id) l ON l.package_id = p.id'
        . ' WHERE p.campus_id = ? AND (p.id = ? OR (? = 0 AND p.total_sessions > 0 AND -COALESCE(l.net, 0) > p.total_sessions))'
        . ' ORDER BY p.id LIMIT 50',
        [$campusId, $packageId, $packageId]
    ));
    $result = ['read_only' => true, 'campus_id' => $campusId, 'package_id' => $packageId, 'target_package_ids' => $targets];
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
    // What the lessons say: rebuildLedgerFromSessions() counts 'attended' slots; the single-to-shared conversion seeds
    // 'attended', 'completed' and 'late'.
    $result['member_lessons'] = $rows(
        'SELECT sc.PackageID AS package_id, COUNT(DISTINCT sc.ID) AS member_courses,'
        . " COUNT(DISTINCT CASE WHEN cs.Status = 'attended' THEN CONCAT(cs.SessionDate, ' ', SUBSTRING(cs.StartTime, 1, 5)) END) AS attended_slots,"
        . " COUNT(DISTINCT CASE WHEN cs.Status IN ('attended', 'completed', 'late') THEN CONCAT(cs.SessionDate, ' ', SUBSTRING(cs.StartTime, 1, 5)) END) AS taught_slots,"
        . " COUNT(DISTINCT CASE WHEN LOWER(cs.Status) = 'scheduled' THEN CONCAT(cs.SessionDate, ' ', SUBSTRING(cs.StartTime, 1, 5)) END) AS scheduled_slots"
        . " FROM StudentClass sc LEFT JOIN ClassSession cs ON cs.StudentClassID = sc.ID WHERE sc.PackageID IN ($in)"
        . ' GROUP BY sc.PackageID ORDER BY sc.PackageID',
        $targets
    );
    $result['members'] = $rows(
        'SELECT sc.PackageID AS package_id, sc.ID AS student_class_id, sc.StudentID AS student_id, sc.SubjectID AS subject_id,'
        . " sc.Stop AS stop, sc.ScheduleMode AS schedule_mode FROM StudentClass sc WHERE sc.PackageID IN ($in)"
        . ' ORDER BY sc.PackageID, sc.ID LIMIT 200',
        $targets
    );

    return $result;
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
            $out = inapp_package_ledger_probe($db, (int) getenv('CAMPUS_ID'), (int) getenv('PACKAGE_ID'));
        } finally {
            $db->rollBack();
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'probe_failed: ' . get_class($e) . "\n");
        exit(2);
    }
    echo json_encode($out + ['session_read_only' => 1], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
}
