<?php

namespace Tests\Feature;

use App\Models\BugReport;
use App\Models\BugReportComment;
use App\Models\BugReportEvidence;
use App\Models\BugReportStatusLog;
use App\Models\User;
use App\Operations\Inapp\InappRequesterGuard;
use App\Operations\Inapp\InappAgentClients;
use App\Operations\Inapp\InappStandingPolicy;
use App\Operations\Inapp\InappReportReplyGuard;
use App\Operations\Inapp\InappSupportCommentOrigin;
use App\Operations\PopOperationCatalog;
use App\Operations\PopOperationService;
use App\Operations\Strategies\InappReportFollowupCommentStrategy;
use App\Operations\Strategies\InappReportResolveAfterDeployStrategy;
use App\Operations\Strategies\InappReportTriageStrategy;
use App\Services\BugReportWritebackEvidence;
use App\Services\BugReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** ADR-POP-015 (b) through the real POP path (draft, dry-run, approval, Pi-local execute) with faked GitHub/health HTTP and real report rows. */
final class PopInappResolveAfterDeployTest extends TestCase
{
    use RefreshDatabase;

    private const OP = InappReportResolveAfterDeployStrategy::OPERATION;
    private const REPO = 'jerry200176-png/AllTrue_System';
    private const ISSUE = 77;
    private const RUN = '4242';
    private const SMOKE = '5151';

    private string $dir;
    private PopOperationService $service;
    private InappStandingPolicy $policy;
    private int $admin;
    private int $reporter;
    private string $fix;
    private string $deployed;
    private string $unrelated;
    private array $remote; // what the faked GitHub API and health endpoint return

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-22 10:00:00'));
        $this->dir = sys_get_temp_dir() . '/pop-resolve-' . bin2hex(random_bytes(6));
        File::copyDirectory(dirname(base_path()) . '/operations', $this->dir);
        [$this->fix, $this->deployed, $this->unrelated] = [str_repeat('f', 40), str_repeat('d', 40), str_repeat('e', 40)];
        $this->writeManifest($this->deployed);
        $catalog = new PopOperationCatalog($this->dir . '/catalog.yaml');
        $this->app->instance(PopOperationCatalog::class, $catalog);
        $this->service = new PopOperationService($catalog, null, $this->dir . '/deployment.json');
        $this->policy = new InappStandingPolicy($catalog);
        config(['pop_inapp.standing_policy_enabled' => true, 'pop_inapp.daily_cap' => 20, 'pop_inapp.github_repo' => self::REPO,
            'pop_inapp.deployment_manifest' => $this->dir . '/deployment.json',
            'pop_inapp.health_url' => 'https://health.test/api/v1/health']);
        $this->admin = User::create(['LoginName' => 'popResAdmin@test.com', 'Name' => 'A', 'PSW' => 'x', 'type' => 'S', 'phone' => rand(900000000, 999999999)])->id;
        $this->reporter = User::create(['LoginName' => 'popResRep@test.com', 'Name' => 'R', 'PSW' => 'x', 'type' => 'T', 'phone' => rand(900000000, 999999999)])->id;
        $this->remote = ['run_path' => '.github/workflows/deploy.yml', 'run_head' => $this->deployed, 'job' => 'success', 'source_ref' => null,
            'pull_request' => false, 'author' => 'github-actions[bot]', 'health' => 200, 'pr_body' => 'Refs #' . self::ISSUE,
            'smoke' => 'success', 'smoke_at' => '2026-10-22T09:30:00Z', 'smoke_event' => 'workflow_run'];
        Http::fake(fn (Request $request) => $this->respond($request));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public static function resolvableStatuses(): array
    {
        return ['triaged walks through in_progress' => ['triaged', [['triaged', 'in_progress'], ['in_progress', 'resolved']]],
            'in_progress resolves directly' => ['in_progress', [['in_progress', 'resolved']]]];
    }

    /** @dataProvider resolvableStatuses */
    public function test_human_approved_report_is_resolved_with_its_reply_and_production_evidence(string $status, array $steps): void
    {
        $bug = $this->bug($status);
        $id = $this->drafted($bug);
        $this->service->approve($id, 'inapp-human-review', 'user:' . $this->admin, 'super_admin', $this->deployed, $this->admin);

        self::assertSame('succeeded', $this->service->runApprovedLocally($id)['status']);
        self::assertSame('resolved', BugReport::query()->whereKey($bug)->value('status'));
        $logs = BugReportStatusLog::query()->where('bug_report_id', $bug)->orderBy('id')->get();
        self::assertSame($steps, $logs->map(fn ($l) => [$l->from_status, $l->to_status])->all());
        self::assertSame([$this->admin], $logs->pluck('changed_by')->map(fn ($v) => (int) $v)->unique()->values()->all());
        self::assertStringContainsString($this->deployed, (string) $logs->last()->note);
        self::assertStringContainsString(self::RUN, (string) $logs->last()->note);
        $comment = BugReportComment::query()->where('bug_report_id', $bug)->sole();
        self::assertSame([$this->reply(), false, $this->admin], [$comment->body, (bool) $comment->is_internal_note, (int) $comment->author_user_id]);
        // The execution record is IDs and hashes only: never the reply text.
        $records = DB::table('pop_execution_records')->where('operation_id', $id)->pluck('payload')->implode("\n");
        self::assertStringNotContainsString('確認已修好', $records);
        self::assertStringContainsString(self::RUN, $records);
    }

    /** @dataProvider resolvableStatuses */
    public function test_native_self_filed_bug_resolution_records_its_reply_and_preserves_timeout_guards(string $status, array $steps): void
    {
        // PR5468: normal resolve must produce the same exact provenance as logged shipping.
        $bugId = $this->bug($status);
        BugReport::whereKey($bugId)->update(['reporter_user_id' => $this->admin]);
        $id = $this->drafted($bugId);
        $this->service->approve($id, 'inapp-human-review', 'user:' . $this->admin, 'super_admin', $this->deployed, $this->admin);
        self::assertSame('succeeded', $this->service->runApprovedLocally($id)['status']);
        $bug = BugReport::findOrFail($bugId);
        $comment = BugReportComment::where('bug_report_id', $bugId)->sole();
        $receipt = json_decode(DB::table('pop_execution_records')->where('operation_id', $id)->where('phase', 'execute')->value('payload'), true);
        self::assertSame($comment->id, $receipt['comment_id']);
        self::assertSame([$comment->id => true], InappSupportCommentOrigin::ids($bug, [$comment]));
        self::assertSame('support', BugReportService::getDetail($bugId)['comments'][0]['origin']);
        self::assertSame($steps, BugReportStatusLog::where('bug_report_id', $bugId)->orderBy('id')->get()->map(fn ($log) => [$log->from_status, $log->to_status])->all());
        self::assertSame('resolved', $bug->status); // no reporter verification on their behalf
        Carbon::setTestNow(now()->addDays(15));
        self::assertContains($bugId, array_column(BugReportService::listEligibleForReporterTimeout(14), 'bug_id'));
        BugReportComment::create(['bug_report_id' => $bugId, 'author_user_id' => $this->admin,
            'body' => $comment->body, 'is_internal_note' => false, 'created_at' => now()]);
        self::assertNotContains($bugId, array_column(BugReportService::listEligibleForReporterTimeout(14), 'bug_id'));
        self::assertSame('not_eligible', BugReportService::closeByReporterTimeout($bugId, $this->admin, false, 14)['code']);
        self::assertSame('resolved', $bug->fresh()->status);
    }

