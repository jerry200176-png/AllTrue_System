<?php

namespace Tests\Feature;

use App\Models\{BugReport, BugReportComment, BugReportStatusLog, Campus, User};
use App\Operations\Inapp\{InappAgentClients, InappStandingPolicy};
use App\Operations\{PopOperationCatalog, PopOperationService};
use App\Services\BugReportWritebackEvidence;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, File, Http};
use Tests\TestCase;

/** #5117 / SourceRef530: answer the reporter through the existing separated POP executor, without claiming resolution. */
class PopInappFollowupCommentTest extends TestCase
{
    use RefreshDatabase;
    private const OP = 'inapp-report-followup-comment';
    private string $dir;
    private PopOperationService $service;
    private InappStandingPolicy $policy;
    private string $actor;
    private string $key;
    private int $campus;
    private int $admin;
    private BugReport $bug;

    protected function setUp(): void
    {
        parent::setUp(); Carbon::setTestNow('2026-11-17 10:00:00');
        $this->dir = sys_get_temp_dir().'/pop-followup-'.bin2hex(random_bytes(6));
        File::copyDirectory(dirname(base_path()).'/operations', $this->dir);
        file_put_contents($this->dir.'/deployment.json', json_encode(['backend_sha' => str_repeat('d', 40)]));
        $catalog = new PopOperationCatalog($this->dir.'/catalog.yaml');
        $this->app->instance(PopOperationCatalog::class, $catalog);
        $this->service = new PopOperationService($catalog, null, $this->dir.'/deployment.json');
        $this->policy = new InappStandingPolicy($catalog);
        config(['pop_inapp.standing_policy_enabled' => true, 'pop_inapp.daily_cap' => 20]);
        $this->campus = (int) Campus::factory()->create()->id;
        $admin = User::create(['LoginName' => 'followup-admin', 'Name' => 'Synthetic support', 'PSW' => 'test-only', 'type' => 'S', 'phone' => '0900000000']);
        $this->admin = (int) $admin->id;
        $reporter = User::create(['LoginName' => 'followup-reporter', 'Name' => 'Synthetic reporter', 'PSW' => 'test-only', 'type' => 'T', 'phone' => '0900000001']);
        $issued = InappAgentClients::issue($admin, 'synthetic-followup', [$this->campus]);
        $this->actor = InappAgentClients::actor((int) $issued['record']['id']); $this->key = $issued['key'];
        $this->bug = BugReport::create(['CampusID' => $this->campus, 'reporter_user_id' => $reporter->id,
            'title' => 'synthetic private title', 'description' => 'synthetic original concern', 'status' => 'in_progress', 'severity' => 'low']);
        BugReportComment::create(['bug_report_id' => $this->bug->id, 'author_user_id' => $reporter->id,
            'body' => '課程仍看不到，重新輸入卻顯示已存在。', 'is_internal_note' => false, 'created_at' => now()]);
        $repo = (string) config('pop_inapp.github_repo');
        Http::fake(function ($request) use ($repo) {
            if ($request->url() === 'https://api.github.com/repos/'.$repo.'/issues/500') return Http::response([
                'number' => 500, 'repository_url' => 'https://api.github.com/repos/'.$repo, 'state' => 'open',
                'user' => ['login' => 'github-actions[bot]'], 'body' => 'SourceRef: alltrue:bug_report:'.$this->bug->id]);
            return str_contains($request->url(), '/issues/500/comments') ? Http::response([]) : Http::response('', 599);
        });
    }

    protected function tearDown(): void { Carbon::setTestNow(); File::deleteDirectory($this->dir); parent::tearDown(); }

    public function test_substantive_reply_answers_latest_reporter_once_and_preserves_the_open_case(): void
    {
        $before = $this->bug->fresh()->getAttributes(); $params = $this->params();
        $id = $this->draft($params);
        self::assertSame([$id], $this->policy->approvePending($this->service)['approved']);
        self::assertSame('succeeded', $this->service->runApprovedLocally($id)['status']);
        self::assertSame($before, $this->bug->fresh()->getAttributes());
        self::assertSame(0, BugReportStatusLog::where('bug_report_id', $this->bug->id)->count());
        $reply = BugReportComment::where('bug_report_id', $this->bug->id)->where('author_user_id', $this->admin)->sole();
        self::assertSame($params['public_reply'], $reply->body); self::assertFalse((bool) $reply->is_internal_note);
        $again = $this->service->createDraft(self::OP, $params, 'followup-once', $this->actor, 'pop_machine', [$this->campus]);
        self::assertSame($id, $again['id']);
        self::assertSame(2, BugReportComment::where('bug_report_id', $this->bug->id)->count());
    }

    public function test_a_new_reporter_comment_after_approval_prevents_stale_reply_execution(): void
    {
        $id = $this->draft($this->params());
        self::assertSame([$id], $this->policy->approvePending($this->service)['approved']);
        BugReportComment::create(['bug_report_id' => $this->bug->id, 'author_user_id' => $this->bug->reporter_user_id,
            'body' => '補充：這次是在另一個日期。', 'is_internal_note' => false, 'created_at' => now()]);
        self::assertSame('failed', $this->service->runApprovedLocally($id)['status']);
        self::assertSame(0, BugReportComment::where('bug_report_id', $this->bug->id)->where('author_user_id', $this->admin)->count());
        self::assertSame('in_progress', $this->bug->fresh()->status);
    }

