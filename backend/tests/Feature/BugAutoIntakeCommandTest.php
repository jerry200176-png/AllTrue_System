<?php

namespace Tests\Feature;

use App\Console\Commands\BugAutoIntakeCommand;
use App\Models\BugReport;
use App\Models\BugReportComment;
use App\Models\User;
use App\Services\BugReportService;
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
        // Exact ack text, no issue URL: Phase-A dedupes its own reply by the URL and must not be fooled.
        $this->assertSame(BugAutoIntakeCommand::ACK_TEXT, $comments[0]->body);
        $this->assertNull(BugReportService::latestDispositionWithKind($bug->id), 'intake must not invent a disposition');
        $this->assertFalse((bool) $comments[0]->is_internal_note);

        // Re-run (next hour or a retried job): skipped, no second ack.
        $this->assertSame(0, Artisan::call('bugs:auto-intake', ['--ack' => true, '--bug-id' => $bug->id, '--issue-url' => self::ISSUE]));
        $this->assertStringContainsString('"ack":"skipped"', Artisan::output());
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

    public function test_retry_completes_missing_ack_and_skips_reports_triaged_by_people(): void
    {
        [$admin, $reporter] = $this->users();
        $half = $this->bug($reporter, 'new', '2026-10-07 09:00:00');
        BugReportService::changeStatus($half->id, $admin->id, 'triaged', BugAutoIntakeCommand::INTAKE_NOTE . self::ISSUE);
        $this->assertSame(0, Artisan::call('bugs:auto-intake', ['--ack' => true, '--bug-id' => $half->id, '--issue-url' => self::ISSUE]));
        $this->assertSame(1, BugReportComment::where('bug_report_id', $half->id)->where('body', BugAutoIntakeCommand::ACK_TEXT)->count());

        $human = $this->bug($reporter, 'new', '2026-10-07 09:00:00');
        BugReportService::changeStatus($human->id, $admin->id, 'triaged', 'manual');
        $this->assertSame(0, Artisan::call('bugs:auto-intake', ['--ack' => true, '--bug-id' => $human->id, '--issue-url' => self::ISSUE]));
        $this->assertSame(0, BugReportComment::where('bug_report_id', $human->id)->count());
    }

    public function test_phase_a_can_restate_disposition_after_auto_intake(): void
    {
        [$admin, $reporter] = $this->users();
        $bug = $this->bug($reporter, 'new', '2026-10-07 09:00:00');
        Artisan::call('bugs:auto-intake', ['--ack' => true, '--bug-id' => $bug->id, '--issue-url' => self::ISSUE]);

        $opts = ['disposition' => 'needs_info', 'github_issue_url' => self::ISSUE];
        $this->assertTrue(BugReportService::restateDisposition($bug->id, $admin->id, $opts)['ok']);
        $this->assertSame('needs_info', BugReportService::latestDispositionWithKind($bug->id)['kind']);
        $this->assertSame('triaged', $bug->fresh()->status);
        $this->assertTrue(BugReportService::restateDisposition($bug->id, $admin->id, $opts)['skipped'] ?? false, 'idempotent');
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
