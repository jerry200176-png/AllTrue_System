<?php

namespace Tests\Feature;

use App\Console\Commands\BugAutoIntakeCommand;
use App\Models\BugReport;
use App\Models\BugReportComment;
use App\Models\BugReportStatusLog;
use App\Models\User;
use App\Operations\Inapp\InappAgentClients;
use App\Operations\Inapp\InappStandingPolicy;
use App\Operations\PopOperationCatalog;
use App\Operations\PopOperationService;
use App\Operations\Strategies\InappReportTriageStrategy;
use App\Services\BugReportService;
use App\Services\BugReportWritebackEvidence;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** ADR-POP-015 (a) through the real POP path (draft, dry-run, approval, Pi-local execute) with a faked GitHub API and real report rows. */
final class PopInappReportTriageTest extends TestCase
{
    use RefreshDatabase;

    private const OP = InappReportTriageStrategy::OPERATION;
    private const REPO = 'jerry200176-png/AllTrue_System';
    private const ISSUE = 77;
    private const MACHINE = 'api-client:7'; // a control-plane machine actor: never a pop_inapp_agent client

    private string $dir;
    private PopOperationService $service;
    private InappStandingPolicy $policy;
    private User $adminUser;
    private int $admin;
    private int $reporter;
    private string $deployed;
    private array $remote; // what the faked GitHub API returns for the issue

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-12 10:00:00'));
        $this->dir = sys_get_temp_dir() . '/pop-triage-' . bin2hex(random_bytes(6));
        File::copyDirectory(dirname(base_path()) . '/operations', $this->dir);
        $this->deployed = str_repeat('d', 40);
        file_put_contents($this->dir . '/deployment.json', json_encode(['backend_sha' => $this->deployed]));
        $catalog = new PopOperationCatalog($this->dir . '/catalog.yaml');
        $this->app->instance(PopOperationCatalog::class, $catalog);
        $this->service = new PopOperationService($catalog, null, $this->dir . '/deployment.json');
        $this->policy = new InappStandingPolicy($catalog);
        config(['pop_inapp.standing_policy_enabled' => true, 'pop_inapp.daily_cap' => 20, 'pop_inapp.github_repo' => self::REPO]);
        $this->adminUser = User::create(['LoginName' => 'popTriAdmin@test.com', 'Name' => 'A', 'PSW' => 'x', 'type' => 'S', 'phone' => rand(900000000, 999999999)]);
        $this->admin = (int) $this->adminUser->id;
        $this->reporter = User::create(['LoginName' => 'popTriRep@test.com', 'Name' => 'R', 'PSW' => 'x', 'type' => 'T', 'phone' => rand(900000000, 999999999)])->id;
        $this->remote = ['source_ref' => null, 'pull_request' => false, 'author' => 'github-actions[bot]', 'state' => 'open', 'state_reason' => null];
        Http::fake(fn (Request $request) => $this->respond($request));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_human_approved_new_report_is_triaged_with_its_issue_link_and_one_public_reply_and_nothing_else_changes(): void
    {
        $bug = $this->bug();
        $before = BugReport::query()->findOrFail($bug)->getAttributes();
        $id = $this->drafted($bug);
        $this->service->approve($id, 'inapp-human-review', 'user:' . $this->admin, 'super_admin', $this->deployed, $this->admin);

        self::assertSame('succeeded', $this->service->runApprovedLocally($id)['status']);
        $after = BugReport::query()->findOrFail($bug)->getAttributes();
        // The only columns the operation may change on the report row.
        self::assertSame([], array_diff(array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before))), ['status', 'updated_at']));
        self::assertSame('triaged', $after['status']);
        $log = BugReportStatusLog::query()->where('bug_report_id', $bug)->sole();
        self::assertSame(['new', 'triaged', $this->admin], [$log->from_status, $log->to_status, (int) $log->changed_by]);
        // The same link the Phase A workflow records: a `bug` disposition carrying the issue URL.
        $disposition = BugReportService::latestDispositionWithKind($bug);
        self::assertSame(['bug', true, $this->issueUrl(), null], [$disposition['kind'], $disposition['engineering_required'],
            $disposition['github_issue_url'], $disposition['github_pr_url']]);
        self::assertStringContainsString($id, (string) $log->note);
        $comment = BugReportComment::query()->where('bug_report_id', $bug)->sole();
        self::assertSame([$this->reply(), false, $this->admin], [$comment->body, (bool) $comment->is_internal_note, (int) $comment->author_user_id]);
        // The execution record is IDs and hashes only: never the reply text or the report text.
        $records = DB::table('pop_execution_records')->where('operation_id', $id)->pluck('payload')->implode("\n");
        self::assertStringNotContainsString('已收到', $records);
        self::assertStringNotContainsString('report-title-marker', $records);
    }

    public function test_standing_policy_approves_the_inapp_agent_clients_request_and_writes_back_as_the_issuing_super_admin(): void
    {
        $actor = $this->inappAgent();
        $bug = $this->bug();
        // The hourly intake's fixed acknowledgement is already there and the report is still `new`.
        BugReportComment::create(['bug_report_id' => $bug, 'author_user_id' => $this->admin, 'body' => BugAutoIntakeCommand::ACK_TEXT,
            'is_internal_note' => false, 'created_at' => now()->subHour()]);
        $id = $this->drafted($bug, null, 'req-001', $actor);

        self::assertSame([$id], $this->policy->approvePending($this->service)['approved']);
        self::assertSame($this->policy->approverRef(), DB::table('pop_approval_events')->where('operation_id', $id)->value('approver'));
        self::assertStringEndsWith('@5', $this->policy->approverRef());
        self::assertSame('succeeded', $this->service->runApprovedLocally($id)['status']);
        self::assertSame('triaged', BugReport::query()->whereKey($bug)->value('status'));
        $replies = BugReportComment::query()->where('bug_report_id', $bug)->orderBy('id')->get();
        self::assertSame([BugAutoIntakeCommand::ACK_TEXT, $this->reply()], $replies->pluck('body')->all());
        self::assertSame($this->admin, (int) $replies->last()->author_user_id);
        self::assertSame($this->admin, (int) BugReportStatusLog::query()->where('bug_report_id', $bug)->value('changed_by'));
    }

    public static function untrustedRequesters(): array
    {
        return ['control-plane machine client' => ['machine'], 'human flow caller' => ['human'], 'in-app agent scoped to another campus' => ['other_campus']];
    }

    public static function approvedLoggedKinds(): array
    {
        return ['suggestion' => ['suggestion'], 'UX friction' => ['ux_friction']];
    }

    /** @dataProvider approvedLoggedKinds */
    public function test_founder_approved_suggestion_receipt_logs_once_without_claiming_a_bug_fix(string $kind): void
    {
        $actor = $this->inappAgent();
        $bug = $this->bug();
        $reply = '已收進產品清單 ' . $this->issueUrl() . '。上線時會在這裡通知你；想補充直接回覆就會重開。';
        $id = $this->drafted($bug, $this->params($bug, ['disposition' => $kind, 'public_reply' => $reply]), 'logged-001', $actor);

        self::assertSame([$id], $this->policy->approvePending($this->service)['approved']);
        self::assertSame('succeeded', $this->service->runApprovedLocally($id)['status']);
        self::assertSame('closed', BugReport::query()->whereKey($bug)->value('status'));
        $linked = BugReportService::loggedCloseDisposition($bug);
        // Logged means tracked, not delivered: keep the canonical engineering-required classification.
        self::assertSame([$kind, true, $this->issueUrl()], [$linked['kind'], $linked['engineering_required'], $linked['github_issue_url']]);
        $log = BugReportStatusLog::query()->where('bug_report_id', $bug)->sole();
        self::assertSame(['new', 'closed'], [$log->from_status, $log->to_status]);
        self::assertSame([$reply], BugReportComment::query()->where('bug_report_id', $bug)->pluck('body')->all());

        $again = $this->drafted($bug, $this->params($bug, ['disposition' => $kind, 'public_reply' => $reply, 'request_id' => 'logged-002']), 'logged-002', $actor);
        self::assertContains('status_not_new', $this->dryRunErrors($again));
        self::assertSame(1, BugReportComment::query()->where('bug_report_id', $bug)->count());
    }

    public static function unapprovedDispositions(): array
    {
        return ['duplicate' => ['duplicate'], 'not planned' => ['not_planned'], 'already solved' => ['already_solved'],
            'unknown' => ['anything'], 'null' => [null], 'boolean' => [false], 'array' => [[]], 'empty' => ['']];
    }

    public function test_logged_receipt_cannot_claim_shipping_without_production_evidence(): void
    {
        $bug = $this->bug();
        $plan = app(InappReportTriageStrategy::class)->plan($this->params($bug, ['disposition' => 'ux_friction',
            'public_reply' => '已修好並上線，請重新整理。追蹤：' . $this->issueUrl()]));
        self::assertFalse($plan['ok']);
        self::assertContains('logged_receipt_mismatch', $plan['errors']);
        $this->assertNothingWritten($bug);
    }

    public function test_legacy_six_key_bug_input_and_explicit_bug_replay_the_same_persisted_intent_but_a_new_kind_does_not(): void
    {
        $bug = $this->bug();
        $actor = $this->inappAgent();
        $p = $this->params($bug);
        $first = $this->service->createDraft(self::OP, $p, 'legacy-intent-001', $actor, 'pop_machine', [1]);
        $same = $this->service->createDraft(self::OP, [...$p, 'disposition' => 'bug'], 'legacy-intent-001', $actor, 'pop_machine', [1]);
        self::assertSame($first['id'], $same['id']);
        self::assertSame('bug', json_decode((string) $this->request($first['id'])->parameters, true)['disposition']);
        self::assertSame(1, DB::table('pop_operation_requests')->count());
        $this->expectException(RuntimeException::class);
        $this->service->createDraft(self::OP, [...$p, 'disposition' => 'suggestion'], 'legacy-intent-001', $actor, 'pop_machine', [1]);
    }

    public function test_explicit_null_disposition_is_never_defaulted_by_the_new_input_compatibility_seam(): void
    {
        $bug = $this->bug();
        $id = $this->drafted($bug, $this->params($bug, ['disposition' => null]));
        self::assertNull(json_decode((string) $this->request($id)->parameters, true)['disposition']);
        self::assertContains('disposition_invalid', $this->dryRunErrors($id));
        $this->assertNothingWritten($bug);
    }

    public function test_a_logged_receipt_cannot_be_attributed_to_its_own_reporter(): void
    {
        $bug = $this->bug();
        BugReport::query()->whereKey($bug)->update(['reporter_user_id' => $this->admin]);
        $p = $this->params($bug, ['disposition' => 'ux_friction', 'public_reply' => '已收進產品清單 ' . $this->issueUrl() . '。上線時會在這裡通知你；想補充直接回覆就會重開。']);
        $id = $this->drafted($bug, $p, 'own-001', $this->inappAgent());
        self::assertContains('reply_would_be_reporters_own', $this->dryRunErrors($id));
        $this->assertNothingWritten($bug);
    }

    public function test_a_reporters_unanswered_comment_blocks_a_logged_receipt_even_with_a_fresh_read_proof(): void
    {
        $bug = $this->bug();
        BugReportComment::create(['bug_report_id' => $bug, 'author_user_id' => $this->reporter, 'body' => '補充問題', 'is_internal_note' => false, 'created_at' => now()]);
        $id = $this->drafted($bug, $this->params($bug, ['disposition' => 'suggestion', 'public_reply' => '已收進產品清單 ' . $this->issueUrl() . '。上線時會在這裡通知你；想補充直接回覆就會重開。']), 'reply-001', $this->inappAgent());
        self::assertContains('reporter_reply_pending', $this->dryRunErrors($id));
        $this->assertNothingWritten($bug, 1);
    }

    public function test_previous_catalog_drafts_and_approvals_are_not_repaired_or_executed_by_the_new_default(): void
    {
        $actor = $this->inappAgent();
        $bug = $this->bug();
        $id = $this->drafted($bug, null, 'old-catalog-001', $actor);
        self::assertSame([$id], $this->policy->approvePending($this->service)['approved']);
        $row = $this->request($id);
        $oldParameters = json_decode((string) $row->parameters, true);
        unset($oldParameters['disposition']);
        $oldJson = json_encode(PopOperationService::canonicalParameters($oldParameters), JSON_THROW_ON_ERROR);
        DB::table('pop_operation_requests')->where('id', $id)->update(['catalog_version' => 15, 'parameters' => $oldJson, 'parameters_hash' => hash('sha256', $oldJson)]);
        $before = $this->request($id);
        try {
            $this->service->runApprovedLocally($id);
            self::fail('old catalog approval must fail closed, not gain a default or execute');
        } catch (RuntimeException) {}
        self::assertSame((string) $before->parameters, (string) $this->request($id)->parameters);
        self::assertSame((string) $before->parameters_hash, (string) $this->request($id)->parameters_hash);
        $this->assertNothingWritten($bug);
    }

    public function test_receipt_rechecks_actual_human_fallback_attribution_under_the_row_lock(): void
    {
        User::query()->whereKey($this->reporter)->update(['type' => 'S']);
        $bug = $this->bug();
        $p = $this->params($bug, ['disposition' => 'suggestion', 'public_reply' => '已收進產品清單 ' . $this->issueUrl() . '。上線時會在這裡通知你；想補充直接回覆就會重開。']);
        $id = $this->drafted($bug, $p, 'fallback-own-001', $this->inappAgent());
        $strategy = app(InappReportTriageStrategy::class);
        $plan = $strategy->plan($p, ['operation_id' => $id]);
        self::assertTrue($plan['ok']); // requester issuer is not the reporter
        $this->service->approve($id, 'human-fallback', 'user:' . $this->reporter, 'super_admin', $this->deployed, $this->reporter);
        self::assertSame(['inapp_triage_reply_would_be_reporters_own'], $strategy->execute($plan, ['operation_id' => $id])['errors']);
        $this->assertNothingWritten($bug);
    }

    public static function existingNonBugKinds(): array
    {
        return ['duplicate' => ['duplicate'], 'not planned' => ['not_planned']];
    }

    /** @dataProvider existingNonBugKinds */
    public function test_bug_receipt_respects_an_existing_staff_non_bug_classification(string $kind): void
    {
        $bug = $this->bug();
        self::assertTrue(BugReportService::restateDisposition($bug, $this->admin, ['disposition' => $kind, 'github_issue_url' => $this->issueUrl()])['ok']);
        $plan = app(InappReportTriageStrategy::class)->plan($this->params($bug));
        self::assertFalse($plan['ok']);
        self::assertContains('classification_change_requires_review', $plan['errors']);
        self::assertSame('new', BugReport::query()->whereKey($bug)->value('status'));
        self::assertSame(0, BugReportComment::query()->where('bug_report_id', $bug)->count());
    }

    /** @dataProvider approvedLoggedKinds */
    public function test_logged_receipt_also_respects_a_different_existing_staff_classification(string $kind): void
    {
        $bug = $this->bug();
        self::assertTrue(BugReportService::restateDisposition($bug, $this->admin, ['disposition' => 'not_planned', 'github_issue_url' => $this->issueUrl()])['ok']);
        $plan = app(InappReportTriageStrategy::class)->plan($this->params($bug, ['disposition' => $kind,
            'public_reply' => '已收進產品清單 ' . $this->issueUrl() . '。上線時會在這裡通知你；想補充直接回覆就會重開。']));
        self::assertFalse($plan['ok']);
        self::assertContains('classification_change_requires_review', $plan['errors']);
        self::assertSame('new', BugReport::query()->whereKey($bug)->value('status'));
        self::assertSame(0, BugReportComment::query()->where('bug_report_id', $bug)->count());
    }

    /** @dataProvider unapprovedDispositions */
    public function test_unapproved_or_malformed_disposition_is_a_hard_plan_error($kind): void
    {
        $bug = $this->bug();
        $plan = app(InappReportTriageStrategy::class)->plan($this->params($bug, ['disposition' => $kind]));
        self::assertFalse($plan['ok']);
        self::assertContains('disposition_invalid', $plan['errors']);
        $this->assertNothingWritten($bug);
    }

    /** @dataProvider untrustedRequesters */
    public function test_only_a_usable_inapp_agent_client_gets_the_standing_policy_and_everyone_else_needs_a_super_admin(string $who): void
    {
        $bug = $this->bug();
        [$actor, $role] = match ($who) {
            'machine' => [self::MACHINE, 'pop_machine'],
            'human' => ['user:' . $this->admin, 'super_admin'],
            'other_campus' => [$this->inappAgent([2]), 'pop_machine'],
        };
        $id = $this->drafted($bug, null, 'req-001', $actor, $role, $role === 'super_admin' ? [] : [1]);

        $out = $this->policy->approvePending($this->service);
        self::assertSame([[], [$id]], [$out['approved'], $out['denied']]);
        self::assertContains('requester_client_unverified', $this->policy->evaluate($this->request($id)));
        self::assertSame(['awaiting_approval', 'new'], [$this->requestStatus($id), BugReport::query()->whereKey($bug)->value('status')]);
        $this->assertNothingWritten($bug);
    }

    public function test_daily_cap_counts_triaged_reports_per_taipei_day_and_the_next_one_falls_back_to_a_super_admin(): void
    {
        config(['pop_inapp.daily_cap' => 1]);
        $actor = $this->inappAgent();
        $first = $this->bug();
        $a = $this->drafted($first, null, 'req-001', $actor);
        self::assertSame([$a], $this->policy->approvePending($this->service)['approved']);
        self::assertSame('succeeded', $this->service->runApprovedLocally($a)['status']);
        $second = $this->bug(); // the faked issue names the newest report
        $b = $this->drafted($second, null, 'req-002', $actor);

        self::assertSame([$b], $this->policy->approvePending($this->service)['denied']);
        self::assertSame(['daily_cap_exceeded'], $this->policy->evaluate($this->request($b)));
        self::assertSame(['triaged', 'new'], BugReport::query()->whereKey([$first, $second])->orderBy('id')->pluck('status')->all());
        $this->assertNothingWritten($second);
    }

    public function test_a_replayed_request_id_is_denied_by_the_policy(): void
    {
        $actor = $this->inappAgent();
        $this->drafted($this->bug(), null, 'req-dup', $actor);
        $second = $this->bug(); // the faked issue names the newest report
        $id = $this->service->createDraft(self::OP, $this->params($second, ['request_id' => 'req-dup']), 'idem-other', $actor, 'pop_machine', [1]);
        $this->service->runDryRun((string) $id['id'], $actor, null, 'pop_machine', [1]);

        self::assertContains('request_id_replayed', $this->policy->evaluate($this->request((string) $id['id'])));
    }

    public static function refusals(): array
    {
        return [
            'SourceRef written by an outsider' => ['outsider', 'issue_source_ref_untrusted'],
            'issue names another report' => ['source_ref', 'issue_source_ref_untrusted'],
            'issue is a pull request' => ['pull_request', 'issue_source_ref_untrusted'],
            'issue is closed' => ['closed', 'issue_source_ref_untrusted'],
            'issue closed as duplicate' => ['duplicate', 'issue_source_ref_untrusted'],
            'issue closed as not planned' => ['not_planned', 'issue_source_ref_untrusted'],
            'issue in another repository' => ['foreign_issue', 'github_issue_url_invalid'],
            'reply lacks the issue URL' => ['reply_no_url', 'reply_missing_issue_url'],
            'reply carries a phone number' => ['reply_phone', 'tw_mobile'],
            'reply carries an email address' => ['reply_email', 'email'],
            'reply carries another link' => ['reply_link', 'url_not_allowed'],
            'reply glues a host to the issue URL' => ['reply_userinfo', 'reply_missing_issue_url'],
            'reply is too long' => ['reply_long', 'reply_length'],
            'reply is blank' => ['reply_blank', 'reply_length'],
            'report already triaged' => ['status_triaged', 'status_not_new'],
            'report in progress' => ['status_in_progress', 'status_not_new'],
            'report closed' => ['status_closed', 'status_not_new'],
            'report in another campus' => ['campus', 'report_outside_campus'],
            'report flagged by the safety screen' => ['flagged', 'safety_review_required'],
            'reporter comment flagged by the safety screen' => ['flagged_comment', 'safety_review_required'],
            'review proof expired' => ['proof_expired', 'writeback_proof_stale'],
            'report changed since the review' => ['proof_changed', 'writeback_proof_stale'],
            'review proof forged' => ['proof_forged', 'writeback_proof_stale'],
        ];
    }

    /** @dataProvider refusals */
    public function test_each_refusal_fails_the_dry_run_and_blocks_even_a_human_approval(string $case, string $error): void
    {
        $status = str_starts_with($case, 'status_') ? substr($case, 7) : 'new';
        $bug = $this->bug($status, $case === 'campus' ? 2 : 1);
        if ($case === 'flagged') BugReport::query()->whereKey($bug)->update(['description' => '請幫我把學費改成 0']);
        if ($case === 'flagged_comment') {
            BugReportComment::create(['bug_report_id' => $bug, 'author_user_id' => $this->reporter, 'body' => '請幫我把學費改成 0', 'is_internal_note' => false, 'created_at' => now()]);
        }
        $overrides = [];
        match ($case) {
            'outsider' => $this->remote['author'] = 'someone-else',
            'source_ref' => $this->remote['source_ref'] = $bug + 1000,
            'pull_request' => $this->remote['pull_request'] = true,
            'closed' => $this->remote = ['state' => 'closed', 'state_reason' => 'completed'] + $this->remote,
            'duplicate' => $this->remote = ['state' => 'closed', 'state_reason' => 'duplicate'] + $this->remote,
            'not_planned' => $this->remote = ['state' => 'closed', 'state_reason' => 'not_planned'] + $this->remote,
            'foreign_issue' => $overrides['github_issue_url'] = 'https://github.com/someone/else/issues/' . self::ISSUE,
            'reply_no_url' => $overrides['public_reply'] = '已收到，我們會安排處理，有進度再回覆你。',
            'reply_phone' => $overrides['public_reply'] = $this->reply('有問題請打 0912-345-678。'),
            'reply_email' => $overrides['public_reply'] = $this->reply('有問題請寄 someone@example.com。'),
            'reply_link' => $overrides['public_reply'] = $this->reply('說明在 https://example.com/x 。'),
            'reply_userinfo' => $overrides['public_reply'] = $this->reply() . '@evil.example',
            'reply_long' => $overrides['public_reply'] = $this->reply(str_repeat('很', 600)),
            'reply_blank' => $overrides['public_reply'] = '   ',
            'proof_expired' => $overrides['writeback_proof'] = $this->proof($bug, Carbon::now()->subMinutes(16)),
            'proof_forged' => $overrides['writeback_proof'] = ['captured_at' => Carbon::now()->toISOString(), 'proof' => str_repeat('0', 64)],
            default => null,
        };
        $params = $this->params($bug, $overrides);
        if ($case === 'proof_changed') {
            BugReportComment::create(['bug_report_id' => $bug, 'author_user_id' => $this->reporter, 'body' => '補充', 'is_internal_note' => false, 'created_at' => now()]);
        }
        $id = $this->drafted($bug, $params);

        self::assertContains($error, $this->dryRunErrors($id));
        self::assertSame(['failed', $status], [$this->requestStatus($id), BugReport::query()->whereKey($bug)->value('status')]);
        $this->assertNothingWritten($bug, in_array($case, ['proof_changed', 'flagged_comment'], true) ? 1 : 0);
    }

    public static function malformedParameters(): array
    {
        return [
            'missing report' => [['bug_id' => 999999], 'report_missing'],
            'bug id not an integer' => [['bug_id' => '5'], 'bug_id_invalid'],
            'campus id not an integer' => [['campus_id' => '1'], 'campus_id_invalid'],
            'request id malformed' => [['request_id' => 'Bad Id'], 'request_id_invalid'],
            'reply not a string' => [['public_reply' => ['x']], 'public_reply_invalid'],
            'issue URL with a query string' => [['github_issue_url' => 'https://github.com/' . self::REPO . '/issues/77?x=1'], 'github_issue_url_invalid'],
            'proof missing' => [['writeback_proof' => null], 'writeback_proof_invalid'],
            'proof with an extra field' => [['writeback_proof' => ['captured_at' => 'a', 'proof' => 'b', 'extra' => 'c']], 'writeback_proof_invalid'],
        ];
    }

    /** @dataProvider malformedParameters */
    public function test_plan_names_each_malformed_parameter_and_never_reaches_github(array $overrides, string $error): void
    {
        $bug = $this->bug();
        $plan = $this->app->make(InappReportTriageStrategy::class)->plan($this->params($bug, $overrides));

        self::assertFalse($plan['ok']);
        self::assertContains($error, $plan['errors']);
        Http::assertNothingSent();
    }

    public static function movedAfterThePlan(): array
    {
        return ['reporter wrote again' => ['comment', 'inapp_triage_writeback_proof_stale', 1],
            'someone else triaged it first' => ['status', 'inapp_triage_status_changed', 0],
            'report moved to another campus' => ['campus', 'inapp_triage_report_outside_campus', 0]];
    }

    /** @dataProvider movedAfterThePlan */
    public function test_execute_rechecks_under_the_row_lock_and_writes_nothing_when_something_moved(string $moved, string $error, int $comments): void
    {
        $bug = $this->bug();
        $id = $this->drafted($bug);
        $strategy = $this->app->make(InappReportTriageStrategy::class);
        $plan = $strategy->plan($this->params($bug));
        self::assertTrue($plan['ok'], implode(',', $plan['errors']));
        $this->service->approve($id, 'inapp-human-review', 'user:' . $this->admin, 'super_admin', $this->deployed, $this->admin);
        match ($moved) { // between the last plan and the locked write
            'comment' => BugReportComment::create(['bug_report_id' => $bug, 'author_user_id' => $this->reporter, 'body' => '補充', 'is_internal_note' => false, 'created_at' => now()]),
            'status' => BugReport::query()->whereKey($bug)->update(['status' => 'triaged']),
            'campus' => BugReport::query()->whereKey($bug)->update(['CampusID' => 2]),
        };

        self::assertSame(['ok' => false, 'errors' => [$error], 'bug_id' => $bug], $strategy->execute($plan, ['operation_id' => $id]));
        $this->assertNothingWritten($bug, $comments);
    }

    public function test_execute_rechecks_the_policy_kill_switches_inside_the_write_transaction(): void
    {
        $actor = $this->inappAgent();
        $bug = $this->bug();
        $id = $this->drafted($bug, null, 'req-001', $actor);
        $this->policy->approvePending($this->service);
        $strategy = $this->app->make(InappReportTriageStrategy::class);
        $plan = $strategy->plan($this->params($bug));
        config(['pop_inapp.standing_policy_enabled' => false]); // flipped after the executor claimed the request

        self::assertSame(['inapp_standing_policy_kill_switch'], $strategy->execute($plan, ['operation_id' => $id])['errors']);
        self::assertSame('new', BugReport::query()->whereKey($bug)->value('status'));
        $this->assertNothingWritten($bug);
    }

    public function test_an_issue_closed_after_a_human_approval_stops_the_local_executor(): void
    {
        $bug = $this->bug();
        $id = $this->drafted($bug);
        $this->service->approve($id, 'inapp-human-review', 'user:' . $this->admin, 'super_admin', $this->deployed, $this->admin);
        $this->remote['state'] = 'closed';

        $this->service->runApprovedLocally($id);
        self::assertSame(['failed', 'new'], [$this->requestStatus($id), BugReport::query()->whereKey($bug)->value('status')]);
        $this->assertNothingWritten($bug);
    }

    public function test_the_reply_is_only_the_callers_text_and_a_second_request_cannot_answer_the_same_report_twice(): void
    {
        $bug = $this->bug();
        $id = $this->drafted($bug);
        $this->service->approve($id, 'inapp-human-review', 'user:' . $this->admin, 'super_admin', $this->deployed, $this->admin);
        $this->service->runApprovedLocally($id);
        $body = (string) BugReportComment::query()->where('bug_report_id', $bug)->value('body');
        self::assertSame($this->reply(), $body);
        self::assertStringNotContainsString('report-title-marker', $body);
        self::assertStringNotContainsString('report-description-marker', $body);

        $again = $this->drafted($bug, $this->params($bug, ['request_id' => 'req-002']), 'req-002');
        self::assertContains('status_not_new', $this->dryRunErrors($again));
        self::assertSame(1, BugReportComment::query()->where('bug_report_id', $bug)->count());
        self::assertSame(1, BugReportStatusLog::query()->where('bug_report_id', $bug)->count());
    }

    public static function outOfScope(): array
    {
        return ['another campus' => [[2]], 'empty scope' => [[]]];
    }

    /** @dataProvider outOfScope */
    public function test_a_requester_outside_the_report_campus_cannot_even_draft(array $scope): void
    {
        $bug = $this->bug();
        $this->expectException(RuntimeException::class);
        $this->service->createDraft(self::OP, $this->params($bug), 'idem-scope', self::MACHINE, 'pop_machine', $scope);
    }

    private function drafted(int $bug, ?array $params = null, string $rid = 'req-001', string $actor = self::MACHINE, string $role = 'pop_machine', array $scope = [1]): string
    {
        $draft = $this->service->createDraft(self::OP, $params ?? $this->params($bug, ['request_id' => $rid]), 'idem-' . $rid . '-' . $bug,
            $actor, $role, $scope);
        $this->service->runDryRun((string) $draft['id'], $actor, null, $role, $scope);

        return (string) $draft['id'];
    }

    /** A real `pop_inapp_agent` client issued by the super admin; returns its POP request actor. */
    private function inappAgent(array $campusIds = [1]): string
    {
        return InappAgentClients::actor((int) InappAgentClients::issue($this->adminUser, 'triage agent', $campusIds)['record']['id']);
    }

    private function params(int $bug, array $overrides = []): array
    {
        return array_merge(['bug_id' => $bug, 'campus_id' => 1, 'github_issue_url' => $this->issueUrl(), 'public_reply' => $this->reply(),
            'request_id' => 'req-001', 'writeback_proof' => $this->proof($bug)], $overrides);
    }

    private function proof(int $bug, ?Carbon $at = null): ?array
    {
        $report = BugReport::query()->find($bug);
        if (!$report) return ['captured_at' => Carbon::now()->toISOString(), 'proof' => str_repeat('0', 64)];
        $now = Carbon::now();
        Carbon::setTestNow($at ?? $now);
        $proof = BugReportWritebackEvidence::capture($report);
        Carbon::setTestNow($now);

        return $proof;
    }

    private function reply(string $extra = ''): string
    {
        return '已收到，我們確認這是系統問題，會安排修正，有進度再回覆你。' . $extra . '追蹤：' . $this->issueUrl();
    }

    private function issueUrl(): string
    {
        return 'https://github.com/' . self::REPO . '/issues/' . self::ISSUE;
    }

    private function bug(string $status = 'new', int $campus = 1): int
    {
        return BugReport::create(['CampusID' => $campus, 'reporter_user_id' => $this->reporter, 'title' => 'report-title-marker',
            'description' => 'report-description-marker', 'severity' => 'low', 'status' => $status])->id;
    }

    private function request(string $id): object
    {
        return DB::table('pop_operation_requests')->where('id', $id)->first();
    }

    private function dryRunErrors(string $id): array
    {
        $payload = json_decode((string) DB::table('pop_execution_records')->where('operation_id', $id)->where('phase', 'dry-run')->value('payload'), true);

        return (array) ($payload['plan']['errors'] ?? []);
    }

    private function requestStatus(string $id): string
    {
        return (string) DB::table('pop_operation_requests')->where('id', $id)->value('status');
    }

    private function assertNothingWritten(int $bug, int $comments = 0): void
    {
        self::assertSame(0, BugReportStatusLog::query()->where('bug_report_id', $bug)->count());
        self::assertSame($comments, BugReportComment::query()->where('bug_report_id', $bug)->count());
    }

    private function respond(Request $request)
    {
        $api = 'https://api.github.com/repos/' . self::REPO . '/';
        $bug = $this->remote['source_ref'] ?? (int) BugReport::query()->max('id');
        $url = $request->url();

        return match (true) {
            str_starts_with($url, $api . 'issues/' . self::ISSUE . '/comments') => Http::response([]),
            $url === $api . 'issues/' . self::ISSUE => Http::response(['number' => self::ISSUE, 'repository_url' => rtrim($api, '/'),
                'state' => $this->remote['state'], 'state_reason' => $this->remote['state_reason'],
                'user' => ['login' => $this->remote['author']], 'body' => "SourceRef: alltrue:bug_report:{$bug}\n\n- In-app ID: {$bug}"]
                + ($this->remote['pull_request'] ? ['pull_request' => ['url' => 'x']] : [])),
            default => Http::response('', 599), // never reach the network
        };
    }
}
