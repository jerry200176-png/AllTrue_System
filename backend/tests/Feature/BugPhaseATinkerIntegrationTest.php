<?php

namespace Tests\Feature;

use App\Models\BugReport;
use App\Models\BugReportComment;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Minimal MySQL integration for Phase-A write tinker (PR #4246).
 *
 * Loads the exact `php artisan tinker --execute='…'` bytes from
 * `.github/workflows/bug-phase-a-triage.yml` (REMOTE heredoc) and runs them
 * as a subprocess against AllTrue_test. Does not reimplement chunk/hash/receipt
 * logic. Opts out of RefreshDatabase wrapping so subprocess commit/rollback
 * boundaries stay visible to assertions.
 */
final class BugPhaseATinkerIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string|null> */
    protected $connectionsToTransact = [];

    private const ISSUE = 'https://github.com/jerry200176-png/AllTrue_System/issues/4246';

    /** Must match chunkById(200) in the extracted workflow tinker payload. */
    private const CHUNK_SIZE = 200;

    private const FORCE_ROLLBACK_TRIGGER = 'phase_a_force_comment_rollback_trg';

    private string $tinkerPhp = '';

    /** @var list<int> */
    private array $cleanupBugIds = [];

    /** @var list<int> */
    private array $cleanupUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Phase-A tinker integration requires MySQL AllTrue_test');
        }
        $this->tinkerPhp = self::extractTinkerPhpFromWorkflow();
        $this->assertStringContainsString('chunkById(', $this->tinkerPhp);
        $this->assertMatchesRegularExpression('/chunkById\(\s*' . self::CHUNK_SIZE . '\s*,/', $this->tinkerPhp);
        $this->assertStringContainsString('phase_a_receipt', $this->tinkerPhp);
        $this->assertStringContainsString('hash("sha256"', $this->tinkerPhp);
        $this->assertStringNotContainsString('->limit(100)', $this->tinkerPhp);
    }

    protected function tearDown(): void
    {
        $this->dropForceRollbackTrigger();
        foreach ($this->cleanupBugIds as $id) {
            BugReportComment::where('bug_report_id', $id)->delete();
            DB::table('bug_report_status_logs')->where('bug_report_id', $id)->delete();
            BugReport::whereKey($id)->delete();
        }
        foreach ($this->cleanupUserIds as $id) {
            UserCampus::where('UserID', $id)->delete();
            User::whereKey($id)->delete();
        }
        parent::tearDown();
    }

    public function test_exact_hash_on_chunkById_second_page_hits_and_retry_dedupes(): void
    {
        [, $reporter] = $this->actors();
        $reply = 'phase-a-chunk2-target-' . uniqid('', true);
        $bug = $this->newBug($reporter);

        // Page 1 = first CHUNK_SIZE rows by ascending id; target must land on page 2.
        $rows = [];
        for ($i = 1; $i <= self::CHUNK_SIZE; $i++) {
            $rows[] = [
                'bug_report_id' => $bug->id,
                'author_user_id' => $reporter->id,
                'body' => "filler-page1-{$i}-{$bug->id}",
                'is_internal_note' => 0,
                'created_at' => now()->toDateTimeString(),
            ];
        }
        $rows[] = [
            'bug_report_id' => $bug->id,
            'author_user_id' => $reporter->id,
            'body' => $reply,
            'is_internal_note' => 0,
            'created_at' => now()->toDateTimeString(),
        ];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = [
                'bug_report_id' => $bug->id,
                'author_user_id' => $reporter->id,
                'body' => "filler-page2-after-{$i}-{$bug->id}",
                'is_internal_note' => 0,
                'created_at' => now()->toDateTimeString(),
            ];
        }
        BugReportComment::insert($rows);

        $orderedIds = BugReportComment::query()
            ->where('bug_report_id', $bug->id)
            ->where('is_internal_note', false)
            ->orderBy('id')
            ->pluck('id')
            ->all();
        $this->assertGreaterThan(self::CHUNK_SIZE, count($orderedIds));
        $page1 = array_slice($orderedIds, 0, self::CHUNK_SIZE);
        $page2 = array_slice($orderedIds, self::CHUNK_SIZE);
        $targetId = (int) BugReportComment::query()
            ->where('bug_report_id', $bug->id)
            ->where('body', $reply)
            ->value('id');
        $this->assertNotContains($targetId, $page1, 'fixture: target must miss chunkById page 1');
        $this->assertContains($targetId, $page2, 'fixture: target must sit on chunkById page 2');

        $first = $this->runExtractedTinker([
            'BUG_ID' => (string) $bug->id,
            'GITHUB_ISSUE_URL' => self::ISSUE,
            'DISPOSITION' => 'bug',
            'PUBLIC_REPLY' => $reply,
            'CLOSE_AS_LOGGED' => 'false',
        ]);
        $this->assertNotNull($first['receipt'], $first['stderr'] . "\n" . $first['stdout']);
        $this->assertSame(1, (int) $first['receipt']['phase_a_receipt']);
        $this->assertSame($targetId, (int) $first['receipt']['comment_id']);
        $this->assertTrue((bool) $first['receipt']['comment_skipped']);
        $this->assertSame('triaged', $first['receipt']['final']);
        $this->assertSame(hash('sha256', $reply), $first['receipt']['public_body_sha256']);
        $publicCount = BugReportComment::where('bug_report_id', $bug->id)->where('is_internal_note', false)->count();
        $this->assertSame(count($orderedIds), $publicCount);

        // Newer reporter follow-up must not become the retry match key.
        BugReportComment::create([
            'bug_report_id' => $bug->id,
            'author_user_id' => $reporter->id,
            'body' => 'reporter follow-up after chunk2 ' . uniqid('', true),
            'is_internal_note' => false,
            'created_at' => now(),
        ]);
        $beforeRetry = BugReportComment::where('bug_report_id', $bug->id)->where('is_internal_note', false)->count();

        $retry = $this->runExtractedTinker([
            'BUG_ID' => (string) $bug->id,
            'GITHUB_ISSUE_URL' => self::ISSUE,
            'DISPOSITION' => 'bug',
            'PUBLIC_REPLY' => $reply,
            'CLOSE_AS_LOGGED' => 'false',
        ]);
        $this->assertNotNull($retry['receipt'], $retry['stderr'] . "\n" . $retry['stdout']);
        $this->assertTrue((bool) $retry['receipt']['comment_skipped']);
        $this->assertSame($targetId, (int) $retry['receipt']['comment_id']);
        $this->assertSame(
            $beforeRetry,
            BugReportComment::where('bug_report_id', $bug->id)->where('is_internal_note', false)->count(),
            'retry must not insert a second approved body after crossing chunk page 2'
        );
    }

    public function test_case_and_accent_byte_differences_do_not_sha256_collide(): void
    {
        [, $reporter] = $this->actors();
        $this->assertNotSame(hash('sha256', 'cafe'), hash('sha256', 'café'));
        $nearMiss = 'Cafe';
        $reply = 'café';
        $this->assertNotSame(hash('sha256', $nearMiss), hash('sha256', $reply));

        $bug = $this->newBug($reporter);
        BugReportComment::create([
            'bug_report_id' => $bug->id,
            'author_user_id' => $reporter->id,
            'body' => $nearMiss,
            'is_internal_note' => false,
            'created_at' => now(),
        ]);
        $ciHit = (int) DB::table('bug_report_comments')
            ->where('bug_report_id', $bug->id)
            ->whereRaw('body = ? COLLATE utf8mb4_unicode_ci', [$reply])
            ->count();
        if ($ciHit === 0) {
            BugReportComment::where('bug_report_id', $bug->id)->delete();
            $nearMiss = 'Resume';
            $reply = 'resume';
            $this->assertNotSame(hash('sha256', $nearMiss), hash('sha256', $reply));
            BugReportComment::create([
                'bug_report_id' => $bug->id,
                'author_user_id' => $reporter->id,
                'body' => $nearMiss,
                'is_internal_note' => false,
                'created_at' => now(),
            ]);
            $ciHit = (int) DB::table('bug_report_comments')
                ->where('bug_report_id', $bug->id)
                ->whereRaw('body = ? COLLATE utf8mb4_unicode_ci', [$reply])
                ->count();
        }
        $this->assertGreaterThan(0, $ciHit, 'fixture: MySQL unicode_ci would treat near-miss as equal');

        $result = $this->runExtractedTinker([
            'BUG_ID' => (string) $bug->id,
            'GITHUB_ISSUE_URL' => self::ISSUE,
            'DISPOSITION' => 'bug',
            'PUBLIC_REPLY' => $reply,
            'CLOSE_AS_LOGGED' => 'false',
        ]);

        $this->assertNotNull($result['receipt'], $result['stderr'] . "\n" . $result['stdout']);
        $this->assertFalse((bool) $result['receipt']['comment_skipped'], 'byte-different body must insert, not reuse unicode_ci match');
        $this->assertSame(2, BugReportComment::where('bug_report_id', $bug->id)->where('is_internal_note', false)->count());
        $bodies = BugReportComment::where('bug_report_id', $bug->id)->where('is_internal_note', false)->pluck('body')->all();
        $this->assertContains($nearMiss, $bodies);
        $this->assertContains($reply, $bodies);
        $inserted = BugReportComment::find((int) $result['receipt']['comment_id']);
        $this->assertSame($reply, (string) $inserted->body);
        $this->assertSame(hash('sha256', $reply), $result['receipt']['public_body_sha256']);
    }

    /**
     * Force failure after changeStatus has written inside the tinker transaction
     * (comment INSERT trigger), prove an independent connection never sees a
     * half-write / no success receipt, then drop the trigger and commit+retry.
     */
    public function test_post_write_failure_rolls_back_then_commit_receipt_and_retry_dedupes(): void
    {
        [, $reporter] = $this->actors();
        $reply = 'PHASE_A_FORCE_ROLLBACK ' . uniqid('', true);
        $bug = $this->newBug($reporter);

        config(['database.connections.phase_a_observer' => config('database.connections.' . config('database.default'))]);
        DB::purge('phase_a_observer');
        $observer = DB::connection('phase_a_observer');

        $this->installForceRollbackTrigger();
        try {
            $rolled = $this->runExtractedTinker([
                'BUG_ID' => (string) $bug->id,
                'GITHUB_ISSUE_URL' => self::ISSUE,
                'DISPOSITION' => 'bug',
                'PUBLIC_REPLY' => $reply,
                'CLOSE_AS_LOGGED' => 'false',
            ]);
            $this->assertNull($rolled['receipt'], 'forced post-write failure must not leave a success receipt');
            $this->assertStringContainsString('PHASE_A_FAIL code=', $rolled['stderr'] . $rolled['stdout']);

            // Independent connection: rolled-back status/comment must be invisible.
            $this->assertSame('new', (string) $observer->table('bug_reports')->where('id', $bug->id)->value('status'));
            $this->assertSame(
                0,
                (int) $observer->table('bug_report_comments')->where('bug_report_id', $bug->id)->count()
            );
            $this->assertSame(
                0,
                (int) $observer->table('bug_report_status_logs')->where('bug_report_id', $bug->id)->count()
            );
            $this->assertSame('new', $bug->fresh()->status);
        } finally {
            $this->dropForceRollbackTrigger();
        }

        $first = $this->runExtractedTinker([
            'BUG_ID' => (string) $bug->id,
            'GITHUB_ISSUE_URL' => self::ISSUE,
            'DISPOSITION' => 'bug',
            'PUBLIC_REPLY' => $reply,
            'CLOSE_AS_LOGGED' => 'false',
        ]);
        $this->assertNotNull($first['receipt'], $first['stderr'] . "\n" . $first['stdout']);
        $this->assertFalse((bool) $first['receipt']['comment_skipped']);
        $this->assertSame('triaged', $first['receipt']['final']);
        $this->assertSame('triaged', (string) $observer->table('bug_reports')->where('id', $bug->id)->value('status'));
        $commentId = (int) $first['receipt']['comment_id'];
        $this->assertGreaterThan(0, $commentId);
        $this->assertSame(1, BugReportComment::where('bug_report_id', $bug->id)->where('is_internal_note', false)->count());
        $this->assertSame($reply, (string) BugReportComment::find($commentId)->body);

        BugReportComment::create([
            'bug_report_id' => $bug->id,
            'author_user_id' => $reporter->id,
            'body' => 'reporter follow-up after phase-a ' . uniqid('', true),
            'is_internal_note' => false,
            'created_at' => now(),
        ]);
        $publicBeforeRetry = BugReportComment::where('bug_report_id', $bug->id)->where('is_internal_note', false)->count();
        $this->assertSame(2, $publicBeforeRetry);

        $retry = $this->runExtractedTinker([
            'BUG_ID' => (string) $bug->id,
            'GITHUB_ISSUE_URL' => self::ISSUE,
            'DISPOSITION' => 'bug',
            'PUBLIC_REPLY' => $reply,
            'CLOSE_AS_LOGGED' => 'false',
        ]);
        $this->assertNotNull($retry['receipt'], $retry['stderr'] . "\n" . $retry['stdout']);
        $this->assertTrue((bool) $retry['receipt']['comment_skipped']);
        $this->assertSame($commentId, (int) $retry['receipt']['comment_id']);
        $this->assertSame(hash('sha256', $reply), $retry['receipt']['public_body_sha256']);
        $this->assertSame(
            $publicBeforeRetry,
            BugReportComment::where('bug_report_id', $bug->id)->where('is_internal_note', false)->count(),
            'retry must not insert a second approved body'
        );

        DB::purge('phase_a_observer');
    }

    /**
     * Test-DB-only trigger: fires on public-comment INSERT after changeStatus
     * has already mutated rows inside the same tinker transaction.
     */
    private function installForceRollbackTrigger(): void
    {
        $this->dropForceRollbackTrigger();
        DB::unprepared(
            'CREATE TRIGGER `' . self::FORCE_ROLLBACK_TRIGGER . '`
            BEFORE INSERT ON `bug_report_comments`
            FOR EACH ROW
            BEGIN
              IF NEW.`body` LIKE \'PHASE_A_FORCE_ROLLBACK%\' THEN
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'phase_a_test_forced_post_write_rollback\';
              END IF;
            END'
        );
    }

    private function dropForceRollbackTrigger(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `' . self::FORCE_ROLLBACK_TRIGGER . '`');
    }

    /**
     * Extract the REMOTE heredoc from the workflow YAML, strip the 10-space
     * YAML indent, then take the exact `tinker --execute='…'` PHP payload.
     */
    private static function extractTinkerPhpFromWorkflow(): string
    {
        $path = dirname(base_path()) . '/.github/workflows/bug-phase-a-triage.yml';
        $src = file_get_contents($path);
        if (!is_string($src) || $src === '') {
            throw new \RuntimeException('unable to read bug-phase-a-triage.yml');
        }
        $marker = "cat > /tmp/phase-a-remote.sh <<'REMOTE'\n";
        $start = strpos($src, $marker);
        if ($start === false) {
            throw new \RuntimeException('REMOTE heredoc marker not found in bug-phase-a-triage.yml');
        }
        $start += strlen($marker);
        $end = strpos($src, "\n          REMOTE\n", $start);
        if ($end === false) {
            throw new \RuntimeException('REMOTE heredoc terminator not found');
        }
        $remote = substr($src, $start, $end - $start);
        $stripped = [];
        foreach (explode("\n", $remote) as $line) {
            $stripped[] = str_starts_with($line, '          ') ? substr($line, 10) : $line;
        }
        $remote = implode("\n", $stripped);
        if (!preg_match("/php artisan tinker --execute='(.*)' 2>&1\\)\"/s", $remote, $m)) {
            throw new \RuntimeException('tinker --execute PHP block not found inside REMOTE script');
        }

        return $m[1];
    }

    /**
     * @param  array{BUG_ID:string,GITHUB_ISSUE_URL:string,DISPOSITION:string,PUBLIC_REPLY:string,CLOSE_AS_LOGGED?:string}  $vars
     * @return array{exit:int|null,stdout:string,stderr:string,receipt:?array<string,mixed>,receipt_raw:?string}
     */
    private function runExtractedTinker(array $vars): array
    {
        $receiptPath = sys_get_temp_dir() . '/phase-a-receipt-int-' . uniqid('', true) . '.json';
        $phpPath = sys_get_temp_dir() . '/phase-a-tinker-int-' . uniqid('', true) . '.php';
        @unlink($receiptPath);
        file_put_contents($phpPath, $this->tinkerPhp);

        // Force CI/phpunit DB target into the artisan child — never inherit a host .env AllTrue.
        $exports = [
            'BUG_ID' => $vars['BUG_ID'],
            'GITHUB_ISSUE_URL' => $vars['GITHUB_ISSUE_URL'],
            'DISPOSITION' => $vars['DISPOSITION'],
            'PUBLIC_REPLY' => $vars['PUBLIC_REPLY'],
            'CLOSE_AS_LOGGED' => $vars['CLOSE_AS_LOGGED'] ?? 'false',
            'RECEIPT_FILE' => $receiptPath,
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) (getenv('DB_HOST') ?: '127.0.0.1'),
            'DB_PORT' => (string) (getenv('DB_PORT') ?: '3306'),
            'DB_DATABASE' => (string) (getenv('DB_DATABASE') ?: 'AllTrue_test'),
            'DB_USERNAME' => (string) (getenv('DB_USERNAME') ?: 'admin'),
            'DB_PASSWORD' => (string) (getenv('DB_PASSWORD') ?: 'ci-ephemeral'),
        ];
        $this->assertSame('AllTrue_test', $exports['DB_DATABASE'], 'refusing to run Phase-A tinker against a non-test database');

        $prefix = '';
        foreach ($exports as $key => $value) {
            $prefix .= $key . '=' . escapeshellarg($value) . ' ';
        }
        $cmd = $prefix . 'php artisan tinker --execute="$(cat ' . escapeshellarg($phpPath) . ')"';
        $process = Process::fromShellCommandline($cmd, base_path());
        $process->setTimeout(180);
        $process->run();

        $raw = is_file($receiptPath) ? file_get_contents($receiptPath) : null;
        @unlink($receiptPath);
        @unlink($phpPath);

        $decoded = null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $decoded = null;
            }
        }

        return [
            'exit' => $process->getExitCode(),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'receipt' => $decoded,
            'receipt_raw' => $raw,
        ];
    }

    /** @return array{0: User, 1: User} */
    private function actors(): array
    {
        $admin = User::create([
            'LoginName' => uniqid('pha-admin-', true),
            'Name' => 'PhaseA Admin',
            'PSW' => 'fixture',
            'type' => 'S',
            'phone' => (string) random_int(900000000, 999999999),
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $admin->id, 'Admin' => 1, 'Approved' => 1]);
        $reporter = User::create([
            'LoginName' => uniqid('pha-rep-', true),
            'Name' => 'PhaseA Reporter',
            'PSW' => 'fixture',
            'type' => 'T',
            'phone' => (string) random_int(900000000, 999999999),
        ]);
        $this->cleanupUserIds[] = (int) $admin->id;
        $this->cleanupUserIds[] = (int) $reporter->id;

        return [$admin, $reporter];
    }

    private function newBug(User $reporter): BugReport
    {
        $bug = BugReport::create([
            'CampusID' => 1,
            'reporter_user_id' => $reporter->id,
            'title' => 'Phase-A tinker integration',
            'description' => 'synthetic fixture — no production PII',
            'severity' => 'medium',
            'status' => 'new',
            'page_key' => 'calendar',
        ]);
        $this->cleanupBugIds[] = (int) $bug->id;

        return $bug;
    }
}
