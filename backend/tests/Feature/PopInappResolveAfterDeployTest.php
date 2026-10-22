<?php

namespace Tests\Feature;

use App\Models\BugReport;
use App\Models\BugReportComment;
use App\Models\BugReportStatusLog;
use App\Models\User;
use App\Operations\Inapp\InappRequesterGuard;
use App\Operations\Inapp\InappStandingPolicy;
use App\Operations\PopOperationCatalog;
use App\Operations\PopOperationService;
use App\Operations\Strategies\InappReportResolveAfterDeployStrategy;
use App\Services\BugReportWritebackEvidence;
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
            'manifest unreadable' => ['manifest_missing', 'deploy_run_not_in_production'],
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
            'review proof expired' => ['proof_expired', 'writeback_proof_stale'],
            'report changed since the review' => ['proof_changed', 'writeback_proof_stale'],
            'review proof forged' => ['proof_forged', 'writeback_proof_stale'],
        ];
    }

    /** @dataProvider failingEvidence */
    public function test_each_missing_or_wrong_evidence_fails_the_dry_run_and_blocks_even_a_human_approval(string $case, string $error): void
    {
        $bug = $this->bug(match ($case) { 'status_new' => 'new', 'status_resolved' => 'resolved', default => 'triaged' }, $case === 'campus' ? 2 : 1);
        $overrides = [];
        match ($case) {
            'fix_unrelated' => $overrides['fix_commit_sha'] = $this->unrelated,
            'job_failed' => $this->remote['job'] = 'failure',
            'run_path' => $this->remote['run_path'] = '.github/workflows/ci.yml',
            'run_head' => $this->remote['run_head'] = $this->fix,
            'manifest_missing' => File::delete($this->dir . '/deployment.json'),
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
        $this->assertNothingWritten($bug, $case === 'proof_changed' ? 1 : 0);
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

    private function drafted(int $bug, ?array $params = null, string $rid = 'req-001'): string
    {
        $draft = $this->service->createDraft(self::OP, $params ?? $this->params($bug, ['request_id' => $rid]), 'idem-' . $rid . '-' . $bug,
            'api-client:7', 'pop_machine', [1]);
        $this->service->runDryRun((string) $draft['id'], 'api-client:7', null, 'pop_machine', [1]);

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
