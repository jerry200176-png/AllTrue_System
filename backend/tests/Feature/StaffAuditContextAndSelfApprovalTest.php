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

    private function row(string $table, int $teacherId): int
    {
        $base = ['teacher_id' => $teacherId, 'branch_id' => 1, 'created_at' => now(), 'updated_at' => now()];
        $extra = match ($table) {
            'teacher_payroll_deductions' => ['deduction_key' => 'k', 'status' => 'pending', 'director_confirmed_at' => now()],
            'teacher_payroll_admin_allowances' => ['role_key' => 'admin_assist', 'rate' => 1, 'status' => 'pending', 'director_confirmed_at' => now()],
            'teacher_payroll_cash_adjustments' => ['amount' => 100, 'reason' => 'r', 'status' => 'pending', 'director_confirmed_at' => now()],
            'teacher_payroll_events' => ['event_date' => '2026-08-31', 'event_type' => 'holiday', 'status' => 'pending'],
            'teacher_payroll_achievements' => ['outcome_key' => 'k', 'status' => 'pending'],
            'fulltime_salary_profiles' => ['base_salary' => 30000, 'effective_from' => '2099-01-01'],
        };

        return DB::table($table)->insertGetId($base + $extra);
    }

    private function lastMetadata(string $type): array
    {
        return json_decode((string) DB::table('security_audit_events')->where('event_type', $type)->latest('id')->value('metadata'), true);
    }

    public function test_self_confirm_blocked_and_audit_carries_acting_as(): void
    {
        Config::set('staff_capabilities.multi_role_v1_enabled', true);
        $u = $this->dualUser();
        $id = $this->row('teacher_payroll_deductions', $u->id);

        $this->withHeaders($this->headers($u, 'director'))
            ->postJson("/api/v1/finance/teacher-eligibility/deductions/{$id}/confirm")
            ->assertStatus(422)->assertJsonPath('code', 'self_approval_forbidden');

        $meta = $this->lastMetadata('approval.self_blocked');
        $this->assertSame('director', $meta['acting_as']);
        $this->assertSame(1, $meta['capability_campus_count']);
    }

    /** @return array<string, array{0: ?string, 1: string, 2: string, 3: string}> [table, method, uri, actor] */
    public static function guardedRoutes(): array
    {
        $b = 'finance/teacher-eligibility/';
        $r = [];
        foreach (['deductions' => 'teacher_payroll_deductions', 'admin-allowances' => 'teacher_payroll_admin_allowances', 'cash-adjustments' => 'teacher_payroll_cash_adjustments'] as $seg => $t) {
            $r["$seg confirm"] = [$t, 'post', "$b$seg/{id}/confirm", 'director'];
            $r["$seg approve"] = [$t, 'post', "$b$seg/{id}/approve", 'hq'];
        }
        $r['deductions withdraw'] = ['teacher_payroll_deductions', 'post', "{$b}deductions/{id}/withdraw", 'director'];
        $r['cash-adjustments withdraw'] = ['teacher_payroll_cash_adjustments', 'post', "{$b}cash-adjustments/{id}/withdraw", 'director'];
        $r['events withdraw'] = ['teacher_payroll_events', 'post', "{$b}events/{id}/withdraw", 'director'];
        $r['achievements withdraw'] = ['teacher_payroll_achievements', 'post', "{$b}achievements/{id}/withdraw", 'director'];
        $r['achievements verify'] = ['teacher_payroll_achievements', 'post', "{$b}achievements/{id}/verify", 'director'];
        $r['events approve'] = ['teacher_payroll_events', 'post', "{$b}events/{id}/approve", 'director'];
        $r['salary-profile approve'] = ['fulltime_salary_profiles', 'post', "{$b}salary-profiles/{id}/approve", 'hq'];
        $r['parttime teacher-rules put'] = [null, 'put', 'finance/parttime-payroll/teacher-rules', 'hq'];
        $r['parttime teacher-rules put (director)'] = [null, 'put', 'finance/parttime-payroll/teacher-rules', 'director'];
        $r['parttime teacher-rules delete'] = [null, 'delete', 'finance/parttime-payroll/teacher-rules', 'hq'];

        return $r;
    }

    /** @dataProvider guardedRoutes */
    public function test_self_action_is_422_and_changes_nothing(?string $table, string $method, string $uri, string $actor): void
    {
        $u = $this->user($actor === 'hq' ? 'S' : 'A');
        if ($actor === 'director') {
            UserCampus::create(['UserID' => $u->id, 'CampusID' => 1, 'Admin' => 1, 'Approved' => 1]);
        }
        $id = $table ? $this->row($table, $u->id) : 0;
        $before = $table ? (array) DB::table($table)->where('id', $id)->first() : [];

        $res = $this->withHeaders($this->headers($u))->json($method, '/api/v1/'.str_replace('{id}', (string) $id, $uri), [
            'teacher_id' => $u->id, 'branch_id' => 1, 'base_rates' => [],
        ]);

        $res->assertStatus(422)->assertJsonPath('code', 'self_approval_forbidden');
        if ($table) {
            $this->assertSame($before, (array) DB::table($table)->where('id', $id)->first());
        }
    }

    public function test_director_acting_on_a_different_teacher_still_succeeds(): void
    {
        $u = $this->user('A');
        UserCampus::create(['UserID' => $u->id, 'CampusID' => 1, 'Admin' => 1, 'Approved' => 1]);
        $other = $this->user('T');
        $id = $this->row('teacher_payroll_deductions', $other->id);
        DB::table('teacher_payroll_deductions')->where('id', $id)->update(['director_confirmed_at' => null]);

        $this->withHeaders($this->headers($u))
            ->postJson("/api/v1/finance/teacher-eligibility/deductions/{$id}/confirm")
            ->assertOk();
        $this->withHeaders($this->headers($u))
            ->postJson("/api/v1/finance/teacher-eligibility/deductions/{$id}/withdraw")
            ->assertOk()->assertJsonPath('status', 'withdrawn');
    }

    public function test_audit_acting_as_null_when_flag_off(): void
    {
        $u = $this->user('A');
        UserCampus::create(['UserID' => $u->id, 'CampusID' => 1, 'Admin' => 1, 'Approved' => 1]);
        $id = $this->row('teacher_payroll_deductions', $u->id);

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

}