    public function test_standing_policy_approves_and_executes_attributed_to_the_issuing_super_admin(): void
    {
        $this->allowRequester();
        $bug = $this->bug('triaged');
        $id = $this->drafted($bug);

        self::assertSame([$id], $this->policy->approvePending($this->service)['approved']);
        self::assertSame($this->policy->approverRef(), DB::table('pop_approval_events')->where('operation_id', $id)->value('approver'));
        self::assertSame('succeeded', $this->service->runApprovedLocally($id)['status']);
        self::assertSame(['resolved', $this->admin], [BugReport::query()->whereKey($bug)->value('status'),
            (int) BugReportComment::query()->where('bug_report_id', $bug)->value('author_user_id')]);
    }

    public static function failingEvidence(): array
    {
        return [
            'fix not in the deployed revision' => ['fix_unrelated', 'fix_not_in_deployed_revision'],
            'deploy job did not succeed' => ['job_failed', 'deploy_run_not_successful'],
            'run is not deploy.yml' => ['run_path', 'deploy_run_not_successful'],
            'run target is not what the Pi runs' => ['run_head', 'deploy_run_not_in_production'],
            'manifest missing' => ['manifest_missing', 'deployment_manifest_unreadable'],
            'manifest malformed' => ['manifest_malformed', 'deployment_manifest_unreadable'],
            'manifest without a well-formed SHA' => ['manifest_no_sha', 'deployment_manifest_unreadable'],
            'fix PR does not reference the issue' => ['pr_unlinked', 'fix_not_linked_to_issue'],
            'smoke failed' => ['smoke_failed', 'smoke_not_passed_after_deploy'],
            'smoke ran before the deploy finished' => ['smoke_early', 'smoke_not_passed_after_deploy'],
            'smoke run came from a pull request' => ['smoke_pr', 'smoke_not_passed_after_deploy'],
            'SourceRef written by an outsider' => ['outsider', 'issue_source_ref_untrusted'],
            'health not 200' => ['health', 'health_not_ok'],
            'issue names another report' => ['source_ref', 'issue_source_ref_untrusted'],
            'issue is a pull request' => ['pull_request', 'issue_source_ref_untrusted'],
            'issue in another repository' => ['foreign_issue', 'github_issue_url_invalid'],
            'reply lacks outcome phrases' => ['reply_phrases', 'reply_missing_outcome_phrases'],
            'reply lacks the issue URL' => ['reply_no_url', 'reply_missing_issue_url'],
            'reply carries a phone number' => ['reply_phone', 'tw_mobile'],
            'reply carries a full-width phone number' => ['reply_fullwidth_phone', 'tw_mobile'],
            'reply carries another link' => ['reply_link', 'url_not_allowed'],
            'reply glues a host to the issue URL' => ['reply_userinfo', 'reply_missing_issue_url'],
            'reply is too long' => ['reply_long', 'reply_length'],
            'smoke ref missing' => ['smoke', 'smoke_result_ref_invalid'],
            'report is new' => ['status_new', 'status_not_resolvable'],
            'report already resolved' => ['status_resolved', 'status_not_resolvable'],
            'report in another campus' => ['campus', 'report_outside_campus'],
            'report flagged by the safety screen' => ['flagged', 'safety_review_required'],
            'reporter comment flagged by the safety screen' => ['flagged_comment', 'safety_review_required'],
            'review proof expired' => ['proof_expired', 'writeback_proof_stale'],
            'report changed since the review' => ['proof_changed', 'writeback_proof_stale'],
            'review proof forged' => ['proof_forged', 'writeback_proof_stale'],
        ];
    }

    public static function shippedLoggedKinds(): array
    {
        return ['suggestion' => ['suggestion'], 'UX friction' => ['ux_friction']];
    }

    /** @dataProvider shippedLoggedKinds */
    public function test_verified_suggestion_shipping_notifies_once_keeps_logged_status_and_never_claims_reporter_acceptance(string $kind): void
    {
        $bug = $this->bug('new');
        $received = '已收進產品清單，上線時會在這裡通知你。追蹤：' . $this->issueUrl();
        self::assertTrue(BugReportService::closeAsLogged($bug, $this->admin, $kind, $this->issueUrl(), $received)['ok']);
        $client = InappAgentClients::issue(User::query()->findOrFail($this->admin), 'suggestion agent', [1]);
        $actor = InappAgentClients::actor((int) $client['record']['id']);
        $reply = '學生清單已更新配色，請重新開啟學生清單查看；想補充可在這裡回覆。追蹤：' . $this->issueUrl();
        $id = $this->drafted($bug, $this->params($bug, ['public_reply' => $reply]), 'ship-001', $actor);

        self::assertSame([$id], $this->policy->approvePending($this->service)['approved']);
        self::assertSame('succeeded', $this->service->runApprovedLocally($id)['status']);
        self::assertSame('closed', BugReport::query()->whereKey($bug)->value('status'));
        self::assertSame([['new', 'closed']], BugReportStatusLog::query()->where('bug_report_id', $bug)->orderBy('id')->get()->map(fn ($l) => [$l->from_status, $l->to_status])->all());
        self::assertSame([$received, $reply], BugReportComment::query()->where('bug_report_id', $bug)->orderBy('id')->pluck('body')->all());
        $evidence = BugReportEvidence::query()->where('bug_report_id', $bug)->sole();
        self::assertSame([$this->deployed, self::RUN], [$evidence->production_revision, (string) $evidence->deploy_run_id]);
        $closeId = BugReportStatusLog::query()->where('bug_report_id', $bug)->where('to_status', 'closed')->max('id');
        self::assertStringStartsWith('logged_ship:' . $closeId . ':', $evidence->source_ref);

        $again = $this->drafted($bug, $this->params($bug, ['public_reply' => $reply, 'request_id' => 'ship-002']), 'ship-002', $actor);
        self::assertContains('logged_ship_already_notified', $this->dryRunErrors($again));
        self::assertSame(2, BugReportComment::query()->where('bug_report_id', $bug)->count());
        $otherRevision = $this->drafted($bug, $this->params($bug, ['public_reply' => $reply, 'request_id' => 'ship-003', 'fix_commit_sha' => str_repeat('c', 40)]), 'ship-003', $actor);
        self::assertContains('logged_ship_already_notified', $this->dryRunErrors($otherRevision));

        // Verify remains meaningful after re-planning correctly refuses this already shipped cycle.
        $receipt = json_decode((string) DB::table('pop_execution_records')->where('operation_id', $id)->where('phase', 'execute')->value('payload'), true);
        $strategy = app(InappReportResolveAfterDeployStrategy::class);
        self::assertTrue($strategy->verify([], $receipt)['ok']);
        foreach (['comment_id' => 0, 'author_id' => $this->reporter, 'production_revision' => $this->unrelated,
            'deploy_run_id' => '9999', 'issue_url' => $this->issueUrl() . '1', 'reply_sha256' => str_repeat('0', 64),
            'logged_close' => null] as $field => $wrong) {
            self::assertFalse($strategy->verify([], array_replace($receipt, [$field => $wrong]))['ok'], $field);
        }
    }

