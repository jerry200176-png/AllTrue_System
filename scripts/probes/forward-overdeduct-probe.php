<?php

/**
 * #4554 read-only probe: count courses that the nightly forward generator (#1062) gave an extra lesson while the last
 * one was still unmarked (#4515, course 2739; fixed by #4552), and whose ledger now holds more attendance deducts than
 * the course bought. Each hit lists the generated lessons and the later same-student/subject courses, the targets for
 * the existing calendar action 「改到其他合約」.
 *
 * Run only by .github/workflows/forward-overdeduct-probe.yml from main, the same way as inapp-package-ledger-probe.php:
 * Usage comes from SessionDeductionService::batchExpectedUsedSessionDiagnostics (ledger events, or minutes once a
 * partial-duration lesson exists); the generator note is matched as a prefix ('; revert-to-scheduled' suffixes).
 * READ ONLY session, one rolled-back transaction, SELECTs only (ForwardOverdeductProbeTest asserts this). Output is ids,
 * counts and statuses: never names, amounts or notes (public repo, #3605).
 */

use Illuminate\Database\ConnectionInterface;

/** @return array<string, mixed> */
function forward_overdeduct_probe(ConnectionInterface $db, int $campusId): array
{
    // The scan (candidates, usage as the app counts it, over/full flags) is shared with the #4758 cancel operation.
    $scan = \App\Services\ForwardSessionExtras::scan($db, $campusId);
    $courses = [];
    $pending = [];
    foreach ($scan as $x) {
        ['c' => $c, 'u' => $u, 'purchased_minutes' => $purchasedMinutes, 'over' => $over, 'waiting' => $waiting] = $x;
        if (!$over && $x['full'] && $waiting !== [] && count($pending) < 200) {
            $pending[] = ['class_id' => $c['class_id'], 'campus_id' => $c['campus_id'], 'session_count' => $c['session_count'],
                'ledger_used' => $u['uncapped_used'], 'scheduled_generated_session_ids' => implode(',', $waiting), 'later_course_ids' => $c['later_course_ids']];
        }
        if (!$over || count($courses) >= 200) {
            continue;
        }
        $courses[] = ['class_id' => $c['class_id'], 'campus_id' => $c['campus_id'], 'session_count' => $c['session_count'],
            'used_sessions' => $c['used_sessions'], 'ledger_used' => $u['uncapped_used'], 'ledger_net_minutes' => $u['ledger_net_minutes'],
            'purchased_minutes' => $purchasedMinutes, 'has_partial' => $u['has_partial'],
            'generated_session_ids' => $c['generated_session_ids'], 'generated_statuses' => $c['generated_statuses'],
            'later_course_ids' => $c['later_course_ids']];
    }
    $byCampus = [];
    foreach ($courses as $course) {
        $byCampus[(int) $course['campus_id']] = ($byCampus[(int) $course['campus_id']] ?? 0) + 1;
    }
    ksort($byCampus);

    return [
        'read_only' => true,
        'campus_id' => $campusId,
        'summary' => [
            'candidates' => count($scan),
            'courses' => count($courses),
            'by_campus' => $byCampus,
            'list_capped' => count($courses) >= 200,
        ],
        'courses' => $courses,
        'pending_extras' => $pending,
    ];
}

// ponytail: same read-only entry as inapp-package-ledger-probe.php; extract a shared one if a third probe appears.
if (PHP_SAPI === 'cli' && getenv('FORWARD_OVERDEDUCT_PROBE') === '1') {
    // Any failure prints the exception class only: a message can carry SQL or connection details, and the log is public.
    try {
        $root = (string) getenv('APP_ROOT');
        require $root . '/vendor/autoload.php';
        $app = require $root . '/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $db = Illuminate\Support\Facades\DB::connection();
        $db->statement('SET SESSION TRANSACTION READ ONLY');
        try {
            $db->statement('SET SESSION MAX_EXECUTION_TIME = 30000'); // MySQL
        } catch (Throwable) {
            $db->statement('SET SESSION max_statement_time = 30'); // MariaDB
        }
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
            if ($sessionReadOnly() !== 1) {
                throw new RuntimeException('session lost read-only mode');
            }
            $out = forward_overdeduct_probe($db, (int) getenv('CAMPUS_ID'));
        } finally {
            $db->rollBack();
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'probe_failed: ' . get_class($e) . "\n");
        exit(2);
    }
    echo json_encode($out + ['session_read_only' => 1], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
}
