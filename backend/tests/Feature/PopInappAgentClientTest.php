<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\BugReport;
use App\Models\BugReportComment;
use App\Models\BugReportStatusLog;
use App\Models\Campus;
use App\Models\User;
use App\Operations\Inapp\InappAgentClients;
use App\Operations\Inapp\InappRequesterGuard;
use App\Operations\Inapp\InappStandingPolicy;
use App\Operations\PopOperationCatalog;
use App\Operations\PopOperationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** ADR-POP-015 part 2: the pop_inapp_agent requester credential (issuance, machine auth, guard, revocation). CI only. */
final class PopInappAgentClientTest extends TestCase
{
    use RefreshDatabase;

    private const OP = 'inapp-report-reporter-timeout';
    private int $seq = 0;
    private User $admin;
    private int $campus;
    private int $otherCampus;
    private string $manifest;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00'));
        $this->user('S'); // another super admin: attribution must name the issuer, not any super admin
        $this->admin = $this->user('S');
        $this->campus = (int) Campus::factory()->create()->id;
        $this->otherCampus = (int) Campus::factory()->create()->id;
        $this->manifest = tempnam(sys_get_temp_dir(), 'pop-inapp-agent-');
        file_put_contents($this->manifest, json_encode(['backend_sha' => str_repeat('a', 40)]));
        config(['pop_inapp.standing_policy_enabled' => true, 'pop_inapp.daily_cap' => 20]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        @unlink($this->manifest);
        parent::tearDown();
    }

    public function test_super_admin_issues_a_key_shown_once_stored_as_hash_with_ttl_and_campus_list(): void
    {
        config(['pop_inapp.agent_client_ttl_days' => 7]);
        $created = $this->postJson('/api/v1/admin/pop-inapp-agent-clients', ['name' => 'cloud agent', 'campus_ids' => [$this->campus]], $this->as($this->admin));

        $created->assertCreated()->assertJsonPath('record.campus_ids', [$this->campus])->assertJsonPath('record.issued_by_user_id', $this->admin->id);
        $this->assertStringContainsString('no-store', (string) $created->headers->get('Cache-Control'));
        $key = (string) $created->json('key');
        $this->assertMatchesRegularExpression('/^alltrue_pop_inapp_[A-Za-z0-9]{48}$/', $key);
        $client = ApiClient::query()->findOrFail($created->json('record.id'));
        $this->assertSame([hash('sha256', $key), 'pop_inapp_agent', ['pop:draft', 'pop:dry-run'], $this->admin->id, [$this->campus], 0, true],
            [$client->ApiKeyHash, $client->Purpose, $client->Scopes, $client->IssuedByUserID, $client->CampusIDs, (int) $client->CampusID, $client->Active]);
        $this->assertTrue($client->ExpiresAt->equalTo(now()->addDays(7)));

        $list = $this->getJson('/api/v1/admin/pop-inapp-agent-clients', $this->as($this->admin))->assertOk()
            ->assertJsonPath('data.0.id', $client->id)->assertJsonPath('ttl_days', 7)->assertJsonPath('operations', array_keys(InappStandingPolicy::OPERATIONS));
        $this->assertStringNotContainsString($key, $list->getContent());
        $this->assertStringNotContainsString(hash('sha256', $key), $list->getContent());
        $this->assertSame(1, DB::table('security_audit_events')->where('event_type', 'pop_inapp_agent.issued')->count());
    }

    public function test_only_an_active_super_admin_issues_lists_or_revokes_and_the_campus_list_is_required(): void
    {
        $id = $this->client('k-existing')->id;
        $suspended = $this->user('S', 'suspended');
        foreach ([$this->user('A'), $this->user('T'), $suspended] as $user) {
            $this->postJson('/api/v1/admin/pop-inapp-agent-clients', ['name' => 'x', 'campus_ids' => [$this->campus]], $this->as($user))->assertForbidden();
            $this->getJson('/api/v1/admin/pop-inapp-agent-clients', $this->as($user))->assertForbidden();
            $this->deleteJson("/api/v1/admin/pop-inapp-agent-clients/{$id}", [], $this->as($user))->assertForbidden();
        }
        foreach ([[], [0], [999999], 'all', null] as $campusIds) {
            $this->postJson('/api/v1/admin/pop-inapp-agent-clients', ['name' => 'x', 'campus_ids' => $campusIds], $this->as($this->admin))
                ->assertStatus(422)->assertJsonValidationErrors('campus_ids' . (is_array($campusIds) && $campusIds !== [] ? '.0' : ''));
        }
        $this->assertSame(1, ApiClient::query()->where('Purpose', 'pop_inapp_agent')->count());
        $this->assertTrue(ApiClient::query()->findOrFail($id)->Active);
    }