    public static function unauthorizedLoggedKinds(): array
    {
        return ['duplicate' => ['duplicate'], 'not planned' => ['not_planned']];
    }

    /** @dataProvider unauthorizedLoggedKinds */
    public function test_explicit_non_bug_classification_requires_staff_review_in_a_bug_state(string $kind): void
    {
        $bug = $this->bug('triaged');
        self::assertTrue(BugReportService::restateDisposition($bug, $this->admin, ['disposition' => $kind, 'github_issue_url' => $this->issueUrl()])['ok']);
        $plan = app(InappReportResolveAfterDeployStrategy::class)->plan($this->params($bug));
        self::assertFalse($plan['ok']);
        self::assertContains('non_bug_requires_review', $plan['errors']);
        self::assertSame('triaged', BugReport::query()->whereKey($bug)->value('status'));
        self::assertSame(0, BugReportComment::query()->where('bug_report_id', $bug)->count());
        self::assertSame(0, BugReportEvidence::query()->where('bug_report_id', $bug)->count());
    }

    public function test_explicit_bug_classification_preserves_the_existing_resolution_path(): void
    {
        $bug = $this->bug('triaged');
        self::assertTrue(BugReportService::restateDisposition($bug, $this->admin, ['disposition' => 'bug', 'github_issue_url' => $this->issueUrl()])['ok']);
        $this->allowRequester();
        $id = $this->drafted($bug);
        self::assertSame([$id], $this->policy->approvePending($this->service)['approved']);
        self::assertSame('succeeded', $this->service->runApprovedLocally($id)['status']);
        self::assertSame('resolved', BugReport::query()->whereKey($bug)->value('status'));
    }

    /** @dataProvider unauthorizedLoggedKinds */
    public function test_human_logged_kinds_outside_the_new_allowlist_cannot_ship_through_the_machine_operation(string $kind): void
    {
        $bug = $this->bug('new');
        self::assertTrue(BugReportService::closeAsLogged($bug, $this->admin, $kind, $this->issueUrl(), '已記錄。')['ok']);
        $this->allowRequester();
        $id = $this->drafted($bug);
        self::assertContains('status_not_resolvable', $this->dryRunErrors($id));
        self::assertSame(1, BugReportComment::query()->where('bug_report_id', $bug)->count());
        self::assertSame(0, BugReportEvidence::query()->where('bug_report_id', $bug)->count());
        $this->expectException(RuntimeException::class);
        $this->service->approve($id, 'cannot-override-kind', 'user:' . $this->admin, 'super_admin', $this->deployed, $this->admin);
    }

