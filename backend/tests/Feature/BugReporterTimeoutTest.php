<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\BugReport;
use App\Models\BugReportComment;
use App\Models\BugReportStatusLog;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\BugReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BugReporterTimeoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_eligible_requires_age_and_resolution_evidence(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 12:00:00'));

        [$admin] = $this->seedAdmin();

        $fresh = $this->makeResolvedBug($admin->id, Carbon::parse('2026-07-17 12:00:00'), true);
        $oldNoEvidence = $this->makeResolvedBug($admin->id, Carbon::parse('2026-07-01 12:00:00'), false);
        $oldOk = $this->makeResolvedBug($admin->id, Carbon::parse('2026-07-01 12:00:00'), true);

        $eligible = BugReportService::listEligibleForReporterTimeout(7);
        $ids = array_column($eligible, 'bug_id');

        $this->assertNotContains($fresh->id, $ids);
        $this->assertNotContains($oldNoEvidence->id, $ids);
        $this->assertContains($oldOk->id, $ids);

        Carbon::setTestNow();
    }

    public function test_dry_run_does_not_close(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 12:00:00'));
        [$admin] = $this->seedAdmin();
        $bug = $this->makeResolvedBug($admin->id, Carbon::parse('2026-07-01 12:00:00'), true);

        $result = BugReportService::closeByReporterTimeout($bug->id, $admin->id, true, 7);
        $this->assertTrue($result['ok']);
        $this->assertSame('would_close', $result['action']);
        $this->assertSame('resolved', $bug->fresh()->status);

        Carbon::setTestNow();
    }

    public function test_close_is_idempotent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 12:00:00'));
        [$admin] = $this->seedAdmin();
        $bug = $this->makeResolvedBug($admin->id, Carbon::parse('2026-07-01 12:00:00'), true);

        $r1 = BugReportService::closeByReporterTimeout($bug->id, $admin->id, false, 7);
        $this->assertSame('closed', $r1['action']);
        $this->assertSame('closed', $bug->fresh()->status);
        $this->assertDatabaseHas('bug_report_status_logs', [
            'bug_report_id' => $bug->id,
            'to_status' => 'closed',
        ]);

        $r2 = BugReportService::closeByReporterTimeout($bug->id, $admin->id, false, 7);
        $this->assertSame('already_closed_by_timeout', $r2['action']);

        Carbon::setTestNow();
    }

    public function test_artisan_dry_run(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 12:00:00'));
        [$admin] = $this->seedAdmin();
        $bug = $this->makeResolvedBug($admin->id, Carbon::parse('2026-07-01 12:00:00'), true);

        $this->artisan('bugs:close-stale-resolved', [
            '--dry-run' => true,
            '--actor' => $admin->id,
        ])->assertSuccessful();

        $this->assertSame('resolved', $bug->fresh()->status);
        Carbon::setTestNow();
    }

    private function seedAdmin(): array
    {
        $user = User::create([
            'LoginName' => 'timeoutAdmin@test.com',
            'Name' => 'Timeout Admin',
            'PSW' => 'secret',
            'type' => 'S',
            'phone' => rand(900000000, 999999999),
        ]);
        UserCampus::create([
            'CampusID' => 1,
            'UserID' => $user->id,
            'Admin' => 1,
            'Approved' => 1,
        ]);
        return [$user];
    }

    private function makeResolvedBug(int $adminId, Carbon $resolvedAt, bool $withEvidence): BugReport
    {
        $bug = BugReport::create([
            'CampusID' => 1,
            'reporter_user_id' => $adminId,
            'title' => 'Timeout candidate',
            'description' => 'D',
            'severity' => 'low',
            'status' => 'resolved',
        ]);
        BugReportComment::create([
            'bug_report_id' => $bug->id,
            'author_user_id' => $adminId,
            'body' => '請再試一次',
            'is_internal_note' => false,
            'created_at' => $resolvedAt,
        ]);
        $note = $withEvidence
            ? '[resolution_evidence]{"production_revision":"662960e5","resolver_user_id":'.$adminId.',"resolved_at":"'.$resolvedAt->toIso8601String().'"}'
            : 'fixed in chat';
        BugReportStatusLog::create([
            'bug_report_id' => $bug->id,
            'changed_by' => $adminId,
            'from_status' => 'in_progress',
            'to_status' => 'resolved',
            'note' => $note,
            'created_at' => $resolvedAt,
        ]);
        return $bug;
    }
}
