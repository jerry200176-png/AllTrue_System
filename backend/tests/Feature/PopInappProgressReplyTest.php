<?php

namespace Tests\Feature;

use App\Models\BugReport;
use App\Models\BugReportComment;
use App\Models\BugReportStatusLog;
use App\Models\User;
use App\Operations\Inapp\InappAgentClients;
use App\Operations\Inapp\InappProgressReplyTemplates as Templates;
use App\Operations\Inapp\InappReportReplyGuard;
use App\Operations\Inapp\InappStandingPolicy;
use App\Operations\PopOperationCatalog;
use App\Operations\PopOperationService;
use App\Operations\Strategies\InappReportProgressReplyStrategy;
use App\Services\BugReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

/** Founder decision 2026-11-13 (#4991) through the real POP path (draft, dry-run, approval, Pi-local execute) with real report rows. */
final class PopInappProgressReplyTest extends TestCase
{
    use RefreshDatabase;

    private const OP = InappReportProgressReplyStrategy::OPERATION;
    private const MACHINE = 'api-client:7'; // a control-plane machine actor: never a pop_inapp_agent client

    private string $dir;
    private PopOperationService $service;
    private InappStandingPolicy $policy;
    private User $adminUser;
    private int $admin;
    private int $reporter;
    private string $deployed;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-13 10:00:00'));
        $this->dir = sys_get_temp_dir() . '/pop-progress-' . bin2hex(random_bytes(6));
        File::copyDirectory(dirname(base_path()) . '/operations', $this->dir);
        $this->deployed = str_repeat('d', 40);
        file_put_contents($this->dir . '/deployment.json', json_encode(['backend_sha' => $this->deployed]));
        $catalog = new PopOperationCatalog($this->dir . '/catalog.yaml');
        $this->app->instance(PopOperationCatalog::class, $catalog);
        $this->service = new PopOperationService($catalog, null, $this->dir . '/deployment.json');
        $this->policy = new InappStandingPolicy($catalog);
        config(['pop_inapp.standing_policy_enabled' => true, 'pop_inapp.daily_cap' => 20]);
        $this->adminUser = User::create(['LoginName' => 'popProgAdmin@test.com', 'Name' => 'A', 'PSW' => 'x', 'type' => 'S', 'phone' => rand(900000000, 999999999)]);
        $this->admin = (int) $this->adminUser->id;
        $this->reporter = User::create(['LoginName' => 'popProgRep@test.com', 'Name' => 'R', 'PSW' => 'x', 'type' => 'T', 'phone' => rand(900000000, 999999999)])->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public static function templates(): array
    {
        return [
            'received' => ['received', 'new', '已收到你的回報，我們正在確認，有進度會再回覆你。'],
            'in_progress' => ['in_progress', 'triaged', '已找到原因，我們正在處理，完成後會再回覆你。'],
            'deployed_please_confirm on a resolved report' => ['deployed_please_confirm', 'resolved', '已更新，請你協助確認是否恢復正常；如果還有問題，直接在這裡回覆我們就好。'],
            'received on a triaged report' => ['received', 'triaged', '已收到你的回報，我們正在確認，有進度會再回覆你。'],
        ];
    }

    /** @dataProvider templates */
    public function test_each_template_posts_exactly_its_canned_text_and_nothing_else_on_the_report_changes(string $template, string $status, string $text): void
    {
        self::assertSame($text, Templates::text($template));
        $bug = $this->bug($status);
        $before = BugReport::query()->findOrFail($bug)->getAttributes();
        $id = $this->drafted($bug, ['template' => $template]);
        // The dry-run shows the target report and the exact text that execute will post.
        $plan = $this->dryRunPlan($id);
        self::assertSame([$bug, $text, true], [$plan['bug_id'], $plan['reply_text'], $plan['ok']]);

        $this->service->approve($id, 'inapp-human-review', 'user:' . $this->admin, 'super_admin', $this->deployed, $this->admin);
        self::assertSame('succeeded', $this->service->runApprovedLocally($id)['status']);

        self::assertSame($before, BugReport::query()->findOrFail($bug)->getAttributes()); // no status, assignee or timestamp moved
        self::assertSame(0, BugReportStatusLog::query()->where('bug_report_id', $bug)->count());
        $comment = BugReportComment::query()->where('bug_report_id', $bug)->sole();
        self::assertSame([$text, false, $this->admin], [$comment->body, (bool) $comment->is_internal_note, (int) $comment->author_user_id]);
        // The execution record never carries the report's own text.
        $records = DB::table('pop_execution_records')->where('operation_id', $id)->pluck('payload')->implode("\n");
        self::assertStringNotContainsString('report-title-marker', $records);
    }

