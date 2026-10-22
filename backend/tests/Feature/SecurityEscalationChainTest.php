<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Combined regression for the 2026-10-22 authorization fixes (#4414 #4420 #4421 #4422 and the campus-scope PRs).
 * Tokens come from the real register/login endpoints; every refusal also checks that nothing was written.
 * Synthetic data only.
 */
class SecurityEscalationChainTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_self_registered_director_without_an_approved_campus_reaches_nothing(): void
    {
        $campus = CampusFactory::new()->create();
        $student = Student::create(['name' => 'Chain student', 'CampusID' => $campus->id, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $teacher = $this->account('T', [$campus->id], 'chain-teacher@example.com');

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Self registered', 'email' => 'self-dir@example.com', 'password' => 'Password123', 'role' => 'director', 'branch_id' => $campus->id,
        ])->assertSuccessful();
        $selfId = (int) DB::table('User')->where('LoginName', 'self-dir@example.com')->value('id');
        $token = $this->login('self-dir@example.com');
        $state = fn () => [
            DB::table('Campus')->where('id', $campus->id)->first(['messaging_channel_token', 'messaging_channel_secret', 'LIFFID']),
            DB::table('UserCampus')->whereIn('UserID', [$selfId, $teacher->id])->orderBy('UserID')->orderBy('CampusID')->get(['UserID', 'CampusID', 'Approved'])->toArray(),
            DB::table('User')->where('id', $teacher->id)->first(['PSW', 'LoginName', 'status']),
            DB::table('User')->count(),
        ];
        $before = $state();

        $this->api($token)->postJson('/api/v1/line/settings', ['branch_id' => $campus->id, 'messaging_channel_token' => 'x', 'messaging_channel_secret' => 'y', 'liff_id' => 'z'])->assertForbidden();
        $this->api($token)->getJson("/api/v1/parent/payment-message/{$student->id}")->assertForbidden();
        $this->api($token)->putJson("/api/v1/profiles/{$teacher->id}", ['password' => 'Takeover123'])->assertForbidden();
        $this->api($token)->postJson('/api/v1/teacher_branches', [['teacher_id' => $selfId, 'branch_id' => $campus->id]])->assertForbidden();
        $this->api($token)->postJson('/api/v1/profiles', ['name' => 'x', 'account' => 'minted@example.com', 'password' => 'Password123', 'role' => 'director', 'campus_id' => $campus->id])->assertForbidden();

        $this->assertEquals($before, $state());
    }

    public function test_a_campus_director_cannot_chain_into_another_campus_or_another_account(): void
    {
        $own = CampusFactory::new()->create();
        $other = CampusFactory::new()->create();
        $director = $this->account('D', [$own->id], 'chain-dir@example.com');
        $foreignTeacher = $this->account('T', [$other->id], 'chain-foreign@example.com');
        $superAdmin = $this->account('S', [], 'chain-sa@example.com');
        $token = $this->login('chain-dir@example.com');
        $state = fn () => [
            DB::table('UserCampus')->orderBy('UserID')->orderBy('CampusID')->get(['UserID', 'CampusID', 'Approved'])->toArray(),
            DB::table('User')->orderBy('id')->get(['id', 'PSW', 'LoginName', 'type', 'status'])->toArray(),
        ];
        $before = $state();

        // Self-grant and "make a foreign teacher managed", then use it to take the account over.
        $this->api($token)->postJson('/api/v1/teacher_branches', [['teacher_id' => $director->id, 'branch_id' => $other->id]])->assertForbidden();
        $this->api($token)->postJson('/api/v1/teacher_branches', [['teacher_id' => $foreignTeacher->id, 'branch_id' => $own->id]])->assertForbidden();
        $this->api($token)->putJson("/api/v1/profiles/{$foreignTeacher->id}", ['password' => 'Takeover123'])->assertForbidden();
        $this->api($token)->putJson("/api/v1/profiles/{$superAdmin->id}", ['password' => 'Takeover123'])->assertForbidden();
        $this->api($token)->deleteJson("/api/v1/teacher_branches?teacher_id={$foreignTeacher->id}")->assertForbidden();
        $this->api($token)->postJson('/api/v1/profiles', ['name' => 'x', 'account' => 'minted2@example.com', 'password' => 'Password123', 'role' => 'director', 'campus_id' => $own->id])->assertForbidden();
        $this->api($token)->postJson('/api/v1/line/settings', ['branch_id' => $other->id, 'liff_id' => 'z'])->assertForbidden();

        $this->assertEquals($before, $state(), 'no account or campus link changed');
        $this->api($token)->getJson('/api/v1/learning-records?branch_id=' . $other->id)->assertForbidden();
    }

    public function test_legitimate_flows_still_work(): void
    {
        $own = CampusFactory::new()->create();
        $other = CampusFactory::new()->create();
        $this->account('D', [$own->id], 'ok-dir@example.com');
        $teacher = $this->account('T', [$own->id], 'ok-teacher@example.com');
        $director = $this->account('D', [$other->id], 'ok-other-dir@example.com');
        $token = $this->login('ok-dir@example.com');

        $this->api($token)->putJson("/api/v1/profiles/{$teacher->id}", ['name' => '同校老師'])->assertOk();
        $this->api($token)->postJson('/api/v1/line/settings', ['branch_id' => $own->id, 'liff_id' => 'own-liff'])->assertOk();
        $this->assertSame('同校老師', (string) $teacher->fresh()->Name);

        $sa = $this->login($this->account('S', [], 'ok-sa@example.com')->LoginName);
        $this->api($sa)->putJson("/api/v1/profiles/{$director->id}", ['name' => '主任新名'])->assertOk();
        $this->api($sa)->postJson('/api/v1/line/settings', ['branch_id' => $other->id, 'liff_id' => 'sa-liff'])->assertOk();
        $this->assertSame('主任新名', (string) $director->fresh()->Name);
    }

    public function test_a_teacher_linked_only_through_legacy_teacher_branches_stays_manageable(): void
    {
        // The legacy teacher_branches table is created by the test-only fixture migration, so this runs for real.
        $this->assertTrue(Schema::hasTable('teacher_branches'), 'test fixture must provide teacher_branches');
        $own = CampusFactory::new()->create();
        $this->account('D', [$own->id], 'legacy-dir@example.com');
        $legacy = $this->account('T', [], 'legacy-teacher@example.com');
        DB::table('teacher_branches')->insert(['teacher_id' => $legacy->id, 'branch_id' => $own->id]);
        $token = $this->login('legacy-dir@example.com');

        $this->api($token)->putJson("/api/v1/profiles/{$legacy->id}", ['name' => '舊綁定老師'])->assertOk();
        $this->api($token)->postJson('/api/v1/teacher_branches', [['teacher_id' => $legacy->id, 'branch_id' => $own->id]])->assertOk();
        $this->assertSame('舊綁定老師', (string) $legacy->fresh()->Name);
    }

    /** @param list<int> $campusIds */
    private function account(string $type, array $campusIds, string $login): User
    {
        $user = User::create(['LoginName' => $login, 'Name' => 'u', 'type' => $type, 'status' => 'active',
            'PSW' => password_hash('Password123', PASSWORD_DEFAULT), 'phone' => '09' . random_int(10000000, 99999999), 'MustChangePassword' => false]);
        foreach ($campusIds as $campusId) {
            UserCampus::create(['UserID' => $user->id, 'CampusID' => $campusId, 'Admin' => $type === 'T' ? 0 : 1, 'Approved' => 1]);
        }

        return $user;
    }

    private function login(string $login): string
    {
        return (string) $this->postJson('/api/v1/auth/login', ['email' => $login, 'password' => 'Password123'])
            ->assertOk()->json('data.session.access_token');
    }

    private function api(string $token)
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }
}
