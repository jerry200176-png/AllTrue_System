<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\BugReport;
use App\Models\User;
use App\Models\UserCampus;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
}