    public function test_a_duplicate_body_cannot_be_posted_by_a_fresh_request(): void
    {
        $params = $this->params(); $id = $this->draft($params);
        $this->policy->approvePending($this->service); $this->service->runApprovedLocally($id);
        $next = $this->draft($this->params(['request_id' => 'followup-two']), 'followup-two');
        self::assertContains('reply_already_posted', $this->dryPlan($next)['errors']);
        self::assertSame(2, BugReportComment::where('bug_report_id', $this->bug->id)->count());
    }

    public function test_http_requester_can_only_draft_and_dry_run_in_its_existing_campus_scope(): void
    {
        $path = '/api/v1/pop/machine/operations/'.self::OP.'/draft';
        $draft = $this->withHeader('X-POP-MACHINE-KEY', $this->key)->postJson($path,
            ['idempotency_key' => 'http-followup', 'parameters' => $this->params()])->assertCreated()->json('data');
        $this->withHeader('X-POP-MACHINE-KEY', $this->key)->postJson('/api/v1/pop/machine/operations/requests/'.$draft['id'].'/dry-run')
            ->assertOk()->assertJsonPath('data.result', 'succeeded');
        $other = (int) Campus::factory()->create()->id;
        $this->withHeader('X-POP-MACHINE-KEY', $this->key)->postJson($path,
            ['idempotency_key' => 'http-foreign', 'parameters' => $this->params(['campus_id' => $other])])->assertForbidden();
        $this->withHeader('X-POP-MACHINE-KEY', $this->key)->postJson('/api/v1/pop/operations/requests/'.$draft['id'].'/approvals',
            ['phrase' => 'not-an-approval'])->assertUnauthorized();
        self::assertSame(0, DB::table('pop_approval_events')->count());
        self::assertSame(1, BugReportComment::where('bug_report_id', $this->bug->id)->count());
    }

    public function test_policy_kill_switch_after_approval_prevents_a_reply(): void
    {
        $id = $this->draft($this->params()); $this->policy->approvePending($this->service);
        config(['pop_inapp.standing_policy_enabled' => false]);
        try { $this->service->runApprovedLocally($id); $this->fail('The native policy kill switch must prevent execution.'); }
        catch (\RuntimeException $e) { self::assertSame('inapp_standing_policy_kill_switch', $e->getMessage()); }
        self::assertSame('cancelled', DB::table('pop_operation_requests')->where('id', $id)->value('status'));
        self::assertTrue(DB::table('pop_approval_events')->where('operation_id', $id)->where('event_type', 'policy_revoked')->exists());
        self::assertSame(1, BugReportComment::where('bug_report_id', $this->bug->id)->count());
        self::assertSame('in_progress', $this->bug->fresh()->status);
    }

    public function test_followup_cannot_carry_a_status_or_reporter_acceptance_action(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->createDraft(self::OP, $this->params(['reporter_verify' => true, 'status' => 'resolved']),
            'forbidden-status', $this->actor, 'pop_machine', [$this->campus]);
    }

    public static function refusals(): array
    {
        return [['stale', 'writeback_proof_stale'], ['self', 'reply_would_be_reporters_own'], ['phone', 'tw_mobile'],
            ['status', 'status_mismatch'], ['closed', 'status_not_open'], ['campus', 'report_outside_campus']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function test_untrusted_context_and_unsafe_replies_remain_refused(string $case, string $error): void
    {
        $params = $this->params();
        if ($case === 'stale') { Carbon::setTestNow(now()->addMinutes(16)); }
        if ($case === 'self') { $this->bug->forceFill(['reporter_user_id' => $this->admin])->save(); $params = $this->params(); }
        if ($case === 'phone') $params['public_reply'] .= ' 0912345678';
        if ($case === 'status') $params['expected_status'] = 'triaged';
        if ($case === 'closed') { $this->bug->forceFill(['status' => 'closed'])->save(); $params = $this->params(); }
        if ($case === 'campus') $this->bug->forceFill(['CampusID' => Campus::factory()->create()->id])->save();
        $id = $this->draft($params);
        self::assertContains($error, $this->dryPlan($id)['errors']);
        self::assertSame(0, BugReportComment::where('bug_report_id', $this->bug->id)->where('author_user_id', $this->admin)->count());
    }

    private function params(array $extra = []): array
    {
        $url = 'https://github.com/'.config('pop_inapp.github_repo').'/issues/500';
        return array_replace(['bug_id' => (int) $this->bug->id, 'campus_id' => $this->campus, 'expected_status' => 'in_progress',
            'github_issue_url' => $url, 'public_reply' => '目前還沒確認修好。我們查到課堂已保存；請補充看不到的日期及所在頁面，會繼續比對。追蹤：'.$url,
            'request_id' => 'followup-one', 'writeback_proof' => BugReportWritebackEvidence::capture($this->bug->fresh())], $extra);
    }

    private function draft(array $params, string $key = 'followup-once'): string
    {
        $r = $this->service->createDraft(self::OP, $params, $key, $this->actor, 'pop_machine', [$this->campus]);
        $id = (string) $r['id']; $this->service->runDryRun($id, $this->actor, null, 'pop_machine', [$this->campus]); return $id;
    }

    private function dryPlan(string $id): array
    {
        return json_decode(DB::table('pop_execution_records')->where('operation_id', $id)->where('phase', 'dry-run')->value('payload'), true)['plan'];
    }
}