    public function test_key_drafts_and_dry_runs_an_inapp_operation_on_a_listed_campus(): void
    {
        $client = $this->client('k-ok');
        $draft = $this->machineDraft('k-ok', $this->campus, [$this->bug('resolved')]);

        $draft->assertCreated()->assertJsonPath('data.actor', 'machine:api-client:' . $client->id);
        $this->machineDryRun('k-ok', (string) $draft->json('data.id'))->assertOk()->assertJsonPath('data.result', 'succeeded');
        // The key never approves: the approval route takes a human session only (and there is no HTTP execute route).
        $this->withHeader('X-POP-MACHINE-KEY', 'k-ok')->postJson('/api/v1/pop/operations/requests/' . $draft->json('data.id') . '/approvals',
            ['approval_reference' => 'inapp-machine', 'commit_sha' => str_repeat('a', 40)])->assertUnauthorized();
        $this->assertSame(['awaiting_approval', 0], [$this->status((string) $draft->json('data.id')), DB::table('pop_approval_events')->count()]);
    }

    public function test_key_is_refused_for_another_campus_another_operation_or_another_requesters_request(): void
    {
        $this->client('k-scope');
        $this->machineDraft('k-scope', $this->otherCampus, [$this->bug('resolved', $this->otherCampus)])->assertForbidden();
        $this->withHeader('X-POP-MACHINE-KEY', 'k-scope')->postJson('/api/v1/pop/machine/operations/course-contract-repair/draft', [
            'parameters' => ['campus_id' => $this->campus], 'idempotency_key' => 'inapp-other-op'])->assertForbidden();
        $human = $this->service()->createDraft(self::OP, $this->params($this->campus, [$this->bug('resolved')], 'req-human'), 'idem-human', 'user:' . $this->admin->id, 'super_admin');
        $this->machineDryRun('k-scope', (string) $human['id'])->assertForbidden();
        $this->machineDryRun('k-scope', (string) Str::uuid())->assertForbidden();
        $this->assertSame(1, DB::table('pop_operation_requests')->count());
    }

    public static function deniedStates(): array
    {
        return [
            'empty campus list' => [['CampusIDs' => []], 403], 'missing campus list' => [['CampusIDs' => null], 403],
            'non-integer campus' => [['CampusIDs' => ['1']], 403], 'expired' => [['ExpiresAt' => '-1 minute'], 401],
            'no expiry' => [['ExpiresAt' => null], 401], 'revoked' => [['Active' => false], 401],
            'issuer demoted' => [['issuer' => ['type' => 'A']], 403], 'issuer suspended' => [['issuer' => ['status' => 'suspended']], 403],
            'missing scope' => [['Scopes' => ['pop:draft']], 403],
        ];
    }

    /** @dataProvider deniedStates */
    public function test_unusable_client_is_denied_on_the_machine_routes_and_by_the_guard(array $state, int $status): void
    {
        $bug = $this->bug('resolved');
        $request = (object) ['actor' => 'machine:api-client:' . $this->client('k-good')->id, 'operation_id' => self::OP,
            'parameters' => json_encode($this->params($this->campus, [$bug]))];
        $this->assertTrue(app(InappRequesterGuard::class)->allows($request));
        if (isset($state['issuer'])) {
            $this->admin->forceFill($state['issuer'])->save();
            $client = $this->client('k-bad');
        } else {
            $client = $this->client('k-bad', array_map(fn ($v) => is_string($v) && str_starts_with($v, '-') ? now()->modify($v) : $v, $state));
        }

        $this->machineDraft('k-bad', $this->campus, [$bug])->assertStatus($status);
        $request->actor = 'machine:api-client:' . $client->id;
        $this->assertFalse(app(InappRequesterGuard::class)->allows($request));
        $this->assertNull(app(InappRequesterGuard::class)->attributionUserId($request));
    }

