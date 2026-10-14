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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

/** ADR-POP-015 standing policy: fail closed, same approval path as a human, kill switches at execute. */
final class PopInappStandingPolicyTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;
    private PopOperationService $service;
    private InappStandingPolicy $policy;
    private int $admin;
    private int $reporter;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00'));
        $this->dir = sys_get_temp_dir() . '/pop-inapp-' . bin2hex(random_bytes(6));
        File::copyDirectory(dirname(base_path()) . '/operations', $this->dir);
        file_put_contents($this->dir . '/deployment.json', json_encode(['backend_sha' => str_repeat('a', 40)]));
        $catalog = new PopOperationCatalog($this->dir . '/catalog.yaml');
        $this->app->instance(PopOperationCatalog::class, $catalog);
        $this->service = new PopOperationService($catalog, null, $this->dir . '/deployment.json');
        $this->policy = new InappStandingPolicy($catalog);
        config(['pop_inapp.standing_policy_enabled' => true, 'pop_inapp.daily_cap' => 20]);
        $this->allowRequester(true);
        $this->admin = User::create(['LoginName' => 'popAdmin@test.com', 'Name' => 'A', 'PSW' => 'x', 'type' => 'S', 'phone' => rand(900000000, 999999999)])->id;
        $this->reporter = User::create(['LoginName' => 'popRep@test.com', 'Name' => 'R', 'PSW' => 'x', 'type' => 'T', 'phone' => rand(900000000, 999999999)])->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_all_checks_pass_with_flag_on_approves_through_the_human_path_and_executes(): void
    {
        $bugs = [$this->eligibleBug(), $this->eligibleBug()];
        $id = $this->drafted($bugs);

        self::assertSame([$id], $this->policy->approvePending($this->service)['approved']);
        $event = DB::table('pop_approval_events')->where('operation_id', $id)->sole();
        self::assertSame(['approved', 'policy', 'policy:founder-standing-inapp-lifecycle@1', 'FSD-2026-10-14-II:#4069:#4073', null, str_repeat('a', 40), true, 'approved'],
            [$event->event_type, $event->approver_role, $event->approver, $event->approval_reference, $event->approver_id, $event->commit_sha, $event->token_hash !== null, $this->status($id)]);

        self::assertSame('succeeded', $this->service->runApprovedLocally($id)['status']);
        self::assertSame(['closed', 'closed'], BugReport::query()->whereKey($bugs)->pluck('status')->all());
    }

    public static function failingChecks(): array
    {
        return ['flag_off' => ['flag_off'], 'requester' => ['requester'], 'frozen' => ['frozen'], 'replay' => ['replay'],
            'ineligible' => ['ineligible'], 'queue' => ['queue'], 'cap' => ['cap']];
    }

    /** @dataProvider failingChecks */
    public function test_each_failing_check_denies_and_falls_back_to_super_admin(string $check): void
    {
        $bug = $this->eligibleBug();
        $ids = [$bug];
        if ($check === 'flag_off') config(['pop_inapp.standing_policy_enabled' => false]);
        if ($check === 'requester') $this->allowRequester(false);
        if ($check === 'frozen') $this->editPolicy('lifecycle: active', 'lifecycle: frozen');
        if ($check === 'replay') $this->drafted([$this->eligibleBug()], 'req-dup', 'idem-other');
        if ($check === 'ineligible') $ids[] = $this->bug('in_progress');
        if ($check === 'queue') array_map(fn () => $this->eligibleBug(), range(1, 20)); // 21 eligible > AUTO_MAX_PER_RUN
        if ($check === 'cap') {
            config(['pop_inapp.daily_cap' => 1]);
            $ids[] = $this->eligibleBug();
        }
        $id = $this->drafted($ids, $check === 'replay' ? 'req-dup' : 'req-001');

        $out = $this->policy->approvePending($this->service, 5);
        self::assertSame([false, 'awaiting_approval', 'resolved'], [in_array($id, $out['approved'], true), $this->status($id), BugReport::query()->whereKey($bug)->value('status')]);
        // An inactive (flag off / frozen) policy decides nothing; every other failure records one denial.
        self::assertSame(!in_array($check, ['flag_off', 'frozen'], true), DB::table('pop_approval_events')->where('operation_id', $id)->where('event_type', 'policy_denied')->exists());
        $human = $this->service->approve($id, 'inapp-human-review', 'user:' . $this->admin, 'super_admin', str_repeat('a', 40), $this->admin);
        self::assertTrue($human['ready']);
    }

    public function test_report_outside_campus_fails_the_dry_run_and_is_never_approved(): void
    {
        $id = $this->drafted([$this->eligibleBug()], 'req-001', null, 2);
        self::assertSame([[], 'failed'], [$this->policy->approvePending($this->service)['approved'], $this->status($id)]);
    }

    public static function killSwitches(): array
    {
        return ['flag' => ['flag'], 'frozen' => ['frozen'], 'version' => ['version'], 'requester' => ['requester']];
    }

    /** @dataProvider killSwitches */
    public function test_kill_switch_after_approval_blocks_execution_and_revokes(string $switch): void
    {
        $bug = $this->eligibleBug();
        $id = $this->drafted([$bug]);
        $this->policy->approvePending($this->service);
        match ($switch) {
            'flag' => config(['pop_inapp.standing_policy_enabled' => false]),
            'frozen' => $this->editPolicy('lifecycle: active', 'lifecycle: frozen'),
            'version' => $this->editPolicy('version: 1', 'version: 2'),
            'requester' => $this->allowRequester(false),
        };

        try {
            $this->service->runApprovedLocally($id);
            self::fail('a tripped kill switch must stop execution');
        } catch (RuntimeException) {} // fail closed
        self::assertSame('cancelled', $this->status($id));
        self::assertTrue(DB::table('pop_approval_events')->where('operation_id', $id)->where('event_type', 'policy_revoked')->exists());
        self::assertSame('resolved', BugReport::query()->whereKey($bug)->value('status'));
        // Kill switches apply to policy approvals only: a super_admin-approved request still executes.
        $human = $this->drafted([$other = $this->eligibleBug()], 'req-human');
        $this->service->approve($human, 'inapp-human-review', 'user:' . $this->admin, 'super_admin', str_repeat('a', 40), $this->admin);
        self::assertSame('succeeded', $this->service->runApprovedLocally($human)['status']);
        self::assertSame('closed', BugReport::query()->whereKey($other)->value('status'));
    }

    public function test_policy_run_changes_nothing_when_the_queue_grows_past_auto_max_after_approval(): void
    {
        $id = $this->drafted([$bug = $this->eligibleBug()]);
        $this->policy->approvePending($this->service);
        array_map(fn () => $this->eligibleBug(), range(1, 20));
        $this->service->runApprovedLocally($id);
        self::assertSame(['failed', 'resolved'], [$this->status($id), BugReport::query()->whereKey($bug)->value('status')]);
    }

    public function test_policy_approver_role_is_rejected_for_every_other_operation(): void
    {
        $quorum = new \ReflectionMethod($this->service, 'quorumRoles');
        $quorum->setAccessible(true);
        $this->expectException(RuntimeException::class);
        $quorum->invoke($this->service, $this->app->make(PopOperationCatalog::class)->operation('muzha-fixed-schedule-20261001'),
            collect([(object) ['approver_role' => 'policy', 'approver' => 'policy:founder-standing-inapp-lifecycle@1']]), 'none');
    }

    public function test_execute_rechecks_each_id_under_lock_and_skips_ineligible_without_closing(): void
    {
        [$keep, $replied, $moved] = [$this->eligibleBug(), $this->eligibleBug(), $this->eligibleBug()];
        $id = $this->drafted([$keep, $replied, $moved]);
        $this->policy->approvePending($this->service);
        BugReportComment::create(['bug_report_id' => $replied, 'author_user_id' => $this->reporter, 'body' => '問題仍存在', 'is_internal_note' => false, 'created_at' => now()]);
        BugReport::query()->whereKey($moved)->update(['status' => 'in_progress']);

        $this->service->runApprovedLocally($id);
        $record = json_decode((string) DB::table('pop_execution_records')->where('operation_id', $id)->where('phase', 'execute')->value('payload'), true);
        self::assertSame([[$keep], [$replied, $moved]], [$record['closed_ids'], $record['skipped_ids']]);
        self::assertSame(['closed', 'resolved', 'in_progress'], BugReport::query()->whereKey([$keep, $replied, $moved])->orderBy('id')->pluck('status')->all());
    }

    private function drafted(array $bugIds, string $rid = 'req-001', ?string $key = null, int $campus = 1): string
    {
        $draft = $this->service->createDraft('inapp-report-reporter-timeout', ['bug_ids' => $bugIds, 'campus_id' => $campus, 'request_id' => $rid],
            $key ?? 'idem-' . $rid, 'api-client:7', 'super_admin');
        $this->service->runDryRun((string) $draft['id'], 'api-client:7', null, 'super_admin');

        return (string) $draft['id'];
    }

    private function eligibleBug(): int
    {
        $id = $this->bug('resolved');
        $at = now()->subDays(20);
        BugReportComment::create(['bug_report_id' => $id, 'author_user_id' => $this->admin, 'body' => '請再試一次', 'is_internal_note' => false, 'created_at' => $at]);
        BugReportStatusLog::create(['bug_report_id' => $id, 'changed_by' => $this->admin, 'from_status' => 'in_progress',
            'to_status' => 'resolved', 'note' => '[resolution_evidence]{"production_revision":"662960e5"}', 'created_at' => $at]);

        return $id;
    }

    private function bug(string $status): int
    {
        return BugReport::create(['CampusID' => 1, 'reporter_user_id' => $this->reporter, 'title' => 'T', 'description' => 'D',
            'severity' => 'low', 'status' => $status])->id;
    }

    private function status(string $id): string
    {
        return (string) DB::table('pop_operation_requests')->where('id', $id)->value('status');
    }

    private function editPolicy(string $from, string $to): void
    {
        $file = $this->dir . '/policies/' . InappStandingPolicy::FILE;
        file_put_contents($file, str_replace($from, $to, (string) file_get_contents($file)));
    }

    private function allowRequester(bool $allow): void
    {
        $this->app->instance(InappRequesterGuard::class, new class ($allow) extends InappRequesterGuard {
            public function __construct(private readonly bool $allow) {}
            public function allows(object $request): bool { return $this->allow; }
            public function attributionUserId(object $request): ?int { return $this->allow ? (int) User::query()->where('type', 'S')->value('id') : null; }
        });
    }
}
