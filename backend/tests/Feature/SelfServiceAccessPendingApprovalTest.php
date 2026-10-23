<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Database\Factories\CampusFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Founder policy 2026-10-22: self-service access requests end pending until the resource owner approves.
 *  - Policy 2: a public role=director sign-up is a director application (type U), approved by a super_admin.
 *  - Policy 1: a campus a teacher adds on their own profile waits for that campus's director.
 * Synthetic data only.
 */
class SelfServiceAccessPendingApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_director_sign_up_is_a_pending_application_without_director_capability(): void
    {
        config(['app.director_registration_token' => null]);
        $campus = CampusFactory::new()->create();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Public Director',
            'account' => 'public-director@example.com',
            'password' => 'secret1234',
            'role' => 'director',
            'branch_id' => $campus->id,
        ])->assertStatus(201);
        $applicantId = (int) $response->json('pending_application_id');

        $this->assertDatabaseHas('User', ['id' => $applicantId, 'LoginName' => 'public-director@example.com', 'type' => 'U']);
        $this->assertDatabaseMissing('User', ['LoginName' => 'public-director@example.com', 'type' => 'D']);
        $this->assertSame(
            [['CampusID' => (int) $campus->id, 'Admin' => 0, 'Approved' => 0]],
            $this->links($applicantId)
        );

        // Listed for super_admin approval, exactly like a /directors/register application.
        $superAdmin = UserFactory::new()->superAdmin()->create();
        $pending = $this->getJson('/api/v1/directors/pending', ['X-User-Id' => (string) $superAdmin->id])
            ->assertOk()
            ->json();
        $this->assertSame([$applicantId], array_column($pending, 'id'));

        // No director capability: the session role is "pending" and director routes refuse it.
        $login = $this->postJson('/api/v1/auth/login', [
            'account' => 'public-director@example.com',
            'password' => 'secret1234',
        ])->assertOk()->assertJsonPath('data.session.user.role', 'pending');
        $token = (string) $login->json('data.session.access_token');
        $this->getJson('/api/v1/teacher_branches', $this->bearer($token))->assertForbidden();
    }

    public function test_super_admin_approval_turns_the_application_into_a_director(): void
    {
        config(['app.director_registration_token' => null]);
        $campus = CampusFactory::new()->create();
        $applicantId = (int) $this->postJson('/api/v1/auth/register', [
            'name' => 'Approve Me',
            'account' => 'approve-me@example.com',
            'password' => 'secret1234',
            'role' => 'director',
            'branch_id' => $campus->id,
        ])->assertStatus(201)->json('pending_application_id');

        $superAdmin = UserFactory::new()->superAdmin()->create();
        $this->postJson("/api/v1/directors/{$applicantId}/approve", [], ['X-User-Id' => (string) $superAdmin->id])
            ->assertOk();

        $this->assertDatabaseHas('User', ['id' => $applicantId, 'type' => 'D']);
        $this->assertSame([['CampusID' => (int) $campus->id, 'Admin' => 0, 'Approved' => 1]], $this->links($applicantId));
        $this->postJson('/api/v1/auth/login', ['account' => 'approve-me@example.com', 'password' => 'secret1234'])
            ->assertOk()
            ->assertJsonPath('data.session.user.role', 'director');
    }

    public function test_public_director_sign_up_uses_the_director_application_gates(): void
    {
        $campus = CampusFactory::new()->create();
        $payload = [
            'name' => 'Gated Director',
            'account' => 'gated-director@example.com',
            'password' => 'secret1234',
            'role' => 'director',
            'branch_id' => $campus->id,
        ];

        // Invite token configured: same 403 as /directors/register, nothing created.
        config(['app.director_registration_token' => 'invite-abc']);
        $this->postJson('/api/v1/auth/register', $payload)->assertForbidden();
        $this->assertDatabaseMissing('User', ['LoginName' => 'gated-director@example.com']);

        // A director application names its campus.
        config(['app.director_registration_token' => null]);
        $this->postJson('/api/v1/auth/register', array_diff_key($payload, ['branch_id' => true]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['campus_id']);
        $this->assertDatabaseMissing('User', ['LoginName' => 'gated-director@example.com']);

        config(['app.director_registration_token' => 'invite-abc']);
        $this->postJson('/api/v1/auth/register', $payload + ['registration_token' => 'invite-abc'])
            ->assertStatus(201);
        $this->assertDatabaseHas('User', ['LoginName' => 'gated-director@example.com', 'type' => 'U']);
    }

    public function test_teacher_self_join_creates_a_pending_link_and_keeps_existing_links(): void
    {
        $home = CampusFactory::new()->create();
        $second = CampusFactory::new()->create();
        $joined = CampusFactory::new()->create();
        $teacher = $this->teacher();
        UserCampus::create(['UserID' => $teacher->id, 'CampusID' => $home->id, 'Admin' => 0, 'Approved' => 1, 'RFID' => '0011223344']);
        UserCampus::create(['UserID' => $teacher->id, 'CampusID' => $second->id, 'Admin' => 0, 'Approved' => 1]);
        $token = $this->token($teacher);

        $this->putJson('/api/v1/me', [
            'branch_id' => $home->id,
            'multi_branches' => [$second->id, $joined->id],
        ], $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('teacher_config.branch_id', (int) $home->id)
            ->assertJsonPath('teacher_config.branch_ids', [(int) $home->id, (int) $second->id, (int) $joined->id])
            ->assertJsonPath('teacher_config.pending_branch_ids', [(int) $joined->id]);

        $rows = DB::table('UserCampus')->where('UserID', $teacher->id)->get()->keyBy('CampusID');
        $this->assertSame(1, (int) $rows[$home->id]->Approved);
        $this->assertSame('0011223344', (string) $rows[$home->id]->RFID, 'existing link keeps its card');
        $this->assertSame(1, (int) $rows[$second->id]->Approved);
        $this->assertSame(0, (int) $rows[$joined->id]->Approved);

        // The pending campus grants nothing yet.
        $campuses = $this->getJson('/api/v1/me', $this->bearer($token))->assertOk()->json('campuses');
        $this->assertEqualsCanonicalizing([(int) $home->id, (int) $second->id], $campuses);
    }

    public function test_teacher_cannot_approve_a_pending_link_by_saving_the_profile(): void
    {
        $home = CampusFactory::new()->create();
        $waiting = CampusFactory::new()->create();
        $teacher = $this->teacher();
        UserCampus::create(['UserID' => $teacher->id, 'CampusID' => $home->id, 'Admin' => 0, 'Approved' => 1]);
        UserCampus::create(['UserID' => $teacher->id, 'CampusID' => $waiting->id, 'Admin' => 0, 'Approved' => 0]);

        $this->putJson('/api/v1/me', [
            'branch_id' => $home->id,
            'multi_branches' => [$waiting->id],
        ], $this->bearer($this->token($teacher)))
            ->assertOk()
            ->assertJsonPath('teacher_config.pending_branch_ids', [(int) $waiting->id]);

        $this->assertSame(
            [
                ['CampusID' => (int) $home->id, 'Admin' => 0, 'Approved' => 1],
                ['CampusID' => (int) $waiting->id, 'Admin' => 0, 'Approved' => 0],
            ],
            $this->links((int) $teacher->id)
        );
    }

    public function test_only_the_joined_campus_director_sees_and_approves_the_self_join(): void
    {
        $home = CampusFactory::new()->create();
        $joined = CampusFactory::new()->create();
        $unrelated = CampusFactory::new()->create();
        $teacher = $this->teacher();
        UserCampus::create(['UserID' => $teacher->id, 'CampusID' => $home->id, 'Admin' => 0, 'Approved' => 1]);
        $teacherToken = $this->token($teacher);
        $this->putJson('/api/v1/me', ['branch_id' => $home->id, 'multi_branches' => [$joined->id]], $this->bearer($teacherToken))
            ->assertOk();

        $joinedDirector = $this->director($joined->id);
        $unrelatedDirector = $this->director($unrelated->id);

        // The joined campus's director gets the existing pending-teacher badge; another campus's does not.
        $this->getJson('/api/v1/notifications/unread-count', $this->bearer($this->token($joinedDirector)))
            ->assertOk()
            ->assertJsonPath('by_type.pending_teachers.total', 1);
        $unrelatedToken = $this->token($unrelatedDirector);
        $badges = $this->getJson('/api/v1/notifications/unread-count', $this->bearer($unrelatedToken))
            ->assertOk()
            ->json('by_type');
        $this->assertArrayNotHasKey('pending_teachers', (array) $badges);

        // Another campus's director cannot approve it.
        $this->postJson("/api/v1/profiles/{$teacher->id}/campus-links/{$joined->id}/approve", [], $this->bearer($unrelatedToken))
            ->assertForbidden();
        $this->assertSame(0, $this->approvedFlag((int) $teacher->id, (int) $joined->id));

        // The joined campus's director approves it through the campus-link approval endpoint (#4457).
        $this->postJson("/api/v1/profiles/{$teacher->id}/campus-links/{$joined->id}/approve", [], $this->bearer($this->token($joinedDirector)))
            ->assertOk();
        $this->assertSame(1, $this->approvedFlag((int) $teacher->id, (int) $joined->id));
        $campuses = $this->getJson('/api/v1/me', $this->bearer($teacherToken))->assertOk()->json('campuses');
        $this->assertEqualsCanonicalizing([(int) $home->id, (int) $joined->id], $campuses);
    }

    public function test_teacher_cannot_drop_every_approved_campus_for_a_pending_one(): void
    {
        $home = CampusFactory::new()->create();
        $elsewhere = CampusFactory::new()->create();
        $teacher = $this->teacher();
        UserCampus::create(['UserID' => $teacher->id, 'CampusID' => $home->id, 'Admin' => 0, 'Approved' => 1]);

        $this->putJson('/api/v1/me', ['branch_id' => $elsewhere->id, 'multi_branches' => []], $this->bearer($this->token($teacher)))
            ->assertStatus(422);

        $this->assertSame([['CampusID' => (int) $home->id, 'Admin' => 0, 'Approved' => 1]], $this->links((int) $teacher->id));
    }

    public function test_duplicate_rows_for_a_campus_keep_the_approved_one(): void
    {
        $home = CampusFactory::new()->create();
        $teacher = $this->teacher();
        UserCampus::create(['UserID' => $teacher->id, 'CampusID' => $home->id, 'Admin' => 0, 'Approved' => 1, 'RFID' => '0099887766']);
        UserCampus::create(['UserID' => $teacher->id, 'CampusID' => $home->id, 'Admin' => 0, 'Approved' => 0]);

        $this->putJson('/api/v1/me', ['branch_id' => $home->id, 'multi_branches' => []], $this->bearer($this->token($teacher)))->assertOk();

        $rows = DB::table('UserCampus')->where('UserID', $teacher->id)->get();
        $this->assertSame([[1, '0099887766']], $rows->map(fn ($r) => [(int) $r->Approved, (string) $r->RFID])->all());
    }

    private function teacher(): User
    {
        return User::create([
            'LoginName' => 'teacher-' . uniqid() . '@example.com',
            'Name' => 'Teacher',
            'PSW' => password_hash('Password123', PASSWORD_DEFAULT),
            'type' => 'T',
            'status' => 'active',
            'phone' => '09' . random_int(10000000, 99999999),
        ]);
    }

    private function director(int $campusId): User
    {
        $director = User::create([
            'LoginName' => 'director-' . uniqid() . '@example.com',
            'Name' => 'Director',
            'PSW' => password_hash('Password123', PASSWORD_DEFAULT),
            'type' => 'D',
            'status' => 'active',
            'phone' => '09' . random_int(10000000, 99999999),
        ]);
        UserCampus::create(['UserID' => $director->id, 'CampusID' => $campusId, 'Admin' => 1, 'Approved' => 1]);

        return $director;
    }

    private function token(User $user): string
    {
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        return $raw;
    }

    /** @return array<string, string> */
    private function bearer(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    /** @return list<array{CampusID: int, Admin: int, Approved: int}> */
    private function links(int $userId): array
    {
        return DB::table('UserCampus')->where('UserID', $userId)->orderBy('CampusID')->get()
            ->map(fn ($row) => ['CampusID' => (int) $row->CampusID, 'Admin' => (int) $row->Admin, 'Approved' => (int) $row->Approved])
            ->values()
            ->all();
    }

    private function approvedFlag(int $userId, int $campusId): int
    {
        return (int) DB::table('UserCampus')->where('UserID', $userId)->where('CampusID', $campusId)->value('Approved');
    }
}