    public function test_guard_allows_only_the_clients_own_listed_campus_and_governed_operation_and_attributes_to_the_issuer(): void
    {
        $client = $this->client('k-guard');
        $control = $this->client('k-control', ['Purpose' => 'pop_control_plane', 'CampusID' => $this->campus]);
        $guard = app(InappRequesterGuard::class);
        $request = fn (string $actor, string $op, mixed $campus): object => (object) ['actor' => $actor, 'operation_id' => $op,
            'parameters' => json_encode(['bug_ids' => [1], 'campus_id' => $campus, 'request_id' => 'req-guard'])];
        $actor = 'machine:api-client:' . $client->id;

        $this->assertTrue($guard->allows($request($actor, self::OP, $this->campus)));
        $this->assertSame($this->admin->id, $guard->attributionUserId($request($actor, self::OP, $this->campus)));
        foreach ([[$actor, self::OP, $this->otherCampus], [$actor, self::OP, (string) $this->campus], [$actor, self::OP, null],
            [$actor, 'course-contract-repair', $this->campus], ['user:' . $this->admin->id, self::OP, $this->campus],
            ['api-client:' . $client->id, self::OP, $this->campus], ['machine:api-client:' . $control->id, self::OP, $this->campus]] as $case) {
            $this->assertFalse($guard->allows($request(...$case)), json_encode($case));
            $this->assertNull($guard->attributionUserId($request(...$case)));
        }
        $this->assertFalse($guard->allows((object) ['actor' => $actor, 'operation_id' => self::OP, 'parameters' => '{not json']));
        $this->admin->delete();
        $this->assertNull($guard->attributionUserId($request($actor, self::OP, $this->campus)));
    }

    public function test_policy_approves_the_clients_request_and_writes_back_as_the_issuer(): void
    {
        $this->client('k-e2e');
        $id = $this->drafted('k-e2e', $bug = $this->eligibleBug(), 'req-e2e');

        $this->assertSame([$id], $this->policy()->approvePending($this->service())['approved']);
        $this->assertSame('succeeded', $this->service()->runApprovedLocally($id)['status']);
        $this->assertSame('closed', BugReport::query()->whereKey($bug)->value('status'));
        $this->assertSame($this->admin->id, (int) BugReportStatusLog::query()->where('bug_report_id', $bug)->where('to_status', 'closed')->value('changed_by'));
    }

    public static function revokePaths(): array
    {
        return ['admin endpoint' => ['delete'], 'api-clients revoke' => ['legacy']];
    }

    /** @dataProvider revokePaths */
    public function test_revoking_cancels_outstanding_policy_approved_requests_in_the_same_transaction(string $path): void
    {
        $client = $this->client('k-rev');
        $policyApproved = $this->drafted('k-rev', $bug = $this->eligibleBug(), 'req-policy');
        $this->assertSame([$policyApproved], $this->policy()->approvePending($this->service())['approved']);
        $humanApproved = $this->drafted('k-rev', $humanBug = $this->eligibleBug(), 'req-human');
        $this->assertTrue($this->service()->approve($humanApproved, 'inapp-human-review', 'user:' . $this->admin->id, 'super_admin', str_repeat('a', 40), $this->admin->id)['ready']);
        $waiting = $this->drafted('k-rev', $this->eligibleBug(), 'req-waiting');

        if ($path === 'delete') {
            $this->deleteJson("/api/v1/admin/pop-inapp-agent-clients/{$client->id}", [], $this->as($this->admin))
                ->assertOk()->assertJsonPath('cancelled_request_ids', [$policyApproved]);
            $this->assertSame(1, DB::table('security_audit_events')->where('event_type', 'pop_inapp_agent.revoked')->count());
        } else {
            $this->postJson("/api/v1/api-clients/{$client->id}/revoke", [], $this->as($this->admin))->assertOk();
        }

        $this->assertFalse($client->fresh()->Active);
        $this->assertSame(['cancelled', 'approved', 'awaiting_approval'], [$this->status($policyApproved), $this->status($humanApproved), $this->status($waiting)]);
        $revoked = DB::table('pop_approval_events')->where('operation_id', $policyApproved)->where('event_type', 'policy_revoked')->sole();
        $this->assertSame(['policy', $this->policy()->approverRef(), null], [$revoked->approver_role, $revoked->approver, $revoked->token_hash]);
        $this->assertSame('skipped', $this->service()->runApprovedLocally($policyApproved)['status']);
        $this->assertSame('resolved', BugReport::query()->whereKey($bug)->value('status'));
        // ADR-POP-015 §4: a super_admin-approved request is not affected by the requester's revocation.
        $this->assertSame('succeeded', $this->service()->runApprovedLocally($humanApproved)['status']);
        $this->assertSame('closed', BugReport::query()->whereKey($humanBug)->value('status'));
        $this->machineDraft('k-rev', $this->campus, [$this->eligibleBug()], 'req-after')->assertUnauthorized();
    }

