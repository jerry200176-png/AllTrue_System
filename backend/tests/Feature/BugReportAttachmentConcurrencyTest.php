<?php

namespace Tests\Feature;

use App\Models\BugReport;
use App\Models\BugReportAttachment;
use App\Models\Campus;
use App\Services\BugReportService;
use Carbon\Carbon;
use Illuminate\Cache\FileStore;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PaymentReportRaceWorker;
use Tests\TestCase;

class BugReportAttachmentConcurrencyTest extends TestCase
{
    use PaymentReportRaceWorker;

    protected function tearDown(): void
    {
        $this->raceTerminateAllWorkers();
        $this->raceCleanupFiles();
        Carbon::setTestNow();
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_5090_expired_file_lease_cannot_bypass_the_real_parent_row_attachment_cap(): void
    {
        $this->raceAssertMysqlRr();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $campus = Campus::factory()->create();
        $bug = BugReport::create(['CampusID' => $campus->id, 'reporter_user_id' => 900001,
            'title' => 'synthetic attachment race', 'description' => 'synthetic', 'severity' => 'low', 'status' => 'new']);
        $root = storage_path('framework/testing/bug-cap-'.Str::uuid());
        $disk = Storage::build(['driver' => 'local', 'root' => $root.'/files', 'throw' => true]);
        $pause = $this->racePause('');
        $beforeAdmission = $this->raceFile();
        $clock = Carbon::parse('2026-11-08 12:00:00');
        $payload = ['bug_id' => $bug->id, 'storage_root' => $root.'/files', 'cache_root' => $root.'/cache',
            'lock_name' => 'bug-submit:synthetic-'.$bug->id];
        try {
            $a = $this->raceSpawn(base_path('tests/Support/bug-attachment-race-worker.php'), $payload + [
                'worker' => 'A', 'clock' => $clock->toIso8601String(), 'pause' => $pause,
            ]);
            $this->raceAwait($pause['ready']); // A pauses immediately before its first real metadata INSERT.
            Carbon::setTestNow($clock->copy()->addSeconds(59));
            $lease = (new FileStore(app('files'), $root.'/cache'))->lock($payload['lock_name'], 60);
            $this->assertFalse($lease->get(), 'real FileLock still excludes peers before lease expiry');
            Carbon::setTestNow();
            $b = $this->raceSpawn(base_path('tests/Support/bug-attachment-race-worker.php'), $payload + [
                'worker' => 'B', 'clock' => $clock->copy()->addSeconds(61)->toIso8601String(),
                'before_admission' => $beforeAdmission,
            ]);
            $this->raceAwait($beforeAdmission);
            usleep(200000); // B has entered real SQL; A still holds the parent row, not just an expiring lease.
            $beforeCommit = $bug->attachments()->count();
            touch($pause['release']);
            $first = $this->raceFinish($a);
            $late = $this->raceFinish($b);
            $this->assertSame(5, $bug->attachments()->count(), 'expired lease must never allow ten attachments');
            $this->assertSame(0, $beforeCommit, 'the second connection must wait for A\'s uncommitted parent row');
            $this->assertTrue($first['lease_acquired']);
            $this->assertTrue($late['lease_acquired'], 'B really passed the expired FileLock before SQL serialization');
            $this->assertSame(0, $first['attachment_errors']);
            $this->assertSame(0, $late['attachment_errors']);
            $this->assertSame(5, count($disk->allFiles()), 'losing worker removes only its own staged files');
            $this->assertTrue($bug->attachments()->pluck('original_name')->every(fn ($name) => str_starts_with($name, 'A-')));
            foreach ($bug->attachments()->pluck('stored_path') as $path) $this->assertTrue($disk->exists($path));
        } finally {
            touch($pause['release']);
            $this->raceTerminateAllWorkers();
            app('files')->deleteDirectory($root);
        }
    }

    public function test_5090_after_commit_exception_cannot_delete_admitted_evidence(): void
    {
        $this->raceAssertMysqlRr();
        $this->artisan('migrate:fresh', ['--force' => true]);
        Storage::fake('public');
        $campus = Campus::factory()->create();
        $bug = BugReport::create(['CampusID' => $campus->id, 'reporter_user_id' => 900002,
            'title' => 'synthetic commit acknowledgement', 'description' => 'synthetic', 'severity' => 'low', 'status' => 'new']);
        Event::listen('eloquent.created: '.BugReportAttachment::class, function () {
            DB::afterCommit(fn () => throw new \RuntimeException('synthetic post-commit failure'));
        });
        try {
            BugReportService::attachUploadedFiles($bug, [UploadedFile::fake()->create('accepted.png', 1, 'image/png')]);
        } finally {
            Event::forget('eloquent.created: '.BugReportAttachment::class);
        }
        $this->assertSame(1, $bug->attachments()->count(), 'SQL really committed before the exception');
        Storage::disk('public')->assertExists($bug->attachments()->firstOrFail()->stored_path);
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public static function settlementOutcomes(): array
    {
        return ['settles before deadline' => [false], 'barrier times out' => [true]];
    }

    #[DataProvider('settlementOutcomes')]
    public function test_5090_cleanup_waits_for_a_pending_admission_before_deciding_files_are_unreferenced(bool $timeout): void
    {
        $this->raceAssertMysqlRr();
        $this->artisan('migrate:fresh', ['--force' => true]);
        Storage::fake('public');
        $campus = Campus::factory()->create();
        $bug = BugReport::create(['CampusID' => $campus->id, 'reporter_user_id' => 900003,
            'title' => 'synthetic pending commit', 'description' => 'synthetic', 'severity' => 'low', 'status' => 'new']);
        $attempt = $this->raceFile();
        $ready = $this->raceFile();
        $release = $this->raceFile();
        if ($timeout) DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        $metadata = null;
        $holder = null;
        Event::listen('eloquent.creating: '.BugReportAttachment::class, function ($attachment) use (&$metadata) {
            $metadata = $attachment->getAttributes();
            throw new \RuntimeException('synthetic uncertain admission outcome');
        });
        // Test-only handoff: another real server transaction owns parent+metadata
        // while the interrupted caller starts compensation, before that txn settles.
        Event::listen(TransactionRolledBack::class, function () use ($bug, $attempt, $ready, $release, $timeout, &$metadata, &$holder) {
            if ($metadata === null || $holder !== null) return;
            $holder = $this->raceSpawn(base_path('tests/Support/bug-attachment-race-worker.php'), [
                'mode' => 'pending-admission', 'bug_id' => $bug->id, 'attachment' => $metadata,
                'storage_root' => Storage::disk('public')->path(''), 'cache_root' => storage_path('framework/testing/unused-cache'),
                'clock' => '2026-11-08T12:00:00+08:00', 'ready' => $ready, 'cleanup_attempt' => $attempt,
                'hold_until' => $timeout ? $release : null,
            ]);
            $this->raceAwait($ready);
        });
        DB::connection()->beforeExecuting(function ($sql) use (&$holder, $attempt) {
            if ($holder !== null && str_contains(strtolower($sql), 'select')
                && (str_contains($sql, 'bug_reports') || str_contains($sql, 'bug_report_attachments'))) touch($attempt);
        });
        try {
            $errors = BugReportService::attachUploadedFiles($bug, [UploadedFile::fake()->create('pending.png', 1, 'image/png')]);
            $this->assertNotNull($holder);
            $this->assertSame(1, $errors);
            if ($timeout) {
                $this->assertSame(0, $bug->attachments()->count(), 'peer admission is still pending after the barrier timeout');
                Storage::disk('public')->assertExists($metadata['stored_path']);
            }
            touch($release);
            $result = $this->raceFinish($holder);
            $this->assertTrue($result['retained_before_commit'], 'cleanup cannot delete while admission is still pending');
            $this->assertSame(1, $bug->attachments()->count());
            Storage::disk('public')->assertExists($bug->attachments()->firstOrFail()->stored_path);
        } finally {
            touch($attempt);
            touch($release);
            Event::forget('eloquent.creating: '.BugReportAttachment::class);
            Event::forget(TransactionRolledBack::class);
            $this->raceTerminateAllWorkers();
        }
    }

    public function test_5090_cleanup_uses_current_reads_even_inside_a_caller_with_an_old_rr_snapshot(): void
    {
        $this->raceAssertMysqlRr();
        $this->artisan('migrate:fresh', ['--force' => true]);
        Storage::fake('public');
        $campus = Campus::factory()->create();
        $bug = BugReport::create(['CampusID' => $campus->id, 'reporter_user_id' => 900004,
            'title' => 'synthetic old read view', 'description' => 'synthetic', 'severity' => 'low', 'status' => 'new']);
        $attempt = $this->raceFile();
        $ready = $this->raceFile();
        touch($attempt);
        $injected = false;
        $oldSnapshotCount = null;
        DB::beginTransaction();
        $this->assertSame(0, $bug->attachments()->count()); // Pin the caller's ordinary RR read view.
        DB::connection()->beforeExecuting(function ($sql) use ($bug, $attempt, $ready, &$injected, &$oldSnapshotCount) {
            if ($injected || !str_contains(strtolower($sql), 'for update') || !str_contains($sql, 'bug_reports')) return;
            $injected = true;
            $paths = Storage::disk('public')->allFiles();
            $holder = $this->raceSpawn(base_path('tests/Support/bug-attachment-race-worker.php'), [
                'mode' => 'pending-admission', 'bug_id' => $bug->id,
                'attachment' => ['bug_report_id' => $bug->id, 'stored_path' => $paths[0], 'original_name' => 'settled.png'],
                'storage_root' => Storage::disk('public')->path(''), 'cache_root' => storage_path('framework/testing/unused-cache'),
                'clock' => '2026-11-08T12:00:00+08:00', 'ready' => $ready, 'cleanup_attempt' => $attempt,
            ]);
            $this->raceFinish($holder); // This different connection actually commits before compensation.
            $oldSnapshotCount = $bug->attachments()->count();
            throw new \RuntimeException('synthetic interruption before admission lock');
        });
        try {
            BugReportService::attachUploadedFiles($bug, [UploadedFile::fake()->create('settled.png', 1, 'image/png')]);
            $this->assertTrue($injected);
            $this->assertSame(0, $oldSnapshotCount, 'ordinary writer SELECT really still sees the old RR snapshot');
            $current = $bug->attachments()->lockForUpdate()->firstOrFail();
            Storage::disk('public')->assertExists($current->stored_path);
        } finally {
            DB::rollBack();
            $this->raceTerminateAllWorkers();
        }
    }
}
