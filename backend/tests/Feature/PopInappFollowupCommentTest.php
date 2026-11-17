<?php

namespace Tests\Feature;

use App\Models\{BugReport, BugReportComment, BugReportStatusLog, Campus, User};
use App\Operations\Inapp\{InappAgentClients, InappStandingPolicy, InappSupportCommentOrigin};
use App\Operations\{PopOperationCatalog, PopOperationService};
use App\Services\{BugReportService, BugReportWritebackEvidence};
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
    private array $remoteIssue;

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
        $this->remoteIssue = ['number' => 500, 'repository_url' => 'https://api.github.com/repos/'.$repo, 'state' => 'open',
            'user' => ['login' => 'github-actions[bot]'], 'body' => 'SourceRef: alltrue:bug_report:'.$this->bug->id];
        Http::fake(function ($request) use ($repo) {
            if ($request->url() === 'https://api.github.com/repos/'.$repo.'/issues/500') return Http::response($this->remoteIssue);
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

    public static function untrustedIssues(): array { return [['outsider'], ['other_report'], ['closed'], ['pull_request']]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('untrustedIssues')]
    public function test_untrusted_issue_never_authorizes_this_followup(string $case): void
    {
        if ($case === 'outsider') $this->remoteIssue['user']['login'] = 'untrusted-synthetic-author';
        if ($case === 'other_report') $this->remoteIssue['body'] = 'SourceRef: alltrue:bug_report:'.($this->bug->id + 100);
        if ($case === 'closed') { $this->remoteIssue['state'] = 'closed'; $this->remoteIssue['state_reason'] = 'completed'; }
        if ($case === 'pull_request') $this->remoteIssue['pull_request'] = ['url' => 'synthetic-not-an-issue'];
        $before = $this->bug->fresh()->getAttributes(); $id = $this->draft($this->params());
        self::assertContains('issue_source_ref_untrusted', $this->dryPlan($id)['errors']);
        self::assertSame([], $this->policy->approvePending($this->service)['approved']);
        self::assertSame(0, DB::table('pop_approval_events')->where('operation_id', $id)->where('event_type', 'approved')->count());
        self::assertSame($before, $this->bug->fresh()->getAttributes());
        self::assertSame(1, BugReportComment::where('bug_report_id', $this->bug->id)->count());
        self::assertSame(0, BugReportStatusLog::where('bug_report_id', $this->bug->id)->count());
    }

    public static function refusals(): array
    {
        return [['stale', 'writeback_proof_stale'], ['phone', 'tw_mobile'],
            ['status', 'status_mismatch'], ['closed', 'status_not_open'], ['campus', 'report_outside_campus']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function test_untrusted_context_and_unsafe_replies_remain_refused(string $case, string $error): void
    {
        $params = $this->params();
        if ($case === 'stale') { Carbon::setTestNow(now()->addMinutes(16)); }
        if ($case === 'phone') $params['public_reply'] .= ' 0912345678';
        if ($case === 'status') $params['expected_status'] = 'triaged';
        if ($case === 'closed') { $this->bug->forceFill(['status' => 'closed'])->save(); $params = $this->params(); }
        if ($case === 'campus') $this->bug->forceFill(['CampusID' => Campus::factory()->create()->id])->save();
        $id = $this->draft($params);
        self::assertContains($error, $this->dryPlan($id)['errors']);
        self::assertSame(0, BugReportComment::where('bug_report_id', $this->bug->id)->where('author_user_id', $this->admin)->count());
    }

    public function test_self_filed_support_has_exact_origin_real_author_and_unread_without_acceptance(): void
    {
        [$id, $reply] = $this->selfFiledReply();
        self::assertSame([$reply->id => true], InappSupportCommentOrigin::ids($this->bug, [$reply]));
        $detail = BugReportService::getDetail((int) $this->bug->id);
        $public = collect($detail['comments'])->firstWhere('id', $reply->id);
        self::assertSame($this->admin, $public['author_user_id']);
        self::assertSame('support', $public['origin']);
        self::assertSame('in_progress', $this->bug->fresh()->status);
        self::assertSame(0, BugReportStatusLog::where('bug_report_id', $this->bug->id)->count());
        self::assertSame(1, BugReportService::unreadBadgeCount($this->admin, 'director', [$this->campus]));
        self::assertSame(0, BugReportService::unreadBadgeCount($this->admin, 'director', [(int) Campus::factory()->create()->id]));
        self::assertSame(0, BugReportService::unreadBadgeCount($this->admin, 'director', []));
        self::assertSame(1, BugReportService::unreadBadgeCount($this->admin, 'super_admin', []));
        BugReportService::markBugRead($this->admin, (int) $this->bug->id);
        self::assertSame(0, BugReportService::unreadBadgeCount($this->admin, 'director', [$this->campus]));
        $reply->is_internal_note = true; $reply->save();
        self::assertNull(collect(BugReportService::getDetail((int) $this->bug->id)['comments'])->firstWhere('id', $reply->id));
        self::assertSame('support', collect(BugReportService::getDetail((int) $this->bug->id, true)['comments'])->firstWhere('id', $reply->id)['origin']);
        self::assertSame('succeeded', DB::table('pop_execution_records')->where('operation_id', $id)->where('phase', 'execute')->value('result'));
    }

    public function test_native_support_origin_survives_resolution_but_a_copied_human_reply_blocks_timeout(): void
    {
        Carbon::setTestNow(now()->addMinute());
        [, $reply] = $this->selfFiledReply(['public_reply' => '請再試一次並確認畫面，若仍不符請補充。追蹤：https://github.com/'.config('pop_inapp.github_repo').'/issues/500']);
        self::assertTrue(BugReportService::changeStatus((int) $this->bug->id, $this->admin, 'resolved', 'synthetic verified release',
            ['production_revision' => str_repeat('d', 40), 'deploy_run_id' => '123'])['ok']);
        Carbon::setTestNow(now()->addDays(15));
        self::assertContains($this->bug->id, array_column(BugReportService::listEligibleForReporterTimeout(14), 'bug_id'));
        BugReportComment::create(['bug_report_id' => $this->bug->id, 'author_user_id' => $this->admin,
            'body' => $reply->body, 'is_internal_note' => false, 'created_at' => now()]);
        self::assertNotContains($this->bug->id, array_column(BugReportService::listEligibleForReporterTimeout(14), 'bug_id'));
        self::assertSame('not_eligible', BugReportService::closeByReporterTimeout((int) $this->bug->id, $this->admin, false, 14)['code']);
    }

    public function test_copying_support_wording_is_a_real_reporter_reply_and_invalidates_source_proof(): void
    {
        [, $reply] = $this->selfFiledReply();
        $proof = BugReportWritebackEvidence::capture($this->bug->fresh());
        $copy = BugReportComment::create(['bug_report_id' => $this->bug->id, 'author_user_id' => $this->admin,
            'body' => $reply->body, 'is_internal_note' => false, 'created_at' => now()->addSecond()]);
        self::assertSame([$reply->id => true], InappSupportCommentOrigin::ids($this->bug, [$reply, $copy]));
        self::assertFalse(DB::transaction(fn () => BugReportWritebackEvidence::verify($this->bug->fresh(), $proof['captured_at'], $proof['proof'])));
        self::assertSame('reporter', collect(BugReportService::getDetail((int) $this->bug->id)['comments'])->firstWhere('id', $copy->id)['origin']);
    }

    public function test_origin_refuses_mutated_receipt_or_request_and_never_uses_reply_wording(): void
    {
        [$id, $reply] = $this->selfFiledReply();
        $records = DB::table('pop_execution_records')->where('operation_id', $id)->where('phase', 'execute');
        $payload = (string) $records->value('payload'); $original = json_decode($payload, true);
        foreach (['ok' => false, 'operation_id' => 'other', 'execution_id' => 'other', 'strategy' => 'other',
            'bug_id' => $this->bug->id + 1, 'comment_id' => $reply->id + 1, 'author_id' => $this->admin + 1,
            'reply_sha256' => str_repeat('0', 64), 'status' => 'resolved'] as $key => $bad) {
            $records->update(['payload' => json_encode(array_replace($original, [$key => $bad]))]);
            self::assertSame([], InappSupportCommentOrigin::ids($this->bug, [$reply]), $key);
        }
        $records->update(['payload' => $payload, 'result' => 'failed']);
        self::assertSame([], InappSupportCommentOrigin::ids($this->bug, [$reply]));
        $records->update(['result' => 'succeeded']);
        $reply->body .= ' changed';
        self::assertSame([], InappSupportCommentOrigin::ids($this->bug, [$reply]));
        $reply->refresh();
        $reply->is_internal_note = true;
        self::assertSame([$reply->id => true], InappSupportCommentOrigin::ids($this->bug, [$reply])); // visibility never changes historical authorship
        $reply->refresh();
        $requests = DB::table('pop_operation_requests')->where('id', $id);
        $request = (array) $requests->first();
        foreach (['operation_id' => 'inapp-report-progress-reply', 'strategy_id' => 'other',
            'parameters_hash' => str_repeat('0', 64)] as $key => $bad) {
            $requests->update([$key => $bad]);
            self::assertSame([], InappSupportCommentOrigin::ids($this->bug, [$reply]), $key);
            $requests->update([$key => $request[$key]]);
        }
        $parameters = json_decode($request['parameters'], true);
        foreach (['public_reply' => $parameters['public_reply'].' changed', 'campus_id' => (string) $this->campus,
            'bug_id' => (string) $this->bug->id] as $key => $bad) {
            $changed = array_replace($parameters, [$key => $bad]);
            $requests->update(['parameters' => json_encode($changed), 'parameters_hash' => hash('sha256', json_encode(PopOperationService::canonicalParameters($changed)))]);
            self::assertSame([], InappSupportCommentOrigin::ids($this->bug, [$reply]), 'parameter '.$key);
        }
        $requests->update(['parameters' => $request['parameters'], 'parameters_hash' => $request['parameters_hash']]);
        $duplicateRequest = $request;
        $duplicateRequest['id'] = (string) \Illuminate\Support\Str::uuid();
        $duplicateRequest['idempotency_key'] = 'synthetic-ambiguous-origin';
        $requests->insert($duplicateRequest);
        $duplicate = (array) $records->first();
        $duplicate['id'] = $duplicate['execution_id'] = (string) \Illuminate\Support\Str::uuid();
        $duplicate['operation_id'] = $duplicateRequest['id'];
        $duplicate['idempotency_key'] = $duplicate['attempt_key'] = 'synthetic-ambiguous-origin:execute';
        $duplicate['payload'] = json_encode(array_replace($original, ['operation_id' => $duplicateRequest['id'], 'execution_id' => $duplicate['execution_id']]));
        DB::table('pop_execution_records')->insert($duplicate);
        self::assertSame([], InappSupportCommentOrigin::ids($this->bug, [$reply]));
        DB::table('pop_execution_records')->where('id', $duplicate['id'])->delete();
        $records->delete();
        self::assertSame([], InappSupportCommentOrigin::ids($this->bug, [$reply]));
    }

    public function test_many_copied_comments_do_not_gain_origin_or_cause_a_provenance_query_per_comment(): void
    {
        [, $reply] = $this->selfFiledReply();
        BugReportComment::insert(array_fill(0, 100, ['bug_report_id' => $this->bug->id, 'author_user_id' => $this->admin,
            'body' => $reply->body, 'is_internal_note' => false, 'created_at' => now()]));
        $comments = BugReportComment::where('bug_report_id', $this->bug->id)->get();
        DB::flushQueryLog(); DB::enableQueryLog();
        try {
            self::assertSame([$reply->id => true], InappSupportCommentOrigin::ids($this->bug, $comments));
            self::assertLessThanOrEqual(3, count(DB::getQueryLog()));
        } finally { DB::disableQueryLog(); DB::flushQueryLog(); }
    }

    public function test_execute_receipt_failure_rolls_back_the_support_comment_with_its_native_transaction(): void
    {
        $id = $this->draft($this->params()); $this->policy->approvePending($this->service);
        $injected = false;
        DB::connection()->beforeExecuting(function ($query, $bindings) use (&$injected): void {
            if (!$injected && str_starts_with(strtolower($query), 'insert') && str_contains($query, '`pop_execution_records`') && in_array('execute', $bindings, true)) {
                $injected = true;
                throw new \RuntimeException('synthetic execute receipt persistence failure');
            }
        });
        $run = $this->service->runApprovedLocally($id);
        self::assertTrue($injected);
        self::assertSame('failed', $run['status']);
        self::assertSame(1, BugReportComment::where('bug_report_id', $this->bug->id)->count());
        self::assertSame(0, BugReportComment::where('bug_report_id', $this->bug->id)->where('author_user_id', $this->admin)->count());
        self::assertSame('in_progress', $this->bug->fresh()->status);
    }

    private function selfFiledReply(array $extra = []): array
    {
        $this->bug->forceFill(['reporter_user_id' => $this->admin])->save();
        BugReportComment::where('bug_report_id', $this->bug->id)->update(['author_user_id' => $this->admin]);
        $id = $this->draft($this->params($extra));
        self::assertSame([$id], $this->policy->approvePending($this->service)['approved']);
        self::assertSame('succeeded', $this->service->runApprovedLocally($id)['status']);
        return [$id, BugReportComment::where('bug_report_id', $this->bug->id)->orderByDesc('id')->firstOrFail()];
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
