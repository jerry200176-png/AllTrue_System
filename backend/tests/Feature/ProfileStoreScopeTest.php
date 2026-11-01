<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** POST /profiles below super_admin creates teachers in the caller's campus only. Synthetic data only. */
class ProfileStoreScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_director_cannot_create_a_director_or_an_account_at_another_campus(): void
    {
        $campus = CampusFactory::new()->create();
        $other = CampusFactory::new()->create();
        $token = $this->token('A', [$campus->id]);
        $payload = fn (array $over) => array_merge(['name' => '新帳號', 'password' => 'Password123', 'role' => 'teacher', 'campus_id' => $campus->id], $over);

        $this->api($token)->postJson('/api/v1/profiles', $payload(['account' => 'new-dir@example.com', 'role' => 'director']))->assertForbidden();
        $this->api($token)->postJson('/api/v1/profiles', $payload(['account' => 'new-t-other@example.com', 'campus_id' => $other->id]))->assertForbidden();
        $this->assertDatabaseMissing('User', ['LoginName' => 'new-dir@example.com']);
        $this->assertDatabaseMissing('User', ['LoginName' => 'new-t-other@example.com']);

        $this->api($token)->postJson('/api/v1/profiles', $payload(['account' => 'new-t@example.com']))->assertCreated();
        $this->assertDatabaseHas('User', ['LoginName' => 'new-t@example.com', 'type' => 'T']);
    }

    public function test_multi_branches_cannot_reach_a_campus_the_caller_does_not_manage(): void
    {
        $campus = CampusFactory::new()->create();
        $other = CampusFactory::new()->create();
        $token = $this->token('A', [$campus->id]);

        // #4433 (Founder policy 1): a multi_branches entry at a foreign campus only requests it (pending, for that campus's
        // director); the caller never grants an approved link there.
        $this->api($token)->postJson('/api/v1/profiles', [
            'name' => 'x', 'account' => 'mb-foreign@example.com', 'password' => 'Password123', 'role' => 'teacher',
            'campus_id' => $campus->id, 'multi_branches' => [$other->id],
        ])->assertCreated();
        $foreignUid = (int) \App\Models\User::where('LoginName', 'mb-foreign@example.com')->value('id');
        $this->assertDatabaseHas('UserCampus', ['UserID' => $foreignUid, 'CampusID' => $other->id, 'Approved' => 0]);
        $this->assertDatabaseMissing('UserCampus', ['UserID' => $foreignUid, 'CampusID' => $other->id, 'Approved' => 1]);
        // A primary campus the caller doesn't manage is still refused, and creates nothing.
        $this->api($token)->postJson('/api/v1/profiles', [
            'name' => 'y', 'account' => 'main-foreign@example.com', 'password' => 'Password123', 'role' => 'teacher', 'campus_id' => $other->id,
        ])->assertForbidden();
        $this->assertDatabaseMissing('User', ['LoginName' => 'main-foreign@example.com']);

        // multi_branches entirely within the caller's campuses still works.
        $second = CampusFactory::new()->create();
        $token2 = $this->token('A', [$campus->id, $second->id]);
        $created = $this->api($token2)->postJson('/api/v1/profiles', [
            'name' => 'ok', 'account' => 'mb-ok@example.com', 'password' => 'Password123', 'role' => 'teacher',
            'campus_id' => $campus->id, 'multi_branches' => [$second->id],
        ])->assertCreated();
        $uid = (int) \App\Models\User::where('LoginName', 'mb-ok@example.com')->value('id');
        $this->assertDatabaseHas('UserCampus', ['UserID' => $uid, 'CampusID' => $second->id]);
    }

    public function test_super_admin_can_still_create_a_director(): void
    {
        $campus = CampusFactory::new()->create();
        $this->api($this->token('S', []))->postJson('/api/v1/profiles', [
            'name' => '主任', 'account' => 'sa-made-dir@example.com', 'password' => 'Password123', 'role' => 'director', 'campus_id' => $campus->id,
        ])->assertCreated();
        $this->assertDatabaseHas('User', ['LoginName' => 'sa-made-dir@example.com', 'type' => 'D']);
    }

    /** @param list<int> $campusIds */
    private function token(string $type, array $campusIds): string
    {
        $user = User::create(['LoginName' => strtolower($type) . '-' . uniqid() . '@example.com', 'Name' => 'u', 'type' => $type,
            'PSW' => password_hash('Password123', PASSWORD_DEFAULT), 'phone' => '09' . random_int(10000000, 99999999), 'MustChangePassword' => false]);
        foreach ($campusIds as $campusId) {
            UserCampus::create(['UserID' => $user->id, 'CampusID' => $campusId, 'Admin' => 1, 'Approved' => 1]);
        }
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        return $raw;
    }

    private function api(string $token)
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }
}
