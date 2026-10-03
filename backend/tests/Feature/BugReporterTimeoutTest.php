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

        [$admin, $reporter] = $this->seedUsers();

        $fresh = $this->makeResolvedBug($admin->id, $reporter->id, Carbon::parse('2026-07-17 12:00:00'), true);
        $oldNoEvidence = $this->makeResolvedBug($admin->id, $reporter->id, Carbon::parse('2026-07-01 12:00:00'), false);
        $oldOk = $this->makeResolvedBug($admin->id, $reporter->id, Carbon::parse('2026-07-01 12:00:00'), true);

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
        [$admin, $reporter] = $this->seedUsers();
        $bug = $this->makeResolvedBug($admin->id, $reporter->id, Carbon::parse('2026-07-01 12:00:00'), true);

        $result = BugReportService::closeByReporterTimeout($bug->id, $admin->id, true, 7);
        $this->assertTrue($result['ok']);
        $this->assertSame('would_close', $result['action']);
        $this->assertSame('resolved', $bug->fresh()->status);

        Carbon::setTestNow();
    }

    public function test_close_is_idempotent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 12:00:00'));
        [$admin, $reporter] = $this->seedUsers();
        $bug = $this->makeResolvedBug($admin->id, $reporter->id, Carbon::parse('2026-07-01 12:00:00'), true);

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
        [$admin, $reporter] = $this->seedUsers();
        $bug = $this->makeResolvedBug($admin->id, $reporter->id, Carbon::parse('2026-07-01 12:00:00'), true);

        $this->artisan('bugs:close-stale-resolved', [
            '--dry-run' => true,
            '--actor' => $admin->id,
        ])->assertSuccessful();

        $this->assertSame('resolved', $bug->fresh()->status);
        Carbon::setTestNow();
    }

    public function test_artisan_apply_requires_explicit_reviewed_eligible_ids(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 12:00:00'));
        [$admin, $reporter] = $this->seedUsers();
        $bug = $this->makeResolvedBug($admin->id, $reporter->id, Carbon::parse('2026-07-01 12:00:00'), true);

        $this->artisan('bugs:close-stale-resolved', ['--actor' => $admin->id])
            ->assertExitCode(1);
        $this->assertSame('resolved', $bug->fresh()->status);
        $this->artisan('bugs:close-stale-resolved', [
            '--actor' => $admin->id,
            '--reviewed-ids' => $bug->id . ',999999',
        ])->assertExitCode(1);
        $this->assertSame('resolved', $bug->fresh()->status);
        $this->artisan('bugs:close-stale-resolved', [
            '--actor' => $admin->id,
            '--reviewed-ids' => (string) $bug->id,
        ])->assertSuccessful();
        $this->assertSame('closed', $bug->fresh()->status);
        Carbon::setTestNow();
    }

    public function test_timeout_cannot_be_shortened_below_seven_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 12:00:00'));
        [$admin, $reporter] = $this->seedUsers();
        $bug = $this->makeResolvedBug($admin->id, $reporter->id, Carbon::parse('2026-07-16 12:00:00'), true);

        $this->assertNotContains($bug->id, array_column(BugReportService::listEligibleForReporterTimeout(1), 'bug_id'));
        $this->assertSame('not_eligible', BugReportService::closeByReporterTimeout($bug->id, $admin->id, false, 1)['code']);
        $this->artisan('bugs:close-stale-resolved', [
            '--days' => 1,
            '--actor' => $admin->id,
            '--reviewed-ids' => (string) $bug->id,
        ])->assertExitCode(1);
        $this->artisan('bugs:close-stale-resolved', [
            '--days' => 'invalid',
            '--dry-run' => true,
            '--actor' => $admin->id,
        ])->assertExitCode(1);
        $this->assertSame('resolved', $bug->fresh()->status);
        Carbon::setTestNow();
    }

    public function test_reporter_reply_and_missing_retest_request_are_excluded(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 12:00:00'));
        [$admin, $reporter] = $this->seedUsers();
        $resolvedAt = Carbon::parse('2026-07-01 12:00:00');
        $replied = $this->makeResolvedBug($admin->id, $reporter->id, $resolvedAt, true);
        BugReportComment::create([
            'bug_report_id' => $replied->id,
            'author_user_id' => $reporter->id,
            'body' => '仍然有問題',
            'is_internal_note' => false,
            'created_at' => $resolvedAt->copy()->addDay(),
        ]);
        $noAsk = $this->makeResolvedBug($admin->id, $reporter->id, $resolvedAt, true);
        BugReportComment::query()->where('bug_report_id', $noAsk->id)->update(['body' => '已上線']);

        $ids = array_column(BugReportService::listEligibleForReporterTimeout(7), 'bug_id');
        $this->assertNotContains($replied->id, $ids);
        $this->assertNotContains($noAsk->id, $ids);
        $this->assertSame('not_eligible', BugReportService::closeByReporterTimeout($replied->id, $admin->id, false, 7)['code']);
        Carbon::setTestNow();
    }

    public function test_retest_request_must_be_recent_to_resolve_and_at_least_seven_days_old(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 12:00:00'));
        [$admin, $reporter] = $this->seedUsers();
        $resolvedAt = Carbon::parse('2026-07-01 12:00:00');
        $oldAsk = $this->makeResolvedBug($admin->id, $reporter->id, $resolvedAt, true);
        BugReportComment::query()->where('bug_report_id', $oldAsk->id)
            ->update(['created_at' => $resolvedAt->copy()->subDays(2)]);
        $lateAsk = $this->makeResolvedBug($admin->id, $reporter->id, $resolvedAt, true);
        BugReportComment::query()->where('bug_report_id', $lateAsk->id)
            ->update(['created_at' => Carbon::parse('2026-07-17 12:00:00')]);

        $ids = array_column(BugReportService::listEligibleForReporterTimeout(7), 'bug_id');
        $this->assertNotContains($oldAsk->id, $ids);
        $this->assertNotContains($lateAsk->id, $ids);
        Carbon::setTestNow();
    }

    public function test_newer_retest_request_restarts_the_seven_day_wait(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 12:00:00'));
        [$admin, $reporter] = $this->seedUsers();
        $resolvedAt = Carbon::parse('2026-07-01 12:00:00');
        $bug = $this->makeResolvedBug($admin->id, $reporter->id, $resolvedAt, true);
        BugReportComment::create([
            'bug_report_id' => $bug->id,
            'author_user_id' => $admin->id,
            'body' => '請再試一次',
            'is_internal_note' => false,
            'created_at' => Carbon::parse('2026-07-16 12:00:00'),
        ]);

        $ids = array_column(BugReportService::listEligibleForReporterTimeout(7), 'bug_id');
        $this->assertNotContains($bug->id, $ids);
        $this->assertSame('not_eligible', BugReportService::closeByReporterTimeout($bug->id, $admin->id, false, 7)['code']);
        $this->assertSame('resolved', $bug->fresh()->status);
        Carbon::setTestNow();
    }

    public function test_timeout_age_keeps_whole_day_contract_and_exact_threshold(): void
    {
        $now = Carbon::parse('2026-07-18 12:00:00');
        [$admin, $reporter] = $this->seedUsers();
        foreach ([
            ['future', $now->copy()->addDay(), null],
            ['under', $now->copy()->subDays(7)->addSecond(), null],
            ['exact', $now->copy()->subDays(7), 7],
            ['fraction', $now->copy()->subDays(7)->subHours(12), 7],
            ['older', $now->copy()->subDays(17)->subHours(12), 17],
        ] as [$label, $resolvedAt, $expectedDays]) {
            $bug = $this->makeResolvedBug($admin->id, $reporter->id, $resolvedAt, true);
            $eligible = collect(BugReportService::listEligibleForReporterTimeout(7, $now))->keyBy('bug_id');
            if ($expectedDays === null) {
                $this->assertFalse($eligible->has($bug->id), $label);
            } else {
                $this->assertTrue($eligible->has($bug->id), $label);
                $this->assertSame($expectedDays, $eligible->get($bug->id)['days_resolved'], $label);
            }
            $this->assertSame('resolved', $bug->fresh()->status, $label);
        }
    }

    public function test_awaiting_reporter_boundary_reply_and_internal_note(): void
    {
        $now = Carbon::parse('2026-10-03 12:00:00');
        [$admin, $reporter] = $this->seedUsers();
        $b13 = $this->makeTriagedBug($admin->id, $reporter->id, $now->copy()->subDays(13));
        $b15 = $this->makeTriagedBug($admin->id, $reporter->id, $now->copy()->subDays(15));
        $replied = $this->makeTriagedBug($admin->id, $reporter->id, $now->copy()->subDays(20));
        $this->comment($replied, $reporter->id, $now->copy()->subDays(19));
        $internal = $this->makeTriagedBug($admin->id, $reporter->id, $now->copy()->subDays(20));
        $this->comment($internal, $admin->id, $now->copy()->subDays(1), true);
        $this->comment($internal, $reporter->id, $now->copy()->subDays(19));
        $internalOnly = $this->makeTriagedBug($admin->id, $reporter->id, $now->copy()->subDays(20), false);
        $this->comment($internalOnly, $reporter->id, $now->copy()->subDays(16));
        $this->comment($internalOnly, $admin->id, $now->copy()->subDays(15), true);

        $rows = collect(BugReportService::listEligibleForAwaitingReporterTimeout($now))->keyBy('bug_id');
        $this->assertFalse($rows->has($b13->id));
        $this->assertTrue($rows->has($b15->id));
        $this->assertSame(15, $rows->get($b15->id)['days_waiting']);
        $this->assertFalse($rows->has($replied->id));
        $this->assertFalse($rows->has($internal->id));
        $this->assertFalse($rows->has($internalOnly->id), 'internal note is not a question');

        $noted = $this->makeTriagedBug($admin->id, $reporter->id, $now->copy()->subDays(20), false);
        $this->comment($noted, $admin->id, $now->copy()->subDays(20), false, '謝謝建議，我們已記錄這個需求，評估後再決定是否排入開發。');
        $rows = collect(BugReportService::listEligibleForAwaitingReporterTimeout($now))->keyBy('bug_id');
        $this->assertFalse($rows->has($noted->id), 'acknowledgement without a question waits on staff, not the reporter');
    }

    public function test_awaiting_dry_run_writes_nothing_and_apply_closes_with_public_comment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-03 12:00:00'));
        [$admin, $reporter] = $this->seedUsers();
        $bug = $this->makeTriagedBug($admin->id, $reporter->id, Carbon::now()->subDays(15));

        $dry = BugReportService::closeByReporterTimeout($bug->id, $admin->id, true);
        $this->assertSame('would_close', $dry['action']);
        $this->assertSame('triaged', $bug->fresh()->status);
        $this->assertSame(1, BugReportComment::where('bug_report_id', $bug->id)->count());

        $this->assertSame('closed', BugReportService::closeByReporterTimeout($bug->id, $admin->id)['action']);
        $this->assertSame('closed', $bug->fresh()->status);
        $this->assertDatabaseHas('bug_report_comments', [
            'bug_report_id' => $bug->id,
            'author_user_id' => $admin->id,
            'is_internal_note' => false,
            'body' => '超過 14 天沒收到回覆，先結案。直接在這裡回覆就會重開。',
        ]);
        $this->assertDatabaseHas('bug_report_status_logs', [
            'bug_report_id' => $bug->id,
            'to_status' => 'closed',
            'note' => 'closed_by_timeout — awaiting reporter reply 14 days',
        ]);

        Carbon::setTestNow();
    }

    public function test_artisan_lists_awaiting_queue_and_apply_gate_applies(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-03 12:00:00'));
        [$admin, $reporter] = $this->seedUsers();
        $bug = $this->makeTriagedBug($admin->id, $reporter->id, Carbon::now()->subDays(15));

        $this->artisan('bugs:close-stale-resolved', ['--dry-run' => true, '--actor' => $admin->id])
            ->expectsOutput("bug #{$bug->id} [awaiting_reporter]: would_close")
            ->assertExitCode(0);
        $this->artisan('bugs:close-stale-resolved', ['--actor' => $admin->id, '--reviewed-ids' => (string) $bug->id])
            ->assertExitCode(0);
        $this->assertSame('closed', $bug->fresh()->status);

        Carbon::setTestNow();
    }

    public function test_reporter_comment_reopens_only_timeout_closed_bug(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-03 12:00:00'));
        [$admin, $reporter] = $this->seedUsers();
        $tokenR = $this->tokenFor($reporter);
        $tokenA = $this->tokenFor($admin);
        $post = fn (string $t, int $id) => $this->withHeaders(['Authorization' => "Bearer {$t}", 'Accept' => 'application/json'])
            ->postJson("/api/v1/bugs/{$id}/comments", ['body' => 'ok']);

        $timeout = $this->makeTriagedBug($admin->id, $reporter->id, Carbon::now()->subDays(15));
        BugReportService::closeByReporterTimeout($timeout->id, $admin->id);
        $post($tokenA, $timeout->id)->assertStatus(201);
        $this->assertSame('closed', $timeout->fresh()->status, 'staff comment never reopens');
        $post($tokenR, $timeout->id)->assertStatus(201);
        $this->assertSame('triaged', $timeout->fresh()->status);
        $this->assertDatabaseHas('bug_report_status_logs', [
            'bug_report_id' => $timeout->id, 'to_status' => 'triaged', 'note' => 'reopened_by_reporter_reply',
        ]);

        $resolved = $this->makeResolvedBug($admin->id, $reporter->id, Carbon::now()->subDays(10), true);
        BugReportService::closeByReporterTimeout($resolved->id, $admin->id, false, 7);
        $post($tokenR, $resolved->id)->assertStatus(201);
        $this->assertSame('in_progress', $resolved->fresh()->status, 'resolved-timeout regression goes back to in_progress');

        $verified = $this->makeTriagedBug($admin->id, $reporter->id, Carbon::now()->subDays(15));
        BugReportService::changeStatus($verified->id, $reporter->id, 'closed', 'closed_by_reporter');
        $post($tokenR, $verified->id)->assertStatus(201);
        $this->assertSame('closed', $verified->fresh()->status);

        Carbon::setTestNow();
    }

    public function test_days_option_ignored_and_admin_api_cannot_reopen_closed_to_triaged(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-03 12:00:00'));
        [$admin, $reporter] = $this->seedUsers();
        $bug = $this->makeTriagedBug($admin->id, $reporter->id, Carbon::now()->subDays(10));

        $this->artisan('bugs:close-stale-resolved', ['--dry-run' => true, '--days' => 7, '--actor' => $admin->id])
            ->doesntExpectOutput("bug #{$bug->id} [awaiting_reporter]: would_close")
            ->assertExitCode(0);
        $this->assertSame('triaged', $bug->fresh()->status);

        BugReportService::changeStatus($bug->id, $admin->id, 'closed', 'manual');
        $r = BugReportService::changeStatus($bug->id, $admin->id, 'triaged', 'x');
        $this->assertSame('invalid_transition', $r['code']);
        $this->withHeaders(['Authorization' => 'Bearer ' . $this->tokenFor($admin), 'Accept' => 'application/json'])
            ->postJson("/api/v1/bugs/{$bug->id}/status", ['status' => 'triaged']);
        $this->assertSame('closed', $bug->fresh()->status);

        Carbon::setTestNow();
    }

    private function tokenFor(User $user): string
    {
        UserCampus::firstOrCreate(['CampusID' => 1, 'UserID' => $user->id], ['Admin' => $user->type === 'S' ? 1 : 0, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }

    private function comment(BugReport $bug, int $authorId, Carbon $at, bool $internal = false, string $body = '訊息'): void
    {
        BugReportComment::create([
            'bug_report_id' => $bug->id,
            'author_user_id' => $authorId,
            'body' => $body,
            'is_internal_note' => $internal,
            'created_at' => $at,
        ]);
    }

    private function makeTriagedBug(int $adminId, int $reporterId, Carbon $askedAt, bool $publicAsk = true): BugReport
    {
        $bug = BugReport::create([
            'CampusID' => 1,
            'reporter_user_id' => $reporterId,
            'title' => 'Awaiting reporter',
            'description' => 'D',
            'severity' => 'low',
            'status' => 'triaged',
        ]);
        if ($publicAsk) {
            $this->comment($bug, $adminId, $askedAt, false, '請回覆畫面上的合約起迄日期');
        }
        return $bug;
    }

    private function seedUsers(): array
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
        $reporter = User::create([
            'LoginName' => 'timeoutReporter@test.com',
            'Name' => 'Timeout Reporter',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => rand(900000000, 999999999),
        ]);
        return [$user, $reporter];
    }

    private function makeResolvedBug(int $adminId, int $reporterId, Carbon $resolvedAt, bool $withEvidence): BugReport
    {
        $bug = BugReport::create([
            'CampusID' => 1,
            'reporter_user_id' => $reporterId,
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
