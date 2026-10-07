<?php

namespace Tests\Feature;

use App\Console\Commands\BugAutoIntakeCommand;
use App\Models\BugReport;
use App\Models\BugReportComment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BugAutoIntakeCommandTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUE = 'https://github.com/o/r/issues/4001';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_list_returns_only_settled_new_reports_without_free_text(): void
    {
        $reporter = $this->users()[1];
        $old = $this->bug($reporter, 'new', now()->subHour()->toDateTimeString(), '王小明的課表錯了');
        $fresh = $this->bug($reporter, 'new', now()->subMinutes(2)->toDateTimeString());
        $triaged = $this->bug($reporter, 'triaged', now()->subHours(2)->toDateTimeString());

        $this->assertSame(0, Artisan::call('bugs:auto-intake', ['--candidates' => true, '--min-age' => 5]));
        $out = Artisan::output();
        $ids = array_column(json_decode(trim($out), true)['candidates'], 'bug_id');

        $this->assertSame([$old->id], $ids);
        $this->assertNotContains($fresh->id, $ids);
        $this->assertNotContains($triaged->id, $ids);
        $this->assertStringNotContainsString('王小明', $out);
    }

    public function test_ack_triages_links_issue_and_posts_ack_once(): void
    {
        [, $reporter] = $this->users();
        $bug = $this->bug($reporter, 'new', '2026-10-07 09:00:00');

        $this->assertSame(0, Artisan::call('bugs:auto-intake', ['--ack' => true, '--bug-id' => $bug->id, '--issue-url' => self::ISSUE]));
        $this->assertSame('triaged', $bug->fresh()->status);
        $comments = BugReportComment::where('bug_report_id', $bug->id)->get();
        $this->assertCount(1, $comments);
        $this->assertStringStartsWith(BugAutoIntakeCommand::ACK_TEXT, $comments[0]->body);
        $this->assertStringContainsString(self::ISSUE, $comments[0]->body);
        $this->assertFalse((bool) $comments[0]->is_internal_note);

        // Re-run (next hour or a retried job): skipped, no second ack.
        $this->assertSame(0, Artisan::call('bugs:auto-intake', ['--ack' => true, '--bug-id' => $bug->id, '--issue-url' => self::ISSUE]));
        $this->assertStringContainsString('"skipped":"status_triaged"', Artisan::output());
        $this->assertSame(1, BugReportComment::where('bug_report_id', $bug->id)->count());
    }

    public function test_ack_refuses_bad_input(): void
    {
        [, $reporter] = $this->users();
        $bug = $this->bug($reporter, 'new', '2026-10-07 09:00:00');

        $this->assertSame(1, Artisan::call('bugs:auto-intake', ['--ack' => true, '--bug-id' => $bug->id, '--issue-url' => 'https://evil.example/issues/1']));
        $this->assertSame(1, Artisan::call('bugs:auto-intake', ['--ack' => true, '--bug-id' => 999999, '--issue-url' => self::ISSUE]));
        $this->assertSame('new', $bug->fresh()->status);
        $this->assertSame(0, BugReportComment::where('bug_report_id', $bug->id)->count());
    }

    /** @return array{0: User, 1: User} */
    private function users(): array
    {
        $admin = User::create(['LoginName' => 'intakeS@test.com', 'Name' => 'S', 'PSW' => 'x', 'type' => 'S', 'phone' => '0900000101']);
        $reporter = User::create(['LoginName' => 'intakeT@test.com', 'Name' => 'T', 'PSW' => 'x', 'type' => 'T', 'phone' => '0900000102']);
        return [$admin, $reporter];
    }

    private function bug(User $reporter, string $status, string $createdAt, string $title = 'x'): BugReport
    {
        $bug = BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $reporter->id, 'title' => $title,
            'description' => $title, 'severity' => 'medium', 'status' => $status, 'page_key' => 'calendar',
        ]);
        $bug->forceFill(['created_at' => Carbon::parse($createdAt)])->save();
        return $bug;
    }
}