    public function test_the_wording_is_plain_short_and_has_no_links_numbers_or_internal_terms(): void
    {
        self::assertSame(['received', 'in_progress', 'deployed_please_confirm'], Templates::keys());
        foreach (Templates::allTexts() as $text) {
            self::assertLessThanOrEqual(60, mb_strlen($text));
            // The same pattern guard the other automated replies pass; only the (intentionally absent) issue link is reported.
            self::assertSame(['reply_missing_issue_url'], InappReportReplyGuard::violations($text, 'https://github.com/x/y/issues/1', false), $text);
            self::assertDoesNotMatchRegularExpression('/[0-9#]|PR|issue|SQL|CI/u', $text);
        }
    }

    public function test_standing_policy_approves_the_inapp_agent_clients_request_and_attributes_the_reply_to_the_issuing_super_admin(): void
    {
        $actor = $this->inappAgent();
        $bug = $this->bug('new');
        $id = $this->drafted($bug, [], 'req-001', $actor);

        self::assertSame([$id], $this->policy->approvePending($this->service)['approved']);
        self::assertStringEndsWith('@4', $this->policy->approverRef());
        self::assertSame('succeeded', $this->service->runApprovedLocally($id)['status']);
        $comment = BugReportComment::query()->where('bug_report_id', $bug)->sole();
        self::assertSame([Templates::text('received'), $this->admin], [$comment->body, (int) $comment->author_user_id]);
        self::assertSame('new', BugReport::query()->whereKey($bug)->value('status'));
    }

    public function test_a_requester_that_is_not_an_inapp_agent_client_falls_back_to_a_super_admin(): void
    {
        $bug = $this->bug('new');
        $id = $this->drafted($bug);

        $out = $this->policy->approvePending($this->service);
        self::assertSame([[], [$id]], [$out['approved'], $out['denied']]);
        self::assertContains('requester_client_unverified', $this->policy->evaluate($this->request($id)));
        $this->assertNothingWritten($bug);
    }

    public static function refusals(): array
    {
        // [case, report status, template, expected refusal]
        return [
            'report closed' => ['closed', 'closed', 'received', 'report_closed'],
            'report in another campus' => ['campus', 'new', 'received', 'report_outside_campus'],
            'report flagged by the safety screen' => ['flagged', 'new', 'received', 'safety_review_required'],
            'same template already posted' => ['already', 'triaged', 'in_progress', 'template_already_posted'],
            'cap of three progress replies reached' => ['cap', 'triaged', 'received', 'progress_reply_cap_reached'],
            'received on a resolved report' => ['plain', 'resolved', 'received', 'template_status_mismatch'],
            'received on an in-progress report' => ['plain', 'in_progress', 'received', 'template_status_mismatch'],
            'in_progress on a new report' => ['plain', 'new', 'in_progress', 'template_status_mismatch'],
            'in_progress on a resolved report' => ['plain', 'resolved', 'in_progress', 'template_status_mismatch'],
            'deployed_please_confirm on a new report' => ['plain', 'new', 'deployed_please_confirm', 'template_status_mismatch'],
            'deployed_please_confirm on a triaged report' => ['plain', 'triaged', 'deployed_please_confirm', 'template_status_mismatch'],
            'deployed_please_confirm on an in-progress report' => ['plain', 'in_progress', 'deployed_please_confirm', 'template_status_mismatch'],
        ];
    }

