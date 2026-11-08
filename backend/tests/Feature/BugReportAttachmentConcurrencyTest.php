<?php

namespace Tests\Feature;

use App\Models\BugReport;
use App\Models\BugReportAttachment;
use App\Models\Campus;
use App\Services\BugReportService;
use Carbon\Carbon;
use Illuminate\Cache\FileStore;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
}
