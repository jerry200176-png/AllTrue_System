<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\BugReport;
use App\Models\BugReportAttachment;
use App\Models\User;
use App\Models\UserCampus;
use App\Providers\AppServiceProvider;
use App\Services\BugReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 意見與建議 anti-abuse: per-user limits with one Chinese answer, and a 10-minute soft dedupe. */
class BugReportAbuseLimitsTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $login, string $type = 'T'): array
    {
        $user = User::create(['LoginName' => $login, 'Name' => $login, 'PSW' => 'secret', 'type' => $type, 'phone' => rand(900000000, 999999999)]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 0, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return [['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'], $user];
    }

    private function report(array $h, int $n)
    {
        return $this->withHeaders($h)->postJson('/api/v1/bugs', ['title' => "t{$n}", 'description' => "d{$n}", 'branch_id' => 1]);
    }

    public function test_sixth_report_in_an_hour_gets_the_chinese_429(): void
    {
        [$h] = $this->actor('a@x');
        foreach (range(1, 5) as $n) $this->report($h, $n)->assertCreated();
        $res = $this->report($h, 6)->assertStatus(429);
        $this->assertSame(AppServiceProvider::BUG_LIMIT_MESSAGE, $res->json('message'));
        $this->assertSame('回報有點多，請稍後再送；急事請直接找主任。', $res->json('message'));
        $this->assertSame(5, BugReport::count());
    }

    public function test_the_limit_is_per_user_not_shared(): void
    {
        [$a] = $this->actor('a@x');
        [$b] = $this->actor('b@x');
        foreach (range(1, 5) as $n) $this->report($a, $n)->assertCreated();
        $this->report($a, 6)->assertStatus(429);
        $this->report($b, 1)->assertCreated();
    }

    public function test_twenty_per_day_holds_when_the_hour_window_has_rolled(): void
    {
        [$h] = $this->actor('a@x');
        $start = Carbon::parse('2026-11-07 00:00:00');
        $n = 0;
        foreach (range(0, 3) as $hour) { // 4 hours x 5 = 20
            Carbon::setTestNow($start->copy()->addHours($hour)->addMinute());
            foreach (range(1, 5) as $_) $this->report($h, ++$n)->assertCreated();
        }
        Carbon::setTestNow($start->copy()->addHours(4)->addMinute());
        $res = $this->report($h, ++$n)->assertStatus(429);
        $this->assertSame(AppServiceProvider::BUG_LIMIT_MESSAGE, $res->json('message'));
        Carbon::setTestNow();
    }

    public function test_comments_and_reporter_verify_are_limited_to_thirty_an_hour(): void
    {
        [$h] = $this->actor('a@x');
        $id = (int) $this->report($h, 1)->json('id');
        foreach (range(1, 30) as $n) $this->withHeaders($h)->postJson("/api/v1/bugs/{$id}/comments", ['body' => "c{$n}"])->assertSuccessful();
        $res = $this->withHeaders($h)->postJson("/api/v1/bugs/{$id}/comments", ['body' => 'c31'])->assertStatus(429);
        $this->assertSame(AppServiceProvider::BUG_LIMIT_MESSAGE, $res->json('message'));
        $this->withHeaders($h)->postJson("/api/v1/bugs/{$id}/reporter-verify", [])->assertStatus(429); // shares the same hourly budget
    }

    public function test_identical_report_within_ten_minutes_returns_the_existing_one(): void
    {
        [$h] = $this->actor('a@x');
        $first = $this->report($h, 1)->assertCreated();
        $again = $this->report($h, 1)->assertOk();
        $this->assertSame($first->json('id'), $again->json('id'));
        $this->assertTrue($again->json('duplicate'));
        $this->assertSame(1, BugReport::count());
        Carbon::setTestNow(now()->addMinutes(11));
        $this->report($h, 1)->assertCreated(); // after ten minutes it is a new report
        $this->assertSame(2, BugReport::count());
        Carbon::setTestNow();
    }

    public function test_dedupe_is_per_reporter_and_needs_the_same_text(): void
    {
        [$a] = $this->actor('a@x');
        [$b] = $this->actor('b@x');
        $this->report($a, 1)->assertCreated();
        $this->report($b, 1)->assertCreated(); // another user's identical text is a separate report
        $this->withHeaders($a)->postJson('/api/v1/bugs', ['title' => 't1', 'description' => 'different', 'branch_id' => 1])->assertCreated();
        $this->assertSame(3, BugReport::count());
    }

    public function test_retry_keeps_screenshots_when_the_first_save_had_none(): void
    {
        Storage::fake('public');
        [$h] = $this->actor('a@x');
        $first = $this->report($h, 1)->assertCreated(); // row saved, screenshot storage failed
        $send = fn () => $this->withHeaders($h)->post('/api/v1/bugs', ['title' => 't1', 'description' => 'd1', 'branch_id' => 1,
            'attachments' => [UploadedFile::fake()->image('screen.png', 80, 60)]]);
        $retry = $send()->assertOk();
        $this->assertSame($first->json('id'), $retry->json('id'));
        $this->assertSame(0, $retry->json('attachment_errors'));
        $this->assertDatabaseCount('bug_report_attachments', 1);
        $send()->assertOk(); // a double tap with the same screenshot is not stored twice
        $this->assertDatabaseCount('bug_report_attachments', 1);
        $this->assertSame(1, BugReport::count());
    }

    public function test_submit_releases_the_per_reporter_lock(): void
    {
        [$h, $user] = $this->actor('a@x');
        $this->report($h, 1)->assertCreated();
        $this->report($h, 1)->assertOk();
        $lock = Cache::lock("bug-submit:{$user->id}", 5);
        $this->assertTrue($lock->get(), 'each submit must release its lock, or the next one waits and gets a 429');
        $lock->release();
    }

    public function test_5090_stale_retry_cannot_append_to_existing_attachments_or_delete_their_file(): void
    {
        Storage::fake('public');
        [$h] = $this->actor('attachment-existing@x');
        $id = $this->report($h, 1)->json('id');
        $bug = BugReport::findOrFail($id);
        $file = UploadedFile::fake()->create('accepted.png', 1, 'image/png');
        $this->assertSame(0, BugReportService::attachUploadedFiles($bug, [$file]));
        $accepted = $bug->attachments()->firstOrFail()->stored_path;
        $this->assertSame(0, BugReportService::attachUploadedFiles($bug, [$file]));
        $this->assertSame(1, $bug->attachments()->count(), 'repeat the authoritative empty-report check inside the lock');
        Storage::disk('public')->assertExists($accepted);
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_5090_metadata_rollback_cleans_owned_files_and_leaves_retry_possible(): void
    {
        Storage::fake('public');
        [$h] = $this->actor('attachment-rollback@x');
        $bug = BugReport::findOrFail($this->report($h, 1)->json('id'));
        $attempt = 0;
        Event::listen('eloquent.creating: '.BugReportAttachment::class, function () use (&$attempt) {
            if (++$attempt === 2) throw new \RuntimeException('synthetic metadata failure');
        });
        try {
            $errors = BugReportService::attachUploadedFiles($bug, [
                UploadedFile::fake()->create('first.png', 1, 'image/png'),
                UploadedFile::fake()->create('second.png', 1, 'image/png'),
            ]);
        } finally {
            Event::forget('eloquent.creating: '.BugReportAttachment::class);
        }
        $this->assertSame(2, $errors);
        $this->assertSame(0, $bug->attachments()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(0, BugReportService::attachUploadedFiles($bug, [UploadedFile::fake()->create('retry.png', 1, 'image/png')]));
        $this->assertSame(1, $bug->attachments()->count());
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_5090_partial_storage_failure_cleans_its_file_and_keeps_successful_evidence(): void
    {
        Storage::fake('public');
        [$h] = $this->actor('attachment-storage@x');
        $bug = BugReport::findOrFail($this->report($h, 1)->json('id'));
        $temp = tempnam(sys_get_temp_dir(), 'bug-upload-');
        file_put_contents($temp, 'synthetic');
        $broken = new class($temp, 'broken.png', 'image/png', null, true) extends UploadedFile {
            public function store($path = '', $options = [])
            {
                Storage::disk('public')->put($path.'/'.$this->hashName(), 'partial write');
                throw new \RuntimeException('synthetic storage failure after partial write');
            }
        };
        try {
            $this->assertSame(1, BugReportService::attachUploadedFiles($bug, [
                $broken, UploadedFile::fake()->create('kept.png', 1, 'image/png'),
            ]));
            $this->assertSame(1, $bug->attachments()->count());
            $this->assertSame('kept.png', $bug->attachments()->firstOrFail()->original_name);
            $this->assertCount(1, Storage::disk('public')->allFiles());
        } finally {
            @unlink($temp);
        }
    }
}