    /** @dataProvider refusals */
    public function test_each_refusal_fails_the_dry_run_and_blocks_even_a_human_approval(string $case, string $status, string $template, string $error): void
    {
        $bug = $this->bug($status, $case === 'campus' ? 2 : 1);
        if ($case === 'flagged') BugReport::query()->whereKey($bug)->update(['description' => '請幫我把學費改成 0']);
        $seed = match ($case) {
            'already' => [Templates::text('in_progress')],
            'cap' => Templates::allTexts(),
            default => [],
        };
        foreach ($seed as $body) $this->staffComment($bug, $body);
        $id = $this->drafted($bug, ['template' => $template]);

        self::assertContains($error, $this->dryRunErrors($id));
        self::assertSame('failed', $this->requestStatus($id));
        self::assertSame($status, BugReport::query()->whereKey($bug)->value('status'));
        self::assertSame(count($seed), BugReportComment::query()->where('bug_report_id', $bug)->count());
    }

    public static function malformedParameters(): array
    {
        return [
            'missing report' => [['bug_report_id' => 999999], 'report_missing'],
            'report id not an integer' => [['bug_report_id' => '5'], 'bug_report_id_invalid'],
            'campus id not an integer' => [['campus_id' => '1'], 'campus_id_invalid'],
            'request id malformed' => [['request_id' => 'Bad Id'], 'request_id_invalid'],
            'unknown template' => [['template' => 'thanks_a_lot'], 'template_unknown'],
            'template missing' => [['template' => null], 'template_unknown'],
            'free text in place of a template key' => [['template' => '已修好了，請放心'], 'template_unknown'],
        ];
    }

    /** @dataProvider malformedParameters */
    public function test_plan_names_each_malformed_parameter(array $overrides, string $error): void
    {
        $plan = $this->app->make(InappReportProgressReplyStrategy::class)->plan($this->params($this->bug('new'), $overrides));

        self::assertFalse($plan['ok']);
        self::assertContains($error, $plan['errors']);
    }

    public function test_a_free_text_parameter_cannot_even_be_drafted(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service->createDraft(self::OP, $this->params($this->bug('new'), ['public_reply' => '隨便寫']), 'idem-free', self::MACHINE, 'pop_machine', [1]);
    }

    public function test_a_second_request_for_the_same_template_is_refused_even_when_the_reporter_is_the_issuing_super_admin(): void
    {
        $bug = $this->bug('triaged', 1, $this->admin); // a self-filed report: the reply author and the reporter are one user
        $first = $this->drafted($bug, [], 'req-001');
        $this->service->approve($first, 'inapp-human-review', 'user:' . $this->admin, 'super_admin', $this->deployed, $this->admin);
        self::assertSame('succeeded', $this->service->runApprovedLocally($first)['status']); // verify counts every author, so it passes too

        $again = $this->drafted($bug, [], 'req-002');
        self::assertContains('template_already_posted', $this->dryRunErrors($again));
        self::assertSame(1, BugReportComment::query()->where('bug_report_id', $bug)->count());
        // A different template is still allowed.
        self::assertSame([], $this->dryRunErrors($this->drafted($bug, ['template' => 'in_progress'], 'req-003')));
    }

    public function test_the_cap_counts_replies_on_a_self_filed_report_and_a_reporter_pasting_the_text_only_causes_a_refusal(): void
    {
        $own = $this->bug('triaged', 1, $this->admin);
        foreach (Templates::currentTexts() as $text) $this->staffComment($own, $text);
        self::assertContains('progress_reply_cap_reached', $this->dryRunErrors($this->drafted($own, ['template' => 'received'], 'req-001')));

        $other = $this->bug('triaged');
        BugReportComment::create(['bug_report_id' => $other, 'author_user_id' => $this->reporter, 'body' => Templates::text('in_progress'), 'is_internal_note' => false, 'created_at' => now()]);
        self::assertContains('template_already_posted', $this->dryRunErrors($this->drafted($other, ['template' => 'in_progress'], 'req-002')));
    }

    public function test_a_retired_wording_still_counts_for_idempotency_and_the_cap(): void
    {
        $bug = $this->bug('triaged');
        $this->staffComment($bug, '舊版文字');
        $this->staffComment($bug, Templates::text('received'));
        $this->staffComment($bug, Templates::text('deployed_please_confirm'));
        $plain = $this->app->make(InappReportProgressReplyStrategy::class)->plan($this->params($bug, ['template' => 'in_progress']));
        $retired = (new InappReportProgressReplyStrategy(['舊版文字']))->plan($this->params($bug, ['template' => 'in_progress']));

        self::assertSame([], $plain['errors']);
        self::assertSame(['progress_reply_cap_reached'], $retired['errors']);
        self::assertSame([], Templates::RETIRED_TEXTS); // add old wording there when a template text changes
        self::assertSame(Templates::currentTexts(), Templates::allTexts());
    }

