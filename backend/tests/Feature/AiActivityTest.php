<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\SecurityAuditEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** 帳務中心重整 G3 (Founder 2026-11-16): the AI activity card counts triage reads per table and lists governed operations. CI only. */
final class AiActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_counts_reads_per_table_and_day_and_lists_recent_operations(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-17 09:00:00', 'Asia/Taipei'));
        foreach ([['Invoice', 'success'], ['Invoice', 'success'], ['Payment', 'failure']] as [$table, $outcome]) {
            SecurityAuditEvent::append('triage_token.read', $outcome, ['actor_type' => 'triage_token', 'actor_id' => 1],
                ['source' => $table, 'route' => 'triage.db.read', 'http_status' => $outcome === 'success' ? 200 : 422], true);
        }
        Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00', 'Asia/Taipei'));
        SecurityAuditEvent::append('triage_token.read', 'success', ['actor_type' => 'triage_token', 'actor_id' => 1], ['source' => 'Old'], true);
        Carbon::setTestNow(Carbon::parse('2026-11-17 09:00:00', 'Asia/Taipei'));
        $op = (string) Str::uuid();
        DB::table('pop_operation_requests')->insert(['id' => $op, 'operation_id' => 'billing.period_rekey', 'strategy_id' => 'billing_period_rekey',
            'catalog_version' => 1, 'parameters' => '{}', 'parameters_hash' => str_repeat('a', 64), 'idempotency_key' => 'k1', 'status' => 'executed',
            'actor' => 'github-actions', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('pop_approval_events')->insert(['operation_id' => $op, 'event_type' => 'approved', 'approval_reference' => 'run-1', 'approver' => 'founder',
            'approver_role' => 'founder', 'commit_sha' => str_repeat('b', 40), 'phase' => 'apply', 'parameters_hash' => str_repeat('a', 64),
            'expires_at' => now()->addHour(), 'created_at' => now()]);
        DB::table('pop_approval_events')->insert(['operation_id' => $op, 'event_type' => 'policy_revoked', 'approval_reference' => 'run-2', 'approver' => 'founder',
            'approver_role' => 'founder', 'commit_sha' => str_repeat('b', 40), 'phase' => 'apply', 'parameters_hash' => str_repeat('a', 64),
            'expires_at' => now()->addHour(), 'created_at' => now()]);

        $data = $this->withToken($this->token('S'))->getJson('/api/v1/admin/ai-activity')->assertOk()->json('data');

        $this->assertSame([3, 1], [$data['reads']['total'], $data['reads']['refused']]);
        $this->assertSame([['name' => 'Invoice', 'count' => 2], ['name' => 'Payment', 'count' => 1]], $data['reads']['by_source']);
        $this->assertCount(1, $data['reads']['by_day']);
        $this->assertCount(1, $data['operations']);
        $this->assertSame(['billing.period_rekey', 'executed', 1], [$data['operations'][0]['operation'], $data['operations'][0]['status'], $data['operations'][0]['approval_events']]);
        Carbon::setTestNow();
    }

    public function test_is_super_admin_only_and_read_only(): void
    {
        $this->getJson('/api/v1/admin/ai-activity')->assertUnauthorized();
        $this->withToken($this->token('A'))->getJson('/api/v1/admin/ai-activity')->assertForbidden();
        $this->withToken($this->token('T'))->getJson('/api/v1/admin/ai-activity')->assertForbidden();
        $this->flushHeaders()->withToken($this->token('S'))->postJson('/api/v1/admin/ai-activity')->assertStatus(405);
    }

    private function token(string $type): string
    {
        $user = User::create(['LoginName' => 'ai-activity-' . uniqid() . '@test.com', 'Name' => 'AI', 'PSW' => 'secret', 'type' => $type, 'phone' => 912000004]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => Carbon::now()->addDay()]);

        return $raw;
    }
}