    public function test_a_reopened_suggestion_cannot_fall_through_to_bug_resolution(): void
    {
        $bug = $this->bug('new');
        self::assertTrue(BugReportService::closeAsLogged($bug, $this->admin, 'ux_friction', $this->issueUrl(), '已記錄。')['ok']);
        BugReportService::addReporterCommentAndReopen($bug, $this->reporter, '這裡還有問題');
        self::assertSame('triaged', BugReport::query()->whereKey($bug)->value('status'));
        $this->allowRequester();
        $id = $this->drafted($bug); // even the valid bug retest wording must not reclassify the UX item
        self::assertContains('non_bug_requires_review', $this->dryRunErrors($id));
        self::assertSame(0, BugReportEvidence::query()->where('bug_report_id', $bug)->count());
        self::assertSame(2, BugReportComment::query()->where('bug_report_id', $bug)->count());
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['completed'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['new_reporter_reply'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['waiting_on_reporter'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['receipt_write_failed'])]
    public function test_a_verified_support_reply_can_only_reclose_a_reopened_suggestion_after_human_approval(string $outcome): void
    {
        $bug = $this->bug('new');
        $issueUrl = $this->issueUrl();
        self::assertTrue(BugReportService::closeAsLogged($bug, $this->admin, 'suggestion', $issueUrl, '已收進產品清單。')['ok']);
        BugReportService::addReporterCommentAndReopen($bug, $this->reporter, '想補充：希望先保留時段，稍後再填學生資料。');
        $report = BugReport::query()->findOrFail($bug);
        self::assertSame('triaged', $report->status);

        $issuer = User::query()->findOrFail($this->admin);
        $client = InappAgentClients::issue($issuer, 'suggestion follow-up test', [1]);
        $actor = InappAgentClients::actor((int) $client['record']['id']);
        $publicReply = $outcome === 'waiting_on_reporter'
            ? '請提供看不到入口的日期和畫面，我們再核對。'
            : '現在可以從課表先暫留時段，等確認後再補資料。';
        $followup = $this->service->createDraft(InappReportFollowupCommentStrategy::OPERATION, [
            'bug_id' => $bug,
            'campus_id' => 1,
            'expected_status' => 'triaged',
            'github_issue_url' => $issueUrl,
            'public_reply' => $publicReply . '追蹤：' . $issueUrl,
            'request_id' => 'suggestion-followup',
            'writeback_proof' => $this->proof($bug),
        ], 'suggestion-followup', $actor, 'pop_machine', [1]);
        $followupId = (string) $followup['id'];
        $this->service->runDryRun($followupId, $actor, null, 'pop_machine', [1]);
        self::assertSame([$followupId], $this->policy->approvePending($this->service)['approved']);
        self::assertSame('succeeded', $this->service->runApprovedLocally($followupId)['status']);

        $comments = BugReportComment::query()->where('bug_report_id', $bug)->orderBy('id')->get();
        $replyId = (int) $comments->last()->id;
        $origin = InappSupportCommentOrigin::receipts($report->fresh(), [$comments->last()]);
        self::assertSame(InappReportFollowupCommentStrategy::OPERATION, $origin[$replyId]['operation'] ?? null);
        self::assertCount(3, $comments);

        $resolve = $this->drafted($bug, null, 'suggestion-release', $actor);
        self::assertSame(['non_bug_requires_review'], $this->dryRunErrors($resolve));
        self::assertSame([], $this->policy->approvePending($this->service)['approved']);
        self::assertSame('triaged', $report->fresh()->status);
        self::assertSame($comments->modelKeys(), BugReportComment::query()->where('bug_report_id', $bug)->orderBy('id')->pluck('id')->all());
        self::assertSame(0, BugReportEvidence::query()->where('bug_report_id', $bug)->count());

        $params = $this->params($bug, ['public_reply' => $comments->last()->body, 'request_id' => 'suggestion-reclose']);
        if ($outcome === 'waiting_on_reporter') {
            $this->remote['issue_state'] = 'closed';
            $blocked = $this->drafted($bug, $params, 'suggestion-awaiting-information', $actor);
            self::assertContains('non_bug_requires_review', $this->dryRunErrors($blocked));
            self::assertSame([], $this->policy->approvePending($this->service)['approved']);
            self::assertSame('triaged', $report->fresh()->status);
            self::assertSame(0, BugReportEvidence::query()->where('bug_report_id', $bug)->count());

            return;
        }
        $beforeDelivery = $this->drafted($bug, $params, 'suggestion-before-delivery', $actor);
        self::assertContains('issue_source_ref_untrusted', $this->dryRunErrors($beforeDelivery));
        $this->remote['issue_state'] = 'closed';
        $params['request_id'] = 'suggestion-reclose-confirmed';
        $reclose = $this->drafted($bug, $params, 'suggestion-reclose', $actor);
        self::assertSame([], $this->dryRunErrors($reclose));
        self::assertSame([], $this->policy->approvePending($this->service)['approved']);
        $this->service->approve($reclose, 'reopened-suggestion-review', 'user:' . $this->admin, 'super_admin', $this->deployed, $this->admin);
        if ($outcome === 'new_reporter_reply') {
            BugReportService::addReporterCommentAndReopen($bug, $this->reporter, '補充：別的分頁還是找不到。');
            self::assertSame('failed', $this->service->runApprovedLocally($reclose)['status']);
            self::assertSame('triaged', $report->fresh()->status);
            self::assertSame(4, BugReportComment::query()->where('bug_report_id', $bug)->count());
            self::assertSame(0, BugReportEvidence::query()->where('bug_report_id', $bug)->count());

            return;
        }
        if ($outcome === 'receipt_write_failed') {
            $injected = false;
            DB::connection()->beforeExecuting(function (string $sql, array $bindings) use (&$injected): void {
                if (!$injected && str_starts_with(strtolower($sql), 'insert')
                    && str_contains($sql, '`pop_execution_records`') && in_array('execute', $bindings, true)) {
                    $injected = true;
                    throw new RuntimeException('synthetic execute receipt persistence failure');
                }
            });
            self::assertSame('failed', $this->service->runApprovedLocally($reclose)['status']);
            self::assertTrue($injected);
            self::assertSame('triaged', $report->fresh()->status);
            self::assertSame(3, BugReportComment::query()->where('bug_report_id', $bug)->count());
            self::assertSame(2, BugReportStatusLog::query()->where('bug_report_id', $bug)->count());
            self::assertSame(0, BugReportEvidence::query()->where('bug_report_id', $bug)->count());

            return;
        }
        self::assertSame('succeeded', $this->service->runApprovedLocally($reclose)['status']);
        self::assertSame('closed', $report->fresh()->status);
        self::assertSame($comments->modelKeys(), BugReportComment::query()->where('bug_report_id', $bug)->orderBy('id')->pluck('id')->all());
        self::assertSame($origin, InappSupportCommentOrigin::receipts($report->fresh(), [$comments->last()]));
        $evidence = BugReportEvidence::query()->where('bug_report_id', $bug)->sole();
        self::assertSame($replyId, $evidence->metadata['reused_comment_id']);
        self::assertSame($this->deployed, $evidence->production_revision);
        self::assertTrue(BugReportService::getDetail($bug)['product_loop']['shipped']);
        BugReportService::addReporterCommentAndReopen($bug, $this->reporter, '仍有問題，請重新看一下。');
        self::assertSame('triaged', $report->fresh()->status);
        self::assertFalse(BugReportService::getDetail($bug)['product_loop']['shipped']);
    }

    public function test_self_filed_admin_can_receive_shipping_attributed_to_the_existing_issuer(): void
    {
        $bug = $this->bug('new');
        self::assertTrue(BugReportService::closeAsLogged($bug, $this->admin, 'suggestion', $this->issueUrl(), '已記錄。')['ok']);
        $reporter = User::query()->findOrFail($this->reporter);
        $reporter->type = 'S';
        $reporter->save();
        $client = InappAgentClients::issue($reporter, 'own report agent', [1]);
        $id = $this->drafted($bug, null, 'own-ship-001', InappAgentClients::actor((int) $client['record']['id']));
        self::assertSame([], $this->dryRunErrors($id));
        self::assertSame([$id], $this->policy->approvePending($this->service)['approved']);
        self::assertSame('succeeded', $this->service->runApprovedLocally($id)['status']);
        self::assertSame(2, BugReportComment::query()->where('bug_report_id', $bug)->count());
        self::assertSame('closed', BugReport::query()->whereKey($bug)->value('status'));
    }

    public function test_self_filed_logged_cycle_recognizes_its_real_machine_receipt_and_never_verifies_for_the_reporter(): void
    {
        [$bug, $actor] = $this->selfFiledLoggedReceipt();
        $reply = '配色已更新，重新開啟學生清單即可查看。想補充請直接回覆。追蹤：' . $this->issueUrl();
        $id = $this->drafted($bug, $this->params($bug, ['public_reply' => $reply]), 'self-ship', $actor);
        self::assertSame([], $this->dryRunErrors($id));
        self::assertSame([$id], $this->policy->approvePending($this->service)['approved']);
        self::assertSame('succeeded', $this->service->runApprovedLocally($id)['status']);
        self::assertSame('closed', BugReport::query()->whereKey($bug)->value('status'));
        self::assertSame(2, BugReportComment::query()->where('bug_report_id', $bug)->count());
        self::assertSame(1, BugReportEvidence::query()->where('bug_report_id', $bug)->count());
        $loop = BugReportService::getDetail($bug)['product_loop'];
        self::assertSame(['ux_friction', true, 'SHIPPED', true], [$loop['disposition'], $loop['engineering_required'], $loop['semantic_phase'], $loop['shipped']]);
        self::assertSame([['new', 'closed']], BugReportStatusLog::query()->where('bug_report_id', $bug)->orderBy('id')->get()->map(fn ($l) => [$l->from_status, $l->to_status])->all());
    }

    public function test_logged_resolve_origin_retains_the_closed_from_status_guard(): void
    {
        [$bug, $actor] = $this->selfFiledLoggedReceipt();
        $id = $this->drafted($bug, $this->params($bug, ['public_reply' => '配色已更新，想補充請直接回覆。追蹤：'.$this->issueUrl()]), 'origin-ship', $actor);
        self::assertSame([$id], $this->policy->approvePending($this->service)['approved']);
        self::assertSame('succeeded', $this->service->runApprovedLocally($id)['status']);
        $records = DB::table('pop_execution_records')->where('operation_id', $id)->where('phase', 'execute');
        $receipt = json_decode($records->value('payload'), true); $model = BugReport::findOrFail($bug);
        self::assertSame([], InappReportReplyGuard::loggedReportViolations($model, $this->admin, $receipt['logged_close'], $this->issueUrl()));
        foreach (['triaged', null] as $from) {
            $bad = $receipt;
            if ($from === null) unset($bad['from_status']); else $bad['from_status'] = $from;
            $records->update(['payload' => json_encode($bad)]);
            self::assertContains('reporter_reply_pending', InappReportReplyGuard::loggedReportViolations($model, $this->admin, $receipt['logged_close'], $this->issueUrl()));
        }
    }

    public static function selfFiledReceiptFaults(): array
    {
        $keys = ['missing', 'failed', 'dry-run', 'verify-only', 'comment_id', 'author_id', 'bug_id', 'reply_sha256',
            'logged_close_id', 'disposition', 'github_issue', 'wrong-operation', 'wrong-strategy', 'parameters-hash', 'copied-body',
            'request-bug', 'request-campus', 'request-issue', 'request-body', 'changed-comment-body', 'missing-reply-hash'];

        return array_combine($keys, array_map(fn ($key) => [$key], $keys));
    }

    /** @dataProvider selfFiledReceiptFaults */
    public function test_self_filed_reporter_comment_requires_exact_successful_machine_origin(string $fault): void
    {
        [$bug, $actor, $receiptId] = $this->selfFiledLoggedReceipt();
        $records = DB::table('pop_execution_records')->where('operation_id', $receiptId)->where('phase', 'execute');
        $record = $records->first();
        $payload = json_decode($record->payload, true);
        if (in_array($fault, ['missing', 'verify-only'], true)) $records->delete();
        elseif ($fault === 'failed') $records->update(['result' => 'failed']);
        elseif ($fault === 'dry-run') $records->update(['phase' => 'dry-run', 'attempt_no' => 99, 'attempt_key' => 'synthetic-dry-run-99']);
        elseif ($fault === 'wrong-operation') DB::table('pop_operation_requests')->where('id', $receiptId)->update(['operation_id' => 'inapp-report-progress-reply']);
        elseif ($fault === 'wrong-strategy') DB::table('pop_operation_requests')->where('id', $receiptId)->update(['strategy_id' => InappReportResolveAfterDeployStrategy::class]);
        elseif ($fault === 'parameters-hash') DB::table('pop_operation_requests')->where('id', $receiptId)->update(['parameters_hash' => str_repeat('0', 64)]);
        elseif (str_starts_with($fault, 'request-')) {
            $request = DB::table('pop_operation_requests')->where('id', $receiptId)->first();
            $p = json_decode($request->parameters, true);
            $key = ['request-bug' => 'bug_id', 'request-campus' => 'campus_id', 'request-issue' => 'github_issue_url', 'request-body' => 'public_reply'][$fault];
            $p[$key] = is_int($p[$key]) ? $p[$key] + 100 : $p[$key] . '1';
            DB::table('pop_operation_requests')->where('id', $receiptId)->update(['parameters' => json_encode($p),
                'parameters_hash' => hash('sha256', json_encode(PopOperationService::canonicalParameters($p)))]);
        } elseif ($fault === 'changed-comment-body') BugReportComment::query()->where('bug_report_id', $bug)->update(['body' => '不同的留言。']);
        elseif ($fault === 'missing-reply-hash') {
            unset($payload['reply_sha256']);
            $records->update(['payload' => json_encode($payload)]);
        }
        elseif ($fault === 'copied-body') {
            $comment = BugReportComment::query()->where('bug_report_id', $bug)->sole();
            BugReportComment::create(['bug_report_id' => $bug, 'author_user_id' => $this->admin, 'body' => $comment->body, 'is_internal_note' => false, 'created_at' => now()]);
        } else {
            $payload[$fault] = match ($fault) {
                'reply_sha256' => str_repeat('0', 64), 'disposition' => 'bug', default => ((int) $payload[$fault]) + 100,
            };
            $records->update(['payload' => json_encode($payload)]);
        }
        $rid = 'guard-' . str_replace('_', '-', $fault);
        $id = $this->drafted($bug, $this->params($bug, ['request_id' => $rid,
            'public_reply' => '配色已更新，請重新開啟學生清單。追蹤：' . $this->issueUrl()]), $rid, $actor);
        self::assertContains('reporter_reply_pending', $this->dryRunErrors($id));
        self::assertSame(0, BugReportEvidence::query()->where('bug_report_id', $bug)->count());
        self::assertSame($fault === 'copied-body' ? 2 : 1, BugReportComment::query()->where('bug_report_id', $bug)->count());
    }

    public function test_self_filed_missing_receipt_fails_closed_until_its_successful_execute_record_is_persisted(): void
    {
        [$bug, $actor, $receiptId] = $this->selfFiledLoggedReceipt();
        $record = DB::table('pop_execution_records')->where('operation_id', $receiptId)->where('phase', 'execute')->first();
        DB::table('pop_execution_records')->where('id', $record->id)->delete();
        $reply = '配色已更新，請重新開啟學生清單。追蹤：' . $this->issueUrl();
        $missing = $this->drafted($bug, $this->params($bug, ['public_reply' => $reply]), 'missing-receipt', $actor);
        self::assertContains('reporter_reply_pending', $this->dryRunErrors($missing));
        DB::table('pop_execution_records')->insert((array) $record);
        // Origin does not depend on a later verify record or the request's current status.
        DB::table('pop_execution_records')->where('operation_id', $receiptId)->where('phase', 'verify')->delete();
        DB::table('pop_operation_requests')->where('id', $receiptId)->update(['status' => 'executed']);
        $late = $this->drafted($bug, $this->params($bug, ['public_reply' => $reply]), 'late-receipt', $actor);
        self::assertSame([], $this->dryRunErrors($late));
        self::assertSame(1, BugReportComment::query()->where('bug_report_id', $bug)->count());
    }

    private function selfFiledLoggedReceipt(): array
    {
        $bug = $this->bug('new');
        BugReport::query()->whereKey($bug)->update(['reporter_user_id' => $this->admin]);
        $client = InappAgentClients::issue(User::query()->findOrFail($this->admin), 'self filed agent', [1]);
        $actor = InappAgentClients::actor((int) $client['record']['id']);
        $p = ['bug_id' => $bug, 'campus_id' => 1, 'request_id' => 'self-receipt', 'disposition' => 'ux_friction',
            'github_issue_url' => $this->issueUrl(), 'writeback_proof' => $this->proof($bug),
            'public_reply' => '已收進產品清單 ' . $this->issueUrl() . '。上線時會在這裡通知你；想補充直接回覆就會重開。'];
        $draft = $this->service->createDraft(InappReportTriageStrategy::OPERATION, $p, 'self-receipt-' . $bug, $actor, 'pop_machine', [1]);
        $rid = (string) $draft['id'];
        $this->service->runDryRun($rid, $actor, null, 'pop_machine', [1]);
        self::assertSame([], $this->dryRunErrors($rid));
        self::assertSame([$rid], $this->policy->approvePending($this->service)['approved']);
        self::assertSame('succeeded', $this->service->runApprovedLocally($rid)['status']);

        return [$bug, $actor, $rid];
    }

    public function test_shipping_rolls_back_production_evidence_when_the_public_reply_fails(): void
    {
        $bug = $this->bug('new');
        self::assertTrue(BugReportService::closeAsLogged($bug, $this->admin, 'suggestion', $this->issueUrl(), '已記錄。')['ok']);
        $this->allowRequester();
        $id = $this->drafted($bug);
        $strategy = app(InappReportResolveAfterDeployStrategy::class);
        $plan = $strategy->plan($this->params($bug), ['operation_id' => $id]);
        self::assertTrue($plan['ok']);
        BugReportComment::creating(static function ($comment): void {
            if (str_contains($comment->body, '已修好並上線')) throw new RuntimeException('synthetic comment failure');
        });
        self::assertSame(['inapp_resolve_failed'], $strategy->execute($plan, ['operation_id' => $id])['errors']);
        self::assertSame(0, BugReportEvidence::query()->where('bug_report_id', $bug)->count());
        self::assertSame(1, BugReportComment::query()->where('bug_report_id', $bug)->count());
        self::assertSame('closed', BugReport::query()->whereKey($bug)->value('status'));
    }

    public function test_shipping_refuses_a_reopened_and_reclosed_cycle_even_when_the_status_is_closed_again(): void
    {
        $bug = $this->bug('new');
        self::assertTrue(BugReportService::closeAsLogged($bug, $this->admin, 'ux_friction', $this->issueUrl(), '已記錄。')['ok']);
        $this->allowRequester();
        $id = $this->drafted($bug);
        $strategy = app(InappReportResolveAfterDeployStrategy::class);
        $plan = $strategy->plan($this->params($bug), ['operation_id' => $id]);
        self::assertTrue($plan['ok']);
        BugReportService::addReporterCommentAndReopen($bug, $this->reporter, '補充說明');
        BugReportService::addComment($bug, $this->admin, '已讀到補充，保留這項建議。');
        self::assertTrue(BugReportService::closeAsLogged($bug, $this->admin, 'ux_friction', $this->issueUrl(), '已再記錄。')['ok']);
        self::assertSame(['inapp_resolve_status_changed'], $strategy->execute($plan, ['operation_id' => $id])['errors']);
        self::assertSame('closed', BugReport::query()->whereKey($bug)->value('status'));
        self::assertSame(4, BugReportComment::query()->where('bug_report_id', $bug)->count());
        self::assertSame(0, BugReportEvidence::query()->where('bug_report_id', $bug)->count());
    }

    public function test_unrelated_evidence_namespace_does_not_count_as_a_prior_shipping_notice(): void
    {
        $bug = $this->bug('new');
        self::assertTrue(BugReportService::closeAsLogged($bug, $this->admin, 'suggestion', $this->issueUrl(), '已記錄。')['ok']);
        $close = BugReportStatusLog::query()->where('bug_report_id', $bug)->where('to_status', 'closed')->max('id');
        BugReportEvidence::create(['bug_report_id' => $bug, 'evidence_type' => BugReportEvidence::TYPE_RESOLUTION_PRODUCTION_VERIFICATION,
            'production_revision' => $this->unrelated, 'deploy_run_id' => '1', 'source_ref' => 'loggedXship:' . $close . ':' . $this->unrelated,
            'verified_by' => $this->admin, 'verified_at' => now(), 'created_at' => now()]);
        $this->allowRequester();
        $id = $this->drafted($bug);
        self::assertSame([], $this->dryRunErrors($id));
    }

    public function test_shipping_rechecks_human_fallback_attribution_after_role_revocation(): void
    {
        User::query()->whereKey($this->reporter)->update(['type' => 'S']);
        $bug = $this->bug('new');
        self::assertTrue(BugReportService::closeAsLogged($bug, $this->admin, 'ux_friction', $this->issueUrl(), '已記錄。')['ok']);
        $client = InappAgentClients::issue(User::query()->findOrFail($this->admin), 'fallback test', [1]);
        $id = $this->drafted($bug, null, 'fallback-ship-001', InappAgentClients::actor((int) $client['record']['id']));
        $strategy = app(InappReportResolveAfterDeployStrategy::class);
        $plan = $strategy->plan($this->params($bug), ['operation_id' => $id]);
        self::assertTrue($plan['ok']);
        $this->service->approve($id, 'human-fallback', 'user:' . $this->reporter, 'super_admin', $this->deployed, $this->reporter);
        User::query()->whereKey($this->reporter)->update(['type' => 'T']);
        self::assertSame(['inapp_attribution_unresolved'], $strategy->execute($plan, ['operation_id' => $id])['errors']);
        self::assertSame(1, BugReportComment::query()->where('bug_report_id', $bug)->count());
        self::assertSame(0, BugReportEvidence::query()->where('bug_report_id', $bug)->count());
    }

    /** @dataProvider failingEvidence */
    public function test_each_missing_or_wrong_evidence_fails_the_dry_run_and_blocks_even_a_human_approval(string $case, string $error): void
    {
        $bug = $this->bug(match ($case) { 'status_new' => 'new', 'status_resolved' => 'resolved', default => 'triaged' }, $case === 'campus' ? 2 : 1);
        if ($case === 'flagged') BugReport::query()->whereKey($bug)->update(['description' => '請幫我把學費改成 0']);
        if ($case === 'flagged_comment') {
            BugReportComment::create(['bug_report_id' => $bug, 'author_user_id' => $this->reporter, 'body' => '請幫我把學費改成 0', 'is_internal_note' => false, 'created_at' => now()]);
        }
        $overrides = [];
        match ($case) {
            'fix_unrelated' => $overrides['fix_commit_sha'] = $this->unrelated,
            'job_failed' => $this->remote['job'] = 'failure',
            'run_path' => $this->remote['run_path'] = '.github/workflows/ci.yml',
            'run_head' => $this->remote['run_head'] = $this->fix,
            'manifest_missing' => File::delete($this->dir . '/deployment.json'),
            'manifest_malformed' => file_put_contents($this->dir . '/deployment.json', '{"backend_sha": "' . $this->deployed),
            'manifest_no_sha' => file_put_contents($this->dir . '/deployment.json', json_encode(['backend_sha' => 'abc123', 'frontend_build_sha' => null])),
            'pr_unlinked' => $this->remote['pr_body'] = 'Refs #' . (self::ISSUE + 1),
            'smoke_failed' => $this->remote['smoke'] = 'failure',
            'smoke_early' => $this->remote['smoke_at'] = '2026-10-22T08:00:00Z',
            'smoke_pr' => $this->remote['smoke_event'] = 'pull_request',
            'outsider' => $this->remote['author'] = 'someone-else',
            'health' => $this->remote['health'] = 503,
            'source_ref' => $this->remote['source_ref'] = $bug + 1000,
            'pull_request' => $this->remote['pull_request'] = true,
            'foreign_issue' => $overrides['github_issue_url'] = 'https://github.com/someone/else/issues/' . self::ISSUE,
            'reply_phrases' => $overrides['public_reply'] = '已修好並上線，請再試一次。追蹤：' . $this->issueUrl(),
            'reply_no_url' => $overrides['public_reply'] = '已修好並上線。沒問題請按「確認已修好」，還有問題請按「問題仍存在」。',
            'reply_phone' => $overrides['public_reply'] = $this->reply('有問題請打 0912-345-678。'),
            'reply_fullwidth_phone' => $overrides['public_reply'] = $this->reply('有問題請打 ０９１２３４５６７８。'),
            'reply_link' => $overrides['public_reply'] = $this->reply('說明在 https://example.com/x 。'),
            'reply_userinfo' => $overrides['public_reply'] = $this->reply() . '@evil.example',
            'reply_long' => $overrides['public_reply'] = $this->reply(str_repeat('很', 600)),
            'smoke' => $overrides['smoke_result_ref'] = '',
            'proof_expired' => $overrides['writeback_proof'] = $this->proof($bug, Carbon::now()->subMinutes(16)),
            'proof_changed' => null,
            'proof_forged' => $overrides['writeback_proof'] = ['captured_at' => Carbon::now()->toISOString(), 'proof' => str_repeat('0', 64)],
            default => null,
        };
        $params = $this->params($bug, $overrides);
        if ($case === 'proof_changed') {
            BugReportComment::create(['bug_report_id' => $bug, 'author_user_id' => $this->reporter, 'body' => '補充', 'is_internal_note' => false, 'created_at' => now()]);
        }
        $id = $this->drafted($bug, $params);
        // Without intl the guard cannot fold full-width digits, so it refuses the reply outright.
        if ($case === 'reply_fullwidth_phone' && !class_exists(\Normalizer::class)) $error = 'reply_unnormalizable';

        self::assertContains($error, $this->dryRunErrors($id));
        self::assertSame('failed', $this->requestStatus($id));
        $this->assertNothingWritten($bug, in_array($case, ['proof_changed', 'flagged_comment'], true) ? 1 : 0);
    }

    public static function movedAfterThePlan(): array
    {
        return ['reporter wrote again' => ['comment', 'inapp_resolve_writeback_proof_stale', 1],
            'Pi runs another deployment' => ['deployment', 'inapp_resolve_deployment_changed', 0],
            'status changed' => ['status', 'inapp_resolve_status_changed', 0]];
    }

    /** @dataProvider movedAfterThePlan */
    public function test_execute_rechecks_under_the_row_lock_and_writes_nothing_when_something_moved(string $moved, string $error, int $comments): void
    {
        $bug = $this->bug('triaged');
        $id = $this->drafted($bug);
        $strategy = $this->app->make(InappReportResolveAfterDeployStrategy::class);
        $plan = $strategy->plan($this->params($bug));
        self::assertTrue($plan['ok'], implode(',', $plan['errors']));
        $this->service->approve($id, 'inapp-human-review', 'user:' . $this->admin, 'super_admin', $this->deployed, $this->admin);
        match ($moved) { // between the last plan and the locked write
            'comment' => BugReportComment::create(['bug_report_id' => $bug, 'author_user_id' => $this->reporter, 'body' => '還是不行', 'is_internal_note' => false, 'created_at' => now()]),
            'deployment' => $this->writeManifest($this->unrelated),
            'status' => BugReport::query()->whereKey($bug)->update(['status' => 'closed']),
        };

        self::assertSame(['ok' => false, 'errors' => [$error], 'bug_id' => $bug], $strategy->execute($plan, ['operation_id' => $id]));
        self::assertSame($comments, BugReportComment::query()->where('bug_report_id', $bug)->count());
        self::assertSame(0, BugReportStatusLog::query()->where('bug_report_id', $bug)->count());
    }

    public function test_a_refusal_at_the_resolved_step_rolls_back_the_reply_and_the_in_progress_step(): void
    {
        $bug = $this->bug('triaged');
        // A reopen stamped in the future: the resolved step does not count the new reply and refuses.
        BugReportStatusLog::create(['bug_report_id' => $bug, 'changed_by' => $this->admin, 'from_status' => 'resolved', 'to_status' => 'triaged',
            'note' => 'reopen', 'created_at' => now()->addHour()]);
        $id = $this->drafted($bug);
        $strategy = $this->app->make(InappReportResolveAfterDeployStrategy::class);
        $plan = $strategy->plan($this->params($bug));
        $this->service->approve($id, 'inapp-human-review', 'user:' . $this->admin, 'super_admin', $this->deployed, $this->admin);

        self::assertSame(['inapp_resolve_rejected_missing_both_outcomes_reply'], $strategy->execute($plan, ['operation_id' => $id])['errors']);
        self::assertSame(['triaged', 0, 1], [BugReport::query()->whereKey($bug)->value('status'),
            BugReportComment::query()->where('bug_report_id', $bug)->count(), BugReportStatusLog::query()->where('bug_report_id', $bug)->count()]);
    }

    public function test_execute_rechecks_the_policy_kill_switches_inside_the_write_transaction(): void
    {
        $this->allowRequester();
        $bug = $this->bug('triaged');
        $id = $this->drafted($bug);
        $this->policy->approvePending($this->service);
        $strategy = $this->app->make(InappReportResolveAfterDeployStrategy::class);
        $plan = $strategy->plan($this->params($bug));
        config(['pop_inapp.standing_policy_enabled' => false]); // flipped after the executor claimed the request

        self::assertSame(['inapp_standing_policy_kill_switch'], $strategy->execute($plan, ['operation_id' => $id])['errors']);
        $this->assertNothingWritten($bug, 0);
    }

    public function test_evidence_that_goes_bad_after_a_human_approval_stops_the_local_executor(): void
    {
        $bug = $this->bug('triaged');
        $id = $this->drafted($bug);
        $this->service->approve($id, 'inapp-human-review', 'user:' . $this->admin, 'super_admin', $this->deployed, $this->admin);
        $this->remote['health'] = 503;

        $this->service->runApprovedLocally($id);
        self::assertSame('failed', $this->requestStatus($id));
        $this->assertNothingWritten($bug, 0);
    }

    public static function outOfScope(): array
    {
        return ['another campus' => [[2]], 'empty scope' => [[]]];
    }

    /** @dataProvider outOfScope */
    public function test_a_requester_outside_the_report_campus_cannot_even_draft(array $scope): void
    {
        $bug = $this->bug('triaged');
        $this->expectException(RuntimeException::class);
        $this->service->createDraft(self::OP, $this->params($bug), 'idem-scope', 'api-client:7', 'pop_machine', $scope);
    }

    private function drafted(int $bug, ?array $params = null, string $rid = 'req-001', string $actor = 'api-client:7'): string
    {
        $draft = $this->service->createDraft(self::OP, $params ?? $this->params($bug, ['request_id' => $rid]), 'idem-' . $rid . '-' . $bug,
            $actor, 'pop_machine', [1]);
        $this->service->runDryRun((string) $draft['id'], $actor, null, 'pop_machine', [1]);

        return (string) $draft['id'];
    }

    private function params(int $bug, array $overrides = []): array
    {
        return array_merge(['bug_id' => $bug, 'campus_id' => 1, 'deploy_run_id' => self::RUN, 'fix_commit_sha' => $this->fix,
            'github_issue_url' => $this->issueUrl(), 'public_reply' => $this->reply(), 'request_id' => 'req-001',
            'smoke_result_ref' => 'run:' . self::SMOKE, 'writeback_proof' => $this->proof($bug)], $overrides);
    }

    private function proof(int $bug, ?Carbon $at = null): array
    {
        $now = Carbon::now();
        Carbon::setTestNow($at ?? $now);
        $proof = BugReportWritebackEvidence::capture(BugReport::query()->findOrFail($bug));
        Carbon::setTestNow($now);

        return $proof;
    }

    private function reply(string $extra = ''): string
    {
        return '已修好並上線，重新整理後再試一次。' . $extra . '沒問題請按「確認已修好」，還有問題請按「問題仍存在」。追蹤：' . $this->issueUrl();
    }

    private function issueUrl(): string
    {
        return 'https://github.com/' . self::REPO . '/issues/' . self::ISSUE;
    }

    private function bug(string $status, int $campus = 1): int
    {
        return BugReport::create(['CampusID' => $campus, 'reporter_user_id' => $this->reporter, 'title' => 'T', 'description' => 'D',
            'severity' => 'low', 'status' => $status])->id;
    }

    public function test_dry_run_plan_reports_the_shas_actually_read_and_never_the_manifest_path(): void
    {
        $this->remote['run_head'] = $this->fix; // a run that is not what the Pi runs
        $other = str_repeat('c', 40);
        file_put_contents($this->dir . '/deployment.json', json_encode(['backend_sha' => strtoupper($this->deployed), 'frontend_build_sha' => $other]));
        $bug = $this->bug('triaged');
        $id = $this->drafted($bug);

        $payload = json_decode((string) DB::table('pop_execution_records')->where('operation_id', $id)->where('phase', 'dry-run')->value('payload'), true);
        self::assertContains('deploy_run_not_in_production', $payload['plan']['errors']);
        self::assertNotContains('deployment_manifest_unreadable', $payload['plan']['errors']);
        self::assertSame('ok', $payload['plan']['manifest_state']);
        self::assertSame('override', $payload['plan']['manifest_source']); // setUp points the config override at the test manifest
        self::assertSame([$this->deployed, $other], $payload['plan']['manifest_shas']);
        self::assertStringNotContainsString($this->dir, json_encode($payload));
        self::assertStringNotContainsString('deployment.json', json_encode($payload));
    }

    public function test_the_env_override_moves_the_evidence_reader_but_never_the_executor_revision_binding(): void
    {
        $default = base_path(\App\Operations\Deployment\DeploymentManifest::RELATIVE_PATH);
        if (is_file($default)) $this->markTestSkipped('a real manifest exists at the default location');
        $catalog = new PopOperationCatalog($this->dir . '/catalog.yaml');
        $executor = new PopOperationService($catalog); // production wiring: no explicit manifest path
        $read = new \ReflectionMethod($executor, 'localDeploymentSha');

        self::assertSame([$this->deployed], (new \App\Operations\Inapp\InappReportResolveEvidence())->deployedShas());
        try {
            $read->invoke($executor);
            self::fail('the executor must not read the override');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('fail closed', $e->getMessage());
        }
    }

    public function test_an_unreadable_manifest_is_reported_by_state_without_shas(): void
    {
        File::delete($this->dir . '/deployment.json');
        $id = $this->drafted($this->bug('triaged'));

        $payload = json_decode((string) DB::table('pop_execution_records')->where('operation_id', $id)->where('phase', 'dry-run')->value('payload'), true);
        self::assertSame(['deployment_manifest_unreadable'], $payload['plan']['errors']);
        self::assertSame(['missing', []], [$payload['plan']['manifest_state'], $payload['plan']['manifest_shas']]);
        self::assertSame('failed', $this->requestStatus($id));
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

    private function assertNothingWritten(int $bug, int $comments): void
    {
        self::assertSame(0, BugReportStatusLog::query()->where('bug_report_id', $bug)->count());
        self::assertSame($comments, BugReportComment::query()->where('bug_report_id', $bug)->count());
    }

    private function respond(Request $request)
    {
        $api = 'https://api.github.com/repos/' . self::REPO . '/';
        $bug = $this->remote['source_ref'] ?? (int) BugReport::query()->max('id');
        $url = $request->url();
        $run = fn (string $id, string $path, string $conclusion, string $at) => Http::response(['id' => (int) $id, 'path' => $path,
            'head_sha' => $this->remote['run_head'], 'head_branch' => 'main', 'event' => $this->remote['smoke_event'], 'conclusion' => $conclusion, 'run_started_at' => $at]);

        return match (true) {
            $url === 'https://health.test/api/v1/health' => Http::response(['ok' => true], $this->remote['health']),
            str_starts_with($url, $api . 'actions/runs/' . self::RUN . '/jobs?') => Http::response(['jobs' => [
                ['name' => 'Detect deployable changes', 'conclusion' => 'success', 'completed_at' => '2026-10-22T08:50:00Z'],
                ['name' => 'Deploy to Production', 'conclusion' => $this->remote['job'], 'completed_at' => '2026-10-22T09:00:00Z']]]),
            $url === $api . 'actions/runs/' . self::RUN => $run(self::RUN, $this->remote['run_path'], 'success', '2026-10-22T08:40:00Z'),
            $url === $api . 'actions/runs/' . self::SMOKE => $run(self::SMOKE, '.github/workflows/ui-smoke.yml', $this->remote['smoke'], $this->remote['smoke_at']),
            str_starts_with($url, $api . "compare/{$this->fix}...{$this->deployed}") => Http::response(['status' => 'ahead', 'merge_base_commit' => ['sha' => $this->fix]]),
            str_starts_with($url, $api . 'compare/') => Http::response(['status' => 'diverged', 'merge_base_commit' => ['sha' => str_repeat('b', 40)]]),
            str_starts_with($url, $api . 'commits/') => Http::response([['number' => 900, 'merged_at' => '2026-10-22T07:00:00Z', 'title' => 'fix', 'body' => $this->remote['pr_body']]]),
            str_starts_with($url, $api . 'issues/' . self::ISSUE . '/comments') => Http::response([]),
            $url === $api . 'issues/' . self::ISSUE => Http::response(['number' => self::ISSUE, 'repository_url' => rtrim($api, '/'),
                'state' => $this->remote['issue_state'] ?? 'open', 'state_reason' => isset($this->remote['issue_state']) ? 'completed' : null,
                'user' => ['login' => $this->remote['author']], 'body' => "SourceRef: alltrue:bug_report:{$bug}\n\n- In-app ID: {$bug}"]
                + ($this->remote['pull_request'] ? ['pull_request' => ['url' => 'x']] : [])),
            default => Http::response('', 599), // never reach the network
        };
    }

    private function allowRequester(): void
    {
        $this->app->instance(InappRequesterGuard::class, new class extends InappRequesterGuard {
            public function allows(object $request): bool { return true; }
            public function attributionUserId(object $request): ?int { return (int) User::query()->where('type', 'S')->value('id'); }
        });
    }

    private function writeManifest(string $sha): void
    {
        file_put_contents($this->dir . '/deployment.json', json_encode(['backend_sha' => $sha, 'frontend_build_sha' => $sha]));
    }
}