    public function test_the_confirm_template_counts_as_the_retest_request_so_a_wording_change_that_breaks_the_match_is_noticed(): void
    {
        $confirm = Templates::text('deployed_please_confirm');
        self::assertSame(1, preg_match(BugReportService::RETEST_REQUEST_PATTERN, $confirm));
        self::assertSame(1, preg_match(BugReportService::AWAITING_REPORTER_QUESTION_PATTERN, $confirm));
        foreach (['received', 'in_progress'] as $key) {
            self::assertSame(0, preg_match(BugReportService::RETEST_REQUEST_PATTERN, Templates::text($key)), $key);
            self::assertSame(0, preg_match(BugReportService::AWAITING_REPORTER_QUESTION_PATTERN, Templates::text($key)), $key);
        }
    }

    public static function movedAfterThePlan(): array
    {
        return ['report closed' => ['closed', 'inapp_progress_report_closed'], 'same template posted by someone else' => ['posted', 'inapp_progress_template_already_posted'],
            'report moved to another campus' => ['campus', 'inapp_progress_report_outside_campus']];
    }

    /** @dataProvider movedAfterThePlan */
    public function test_execute_rechecks_under_the_row_lock_and_writes_nothing_when_something_moved(string $moved, string $error): void
    {
        $bug = $this->bug('triaged');
        $id = $this->drafted($bug);
        $strategy = $this->app->make(InappReportProgressReplyStrategy::class);
        $plan = $strategy->plan($this->params($bug));
        self::assertTrue($plan['ok'], implode(',', $plan['errors']));
        $this->service->approve($id, 'inapp-human-review', 'user:' . $this->admin, 'super_admin', $this->deployed, $this->admin);
        match ($moved) { // between the last plan and the locked write
            'closed' => BugReport::query()->whereKey($bug)->update(['status' => 'closed']),
            'posted' => $this->staffComment($bug, Templates::text('received')),
            'campus' => BugReport::query()->whereKey($bug)->update(['CampusID' => 2]),
        };

        self::assertSame(['ok' => false, 'errors' => [$error], 'bug_id' => $bug], $strategy->execute($plan, ['operation_id' => $id]));
        self::assertSame($moved === 'posted' ? 1 : 0, BugReportComment::query()->where('bug_report_id', $bug)->count());
    }

    public function test_execute_rechecks_the_policy_kill_switches_inside_the_write_transaction(): void
    {
        $actor = $this->inappAgent();
        $bug = $this->bug('new');
        $id = $this->drafted($bug, [], 'req-001', $actor);
        $this->policy->approvePending($this->service);
        $strategy = $this->app->make(InappReportProgressReplyStrategy::class);
        $plan = $strategy->plan($this->params($bug));
        config(['pop_inapp.standing_policy_enabled' => false]); // flipped after the executor claimed the request

        self::assertSame(['inapp_standing_policy_kill_switch'], $strategy->execute($plan, ['operation_id' => $id])['errors']);
        $this->assertNothingWritten($bug);
    }

    public function test_verify_needs_exactly_the_one_new_reply_and_an_unchanged_status(): void
    {
        $bug = $this->bug('triaged');
        $strategy = $this->app->make(InappReportProgressReplyStrategy::class);
        $comment = BugReportComment::create(['bug_report_id' => $bug, 'author_user_id' => $this->admin, 'body' => Templates::text('in_progress'),
            'is_internal_note' => false, 'created_at' => now()]);
        $result = ['ok' => true, 'bug_id' => $bug, 'template' => 'in_progress', 'comment_id' => $comment->id, 'status' => 'triaged'];

        self::assertTrue($strategy->verify([], $result)['ok']);
        self::assertFalse($strategy->verify([], ['status' => 'resolved'] + $result)['ok']); // status moved
        self::assertFalse($strategy->verify([], ['comment_id' => $comment->id + 1] + $result)['ok']); // not the reply that was posted
        $this->staffComment($bug, Templates::text('in_progress'));
        self::assertFalse($strategy->verify([], $result)['ok']); // two replies with that text
    }