    public function test_attendance_swipe_accepts_device_keys_only(): void
    {
        $this->client('k-swipe');
        $this->client('k-device', ['Purpose' => 'attendance_device', 'Scopes' => ['attendance:swipe'], 'CampusID' => $this->campus]);

        $this->withHeader('X-API-KEY', 'k-swipe')->postJson('/api/v1/attendance/swipe', ['RFID' => 'NOPE-1'])->assertUnauthorized();
        $this->withHeader('X-API-KEY', 'k-device')->postJson('/api/v1/attendance/swipe', ['RFID' => 'NOPE-1'])->assertStatus(202);
    }

    private function client(string $key, array $attributes = []): ApiClient
    {
        $client = new ApiClient();
        $client->forceFill(array_merge(['Name' => 'agent-' . Str::lower(Str::random(6)), 'ApiKeyHash' => hash('sha256', $key),
            'CampusID' => 0, 'Active' => true, 'Purpose' => InappAgentClients::PURPOSE, 'Scopes' => InappAgentClients::SCOPES,
            'IssuedByUserID' => $this->admin->id, 'CampusIDs' => [$this->campus], 'ExpiresAt' => now()->addDays(30)], $attributes))->save();

        return $client;
    }

    private function drafted(string $key, int $bug, string $rid): string
    {
        $id = (string) $this->machineDraft($key, $this->campus, [$bug], $rid)->assertCreated()->json('data.id');
        $this->machineDryRun($key, $id)->assertOk();

        return $id;
    }

    private function machineDraft(string $key, int $campus, array $bugIds, string $rid = 'req-001'): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('X-POP-MACHINE-KEY', $key)->postJson('/api/v1/pop/machine/operations/' . self::OP . '/draft',
            ['parameters' => $this->params($campus, $bugIds, $rid), 'idempotency_key' => 'inapp-' . $rid]);
    }

    private function machineDryRun(string $key, string $id): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('X-POP-MACHINE-KEY', $key)->postJson("/api/v1/pop/machine/operations/requests/{$id}/dry-run", []);
    }

    private function params(int $campus, array $bugIds, string $rid = 'req-001'): array
    {
        return ['bug_ids' => $bugIds, 'campus_id' => $campus, 'request_id' => $rid];
    }

    private function service(): PopOperationService
    {
        return new PopOperationService(app(PopOperationCatalog::class), null, $this->manifest);
    }

    private function policy(): InappStandingPolicy
    {
        return new InappStandingPolicy(app(PopOperationCatalog::class));
    }

    private function eligibleBug(): int
    {
        $id = $this->bug('resolved');
        $at = now()->subDays(20);
        BugReportComment::create(['bug_report_id' => $id, 'author_user_id' => $this->admin->id, 'body' => '請再試一次', 'is_internal_note' => false, 'created_at' => $at]);
        BugReportStatusLog::create(['bug_report_id' => $id, 'changed_by' => $this->admin->id, 'from_status' => 'in_progress',
            'to_status' => 'resolved', 'note' => '[resolution_evidence]{"production_revision":"662960e5"}', 'created_at' => $at]);

        return $id;
    }

    private function bug(string $status, ?int $campus = null): int
    {
        return BugReport::create(['CampusID' => $campus ?? $this->campus, 'reporter_user_id' => $this->user('T')->id, 'title' => 'T',
            'description' => 'D', 'severity' => 'low', 'status' => $status])->id;
    }

    private function user(string $type, ?string $status = null): User
    {
        $n = ++$this->seq;
        $user = User::create(['LoginName' => "popagent{$n}@test.com", 'Name' => "Agent {$n}", 'PSW' => 'secret', 'type' => $type, 'phone' => (string) (910000000 + $n)]);
        if ($status !== null) $user->forceFill(['status' => $status])->save();

        return $user;
    }

    private function status(string $id): string
    {
        return (string) DB::table('pop_operation_requests')->where('id', $id)->value('status');
    }

    /** @return array<string, string> */
    private function as(User $user): array
    {
        return ['X-User-Id' => (string) $user->id, 'Accept' => 'application/json'];
    }
}
