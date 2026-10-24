<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #4433 (Founder policy 1): a teacher's pending request to join campus B (UserCampus.Approved = 0) gives B's director no
 * management of that teacher until B approves it. Registration also writes a teacher_branches row for the requested
 * campus; that legacy row must not count either. Synthetic data only.
 */
class PendingCampusLinkNoManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pending_request_gives_that_campus_director_no_management(): void
    {
        [$a, $b] = [(int) CampusFactory::new()->create()->id, (int) CampusFactory::new()->create()->id];
        $teacher = $this->teacherRequestingB($a, $b);
        $directorB = $this->token($this->user('A', [$b => 1]));
        $snapshot = fn () => [
            DB::table('User')->where('id', $teacher->id)->first(['PSW', 'LoginName', 'Name', 'status']),
            DB::table('UserCampus')->where('UserID', $teacher->id)->orderBy('CampusID')->get(['CampusID', 'Approved', 'RFID'])->toArray(),
            DB::table('teacher_branches')->where('teacher_id', $teacher->id)->orderBy('branch_id')->pluck('branch_id')->all(),
        ];
        $before = $snapshot();

        $this->api($directorB)->postJson("/api/v1/profiles/{$teacher->id}/reset-password")->assertForbidden();
        $this->api($directorB)->putJson("/api/v1/profiles/{$teacher->id}", [
            'name' => 'changed', 'account' => 'changed-' . $teacher->id . '@example.com', 'password' => 'NewPassword123',
            'branch_id' => $b, 'multi_branches' => [],
        ])->assertForbidden();
        $this->api($directorB)->deleteJson("/api/v1/profiles/{$teacher->id}")->assertForbidden();
        $this->api($directorB)->postJson('/api/v1/teacher_branches', [['teacher_id' => $teacher->id, 'branch_id' => $b]])->assertForbidden();
        $this->api($directorB)->deleteJson("/api/v1/teacher_branches?teacher_id={$teacher->id}")->assertForbidden();

        $this->assertEquals($before, $snapshot(), 'password, login, links and the request are unchanged');
    }

    public function test_once_approved_the_link_grants_management_again(): void
    {
        [$a, $b] = [(int) CampusFactory::new()->create()->id, (int) CampusFactory::new()->create()->id];
        $teacher = $this->teacherRequestingB($a, $b);
        $directorB = $this->token($this->user('A', [$b => 1]));
        // B's director decides the request through the #4457 endpoint, which needs no management.
        $this->api($directorB)->postJson("/api/v1/profiles/{$teacher->id}/campus-links/{$b}/approve")->assertOk();

        $this->api($directorB)->putJson("/api/v1/profiles/{$teacher->id}", ['name' => '新名字'])->assertOk();
        $this->api($directorB)->postJson("/api/v1/profiles/{$teacher->id}/reset-password")->assertOk();

        // A self-registered teacher (account pending, every link pending) is still approvable by B's director:
        // 核准 approves B's link first, then the account (Codex r4358525420).
        $selfRegistered = $this->user('T', [$b => 0], 'pending');
        DB::table('teacher_branches')->insert(['teacher_id' => $selfRegistered->id, 'branch_id' => $b]);
        $this->api($directorB)->putJson("/api/v1/profiles/{$selfRegistered->id}", ['status' => 'active'])->assertForbidden();
        $this->api($directorB)->postJson("/api/v1/profiles/{$selfRegistered->id}/campus-links/{$b}/approve")->assertOk();
        $this->api($directorB)->putJson("/api/v1/profiles/{$selfRegistered->id}", ['status' => 'active'])->assertOk();
        $this->assertSame('active', (string) $selfRegistered->fresh()->status);

        // A legacy teacher bound to B only through teacher_branches (no UserCampus row at B) is still managed.
        $legacy = $this->user('T', []);
        DB::table('teacher_branches')->insert(['teacher_id' => $legacy->id, 'branch_id' => $b]);
        $this->api($directorB)->postJson("/api/v1/profiles/{$legacy->id}/reset-password")->assertOk();
        $this->api($directorB)->postJson('/api/v1/teacher_branches', [['teacher_id' => $legacy->id, 'branch_id' => $b]])->assertOk();
    }

    /** Active at A, pending at B, with the teacher_branches rows registration writes. */
    private function teacherRequestingB(int $a, int $b): User
    {
        $teacher = $this->user('T', [$a => 1, $b => 0]);
        foreach ([$a, $b] as $campusId) {
            DB::table('teacher_branches')->insert(['teacher_id' => $teacher->id, 'branch_id' => $campusId]);
        }

        return $teacher;
    }

    /** @param array<int, int> $links campus id => Approved */
    private function user(string $type, array $links, string $status = 'active'): User
    {
        $user = User::create([
            'LoginName' => strtolower($type) . '-' . uniqid() . '@example.com', 'Name' => 'u', 'type' => $type, 'status' => $status,
            'PSW' => password_hash('Password123', PASSWORD_DEFAULT), 'phone' => '09' . random_int(10000000, 99999999),
        ]);
        foreach ($links as $campusId => $approved) {
            UserCampus::create(['UserID' => $user->id, 'CampusID' => $campusId, 'Admin' => $type === 'T' ? 0 : 1, 'Approved' => $approved]);
        }

        return $user;
    }

    private function token(User $user): string
    {
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        return $raw;
    }

    private function api(string $token)
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }
}