    public function test_restore_follows_the_triage_contract_and_never_deletes_a_posted_reply(): void
    {
        $bug = $this->bug('new');
        $this->staffComment($bug, Templates::text('received'));
        $strategy = $this->app->make(InappReportProgressReplyStrategy::class);

        self::assertSame(['ok' => false, 'errors' => ['not_reversible']], $strategy->rollback([], ['operation_id' => 'x']));
        self::assertSame(1, BugReportComment::query()->where('bug_report_id', $bug)->count());
        self::assertFalse((bool) $this->app->make(PopOperationCatalog::class)->operation(self::OP)['reversible']);
    }

    public function test_the_operation_is_governed_by_the_standing_policy_like_its_siblings(): void
    {
        $entry = $this->app->make(PopOperationCatalog::class)->operation(self::OP);

        self::assertTrue(InappStandingPolicy::governs($entry));
        self::assertTrue($this->policy->active());
        self::assertContains(self::OP, array_keys(InappStandingPolicy::OPERATIONS));
        self::assertSame(['bug_report_id', 'campus_id', 'request_id', 'template'], $entry['parameter_keys']);
    }

    public static function outOfScope(): array
    {
        return ['another campus' => [[2]], 'empty scope' => [[]]];
    }

    /** @dataProvider outOfScope */
    public function test_a_requester_outside_the_report_campus_cannot_even_draft(array $scope): void
    {
        $this->expectException(RuntimeException::class);
        $this->service->createDraft(self::OP, $this->params($this->bug('new')), 'idem-scope', self::MACHINE, 'pop_machine', $scope);
    }

    private function drafted(int $bug, array $overrides = [], string $rid = 'req-001', string $actor = self::MACHINE, string $role = 'pop_machine', array $scope = [1]): string
    {
        $draft = $this->service->createDraft(self::OP, $this->params($bug, ['request_id' => $rid] + $overrides), 'idem-' . $rid . '-' . $bug, $actor, $role, $scope);
        $this->service->runDryRun((string) $draft['id'], $actor, null, $role, $scope);

        return (string) $draft['id'];
    }

    /** A real `pop_inapp_agent` client issued by the super admin; returns its POP request actor. */
    private function inappAgent(array $campusIds = [1]): string
    {
        return InappAgentClients::actor((int) InappAgentClients::issue($this->adminUser, 'progress agent', $campusIds)['record']['id']);
    }

    private function params(int $bug, array $overrides = []): array
    {
        return array_merge(['bug_report_id' => $bug, 'campus_id' => 1, 'template' => 'received', 'request_id' => 'req-001'], $overrides);
    }

    private function bug(string $status, int $campus = 1, ?int $reporter = null): int
    {
        return BugReport::create(['CampusID' => $campus, 'reporter_user_id' => $reporter ?? $this->reporter, 'title' => 'report-title-marker',
            'description' => 'report-description-marker', 'severity' => 'low', 'status' => $status])->id;
    }

    private function staffComment(int $bug, string $body): void
    {
        BugReportComment::create(['bug_report_id' => $bug, 'author_user_id' => $this->admin, 'body' => $body, 'is_internal_note' => false, 'created_at' => now()->subHour()]);
    }

    private function request(string $id): object
    {
        return DB::table('pop_operation_requests')->where('id', $id)->first();
    }

    private function dryRunPlan(string $id): array
    {
        $payload = json_decode((string) DB::table('pop_execution_records')->where('operation_id', $id)->where('phase', 'dry-run')->value('payload'), true);

        return (array) ($payload['plan'] ?? []);
    }

    private function dryRunErrors(string $id): array
    {
        return (array) ($this->dryRunPlan($id)['errors'] ?? []);
    }

    private function requestStatus(string $id): string
    {
        return (string) DB::table('pop_operation_requests')->where('id', $id)->value('status');
    }

    private function assertNothingWritten(int $bug): void
    {
        self::assertSame(0, BugReportStatusLog::query()->where('bug_report_id', $bug)->count());
        self::assertSame(0, BugReportComment::query()->where('bug_report_id', $bug)->count());
    }
}
