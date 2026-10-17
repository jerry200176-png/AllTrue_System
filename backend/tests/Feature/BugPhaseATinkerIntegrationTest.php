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
        $this->assertStringContainsString('phase_a_receipt', $this->tinkerPhp);
        $this->assertStringContainsString('hash("sha256"', $this->tinkerPhp);
        $this->assertStringNotContainsString('->limit(100)', $this->tinkerPhp);
    }

    protected function tearDown(): void
    {
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

    public function test_oldest_exact_hash_among_over_100_public_comments_hits_without_duplicate(): void
    {
        [$admin, $reporter] = $this->actors();
        $reply = 'phase-a-oldest-hash-target-' . uniqid('', true);
        $bug = $this->newBug($reporter);
        $rows = [];
        for ($i = 1; $i <= 101; $i++) {
            $rows[] = [
                'bug_report_id' => $bug->id,
                'author_user_id' => $reporter->id,
                'body' => $i === 1 ? $reply : "filler-public-{$i}-{$bug->id}",
                'is_internal_note' => 0,
                'created_at' => now()->toDateTimeString(),
            ];
        }
        BugReportComment::insert($rows);
        $oldestId = (int) BugReportComment::query()
            ->where('bug_report_id', $bug->id)
            ->orderBy('id')
            ->value('id');
        $newest100 = BugReportComment::query()
            ->where('bug_report_id', $bug->id)
            ->orderByDesc('id')
            ->limit(100)
            ->pluck('id');
        $this->assertFalse($newest100->contains($oldestId), 'fixture: newest-100 must miss the oldest row');

        $result = $this->runExtractedTinker([
            'BUG_ID' => (string) $bug->id,
            'GITHUB_ISSUE_URL' => self::ISSUE,
            'DISPOSITION' => 'bug',
            'PUBLIC_REPLY' => $reply,
            'CLOSE_AS_LOGGED' => 'false',
        ]);

        $this->assertNotNull($result['receipt'], $result['stderr'] . "\n" . $result['stdout']);
        $this->assertSame(1, (int) $result['receipt']['phase_a_receipt']);
        $this->assertSame($bug->id, (int) $result['receipt']['bug_id']);
        $this->assertSame($oldestId, (int) $result['receipt']['comment_id']);
        $this->assertTrue((bool) $result['receipt']['comment_skipped']);
        $this->assertSame('triaged', $result['receipt']['final']);
        $this->assertSame(hash('sha256', $reply), $result['receipt']['public_body_sha256']);
        $this->assertSame(101, BugReportComment::where('bug_report_id', $bug->id)->where('is_internal_note', false)->count());
        $this->assertSame('triaged', $bug->fresh()->status);
        $this->assertSame($admin->id, (int) UserCampus::where('Admin', 1)->where('Approved', 1)->orderBy('UserID')->value('UserID'));
    }

    public function test_case_and_accent_byte_differences_do_not_sha256_collide(): void
    {
        [, $reporter] = $this->actors();
        // Accent pair: hashes always differ; may or may not equate under unicode_ci.
        $this->assertNotSame(hash('sha256', 'cafe'), hash('sha256', 'café'));
        // Prefer accent near-miss when the live collation equates them; else case-only.
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

    public function test_rollback_skips_receipt_then_commit_receipt_and_retry_dedupes(): void
    {
        [, $reporter] = $this->actors();
        $reply = "phase-a-commit-retry\nline2-" . uniqid('', true);
        $bug = $this->newBug($reporter);

        $rolled = $this->runExtractedTinker([
            'BUG_ID' => (string) $bug->id,
            'GITHUB_ISSUE_URL' => self::ISSUE,
            'DISPOSITION' => 'not_a_real_disposition',
            'PUBLIC_REPLY' => $reply,
            'CLOSE_AS_LOGGED' => 'false',
        ]);
        $this->assertNull($rolled['receipt'], 'invalid disposition must not write a success receipt');
        $this->assertSame('new', $bug->fresh()->status);
        $this->assertSame(0, BugReportComment::where('bug_report_id', $bug->id)->count());
        $this->assertStringContainsString('PHASE_A_FAIL code=', $rolled['stderr'] . $rolled['stdout']);

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
        $this->assertSame('triaged', $bug->fresh()->status);
        $commentId = (int) $first['receipt']['comment_id'];
        $this->assertGreaterThan(0, $commentId);
        $this->assertSame(1, BugReportComment::where('bug_report_id', $bug->id)->where('is_internal_note', false)->count());
        $this->assertSame($reply, (string) BugReportComment::find($commentId)->body);

        // Newer reporter follow-up must not become the retry match key (hash, not latest).
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
