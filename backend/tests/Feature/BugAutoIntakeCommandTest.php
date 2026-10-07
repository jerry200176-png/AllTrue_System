<?php

namespace Tests\Feature;

use App\Console\Commands\BugAutoIntakeCommand;
use App\Models\BugReport;
use App\Models\BugReportComment;
use App\Models\BugReportStatusLog;
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

    public function test_ack_posts_once_keeps_status_new_and_writes_no_status_log(): void
    {
        [, $reporter] = $this->users();
        $bug = $this->bug($reporter, 'new', '2026-10-07 09:00:00');

        $this->assertSame(0, Artisan::call('bugs:auto-intake', ['--ack' => true, '--bug-id' => $bug->id, '--issue-url' => self::ISSUE]));
        // A generic ack is not triage: SLA metrics count breaches only while `new`.
        $this->assertSame('new', $bug->fresh()->status);
        $this->assertSame(0, BugReportStatusLog::where('bug_report_id', $bug->id)->count(), 'no status log, so adoption metrics never count the automation actor');
        $comments = BugReportComment::where('bug_report_id', $bug->id)->get();
        $this->assertCount(1, $comments);
        $this->assertSame(BugAutoIntakeCommand::ACK_TEXT, $comments[0]->body);
        $this->assertFalse((bool) $comments[0]->is_internal_note);
        $this->assertNull(BugReportService::latestDispositionWithKind($bug->id));

        $this->assertSame(0, Artisan::call('bugs:auto-intake', ['--ack' => true, '--bug-id' => $bug->id, '--issue-url' => self::ISSUE]));
        $this->assertSame(1, BugReportComment::where('bug_report_id', $bug->id)->count(), 'retry never posts twice');
    }

    public function test_acknowledged_reports_leave_the_candidate_list_and_triaged_ones_are_skipped(): void
    {
        [$admin, $reporter] = $this->users();
        $acked = $this->bug($reporter, 'new', now()->subHour()->toDateTimeString());
        $waiting = $this->bug($reporter, 'new', now()->subHour()->toDateTimeString());
        Artisan::call('bugs:auto-intake', ['--ack' => true, '--bug-id' => $acked->id, '--issue-url' => self::ISSUE]);

        Artisan::call('bugs:auto-intake', ['--candidates' => true]);
        $ids = array_column(json_decode(trim(Artisan::output()), true)['candidates'], 'bug_id');
        $this->assertSame([$waiting->id], $ids, 'backlog drains: acked reports are not listed again');

        $human = $this->bug($reporter, 'new', '2026-10-07 09:00:00');
        BugReportService::changeStatus($human->id, $admin->id, 'triaged', 'manual');
        $this->assertSame(0, Artisan::call('bugs:auto-intake', ['--ack' => true, '--bug-id' => $human->id, '--issue-url' => self::ISSUE]));
        $this->assertSame(0, BugReportComment::where('bug_report_id', $human->id)->count());
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

    public function test_phase_a_can_restate_disposition_on_a_triaged_report(): void
    {
        [$admin, $reporter] = $this->users();
        $bug = $this->bug($reporter, 'new', '2026-10-07 09:00:00');
        BugReportService::changeStatus($bug->id, $admin->id, 'triaged', 'phase-a', ['disposition' => 'bug', 'github_issue_url' => self::ISSUE]);

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
