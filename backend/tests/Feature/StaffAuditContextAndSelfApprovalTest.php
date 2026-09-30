<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use App\Models\UserCapabilityGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** #2908 step 3: acting_as in security audit + hard block on self-approval. */
class StaffAuditContextAndSelfApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $type): User
    {
        return User::create([
            'LoginName' => 'sod-'.uniqid('', true).'@example.com', 'Name' => 'SoD', 'PSW' => 'x',
            'type' => $type, 'phone' => '09'.random_int(10000000, 99999999), 'MustChangePassword' => false,
        ]);
    }

    private function headers(User $u, ?string $actingAs = null): array
    {
        $t = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $u->id, 'token' => $t, 'expires_at' => now()->addDay()]);

        return ['Authorization' => "Bearer {$t}", 'Accept' => 'application/json'] + ($actingAs ? ['X-Acting-As' => $actingAs] : []);
    }

    private function dualUser(): User
    {
        $u = $this->user('T');
        foreach (['director', 'teacher'] as $cap) {
            UserCapabilityGrant::create(['user_id' => $u->id, 'capability' => $cap, 'campus_id' => 1, 'granted_at' => now()]);
        }

        return $u;
    }

    private function deduction(int $teacherId): int
    {
        return DB::table('teacher_payroll_deductions')->insertGetId([
            'teacher_id' => $teacherId, 'branch_id' => 1, 'deduction_key' => 'k', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function lastMetadata(string $type): array
    {
        return json_decode((string) DB::table('security_audit_events')->where('event_type', $type)->latest('id')->value('metadata'), true);
    }

    public function test_self_confirm_blocked_and_audit_carries_acting_as(): void
    {
        Config::set('staff_capabilities.multi_role_v1_enabled', true);
        $u = $this->dualUser();
        $id = $this->deduction($u->id);

        $this->withHeaders($this->headers($u, 'director'))
            ->postJson("/api/v1/finance/teacher-eligibility/deductions/{$id}/confirm")
            ->assertStatus(422)->assertJsonPath('code', 'self_approval_forbidden');

        $meta = $this->lastMetadata('approval.self_blocked');
        $this->assertSame('director', $meta['acting_as']);
        $this->assertSame(1, $meta['capability_campus_count']);
        $this->assertNull(DB::table('teacher_payroll_deductions')->where('id', $id)->value('director_confirmed_at'));
    }

    public function test_audit_acting_as_null_when_flag_off(): void
    {
        $u = $this->user('A');
        UserCampus::create(['UserID' => $u->id, 'CampusID' => 1, 'Admin' => 1, 'Approved' => 1]);
        $id = $this->deduction($u->id);

        $this->withHeaders($this->headers($u))
            ->postJson("/api/v1/finance/teacher-eligibility/deductions/{$id}/confirm")
            ->assertStatus(422)->assertJsonPath('code', 'self_approval_forbidden');

        $meta = $this->lastMetadata('approval.self_blocked');
        $this->assertArrayHasKey('acting_as', $meta);
        $this->assertNull($meta['acting_as']);
        $this->assertNull($meta['capability_campus_count']);
    }

    public function test_denied_context_emits_audit_event(): void
    {
        Config::set('staff_capabilities.multi_role_v1_enabled', true);
        $u = $this->user('T');
        UserCapabilityGrant::create(['user_id' => $u->id, 'capability' => 'teacher', 'campus_id' => 1, 'granted_at' => now()]);

        $this->withHeaders($this->headers($u, 'director'))->getJson('/api/v1/me')->assertForbidden();

        $this->assertSame('acting_context_denied', $this->lastMetadata('staff.context.denied')['reason_code']);
    }

    public function test_hq_cannot_approve_own_cash_adjustment_or_salary_profile_but_can_approve_others(): void
    {
        $hq = $this->user('S');
        $h = $this->headers($hq);
        $cash = fn (int $tid) => DB::table('teacher_payroll_cash_adjustments')->insertGetId([
            'teacher_id' => $tid, 'branch_id' => 1, 'amount' => 100, 'reason' => 'r', 'status' => 'pending',
            'director_confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $profile = fn (int $tid) => DB::table('fulltime_salary_profiles')->insertGetId([
            'teacher_id' => $tid, 'branch_id' => 1, 'base_salary' => 30000, 'effective_from' => '2099-01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $c = $cash($hq->id);
        $this->withHeaders($h)->postJson("/api/v1/finance/teacher-eligibility/cash-adjustments/{$c}/approve")
            ->assertStatus(422)->assertJsonPath('code', 'self_approval_forbidden');
        $p = $profile($hq->id);
        $this->withHeaders($h)->postJson("/api/v1/finance/teacher-eligibility/salary-profiles/{$p}/approve")
            ->assertStatus(422)->assertJsonPath('code', 'self_approval_forbidden');

        $c2 = $cash($hq->id + 1000);
        $this->withHeaders($h)->postJson("/api/v1/finance/teacher-eligibility/cash-adjustments/{$c2}/approve")
            ->assertOk()->assertJsonPath('status', 'approved');
    }
}
