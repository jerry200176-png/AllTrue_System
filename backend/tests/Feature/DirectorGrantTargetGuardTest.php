<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use App\Models\UserCapabilityGrant;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Founder policy 3 (2026-10-22): a type T account that holds an unrevoked director grant (a merged staff identity) is a
 * director for account management, so an ordinary director cannot edit, reset, delete or relink it, with the multi-role
 * flag on or off. Synthetic data only.
 */
class DirectorGrantTargetGuardTest extends TestCase
{
    use RefreshDatabase;

    public static function flags(): array
    {
        return ['multi-role flag off' => [false], 'multi-role flag on' => [true]];
    }

    /** @dataProvider flags */
    public function test_a_director_cannot_manage_a_teacher_account_that_holds_a_director_grant(bool $multiRole): void
    {
        Config::set('staff_capabilities.multi_role_v1_enabled', $multiRole);
        $campus = CampusFactory::new()->create();
        $other = CampusFactory::new()->create();
        $token = $this->token($this->user('D', $campus->id));
        $target = $this->user('T', $campus->id);
        // The grant is on a campus the director does not manage: any unrevoked director grant counts.
        $this->grant($target->id, $other->id);
        foreach (["/api/v1/profiles?single=1&id={$target->id}", "/api/v1/teachers?single=1&id={$target->id}"] as $url) {
            $this->api($token)->getJson($url)->assertOk()
                ->assertJsonPath('profile_management_blocked_by_director_grant', true)
                ->assertJsonPath('profile_management_block_reason', 'director_grant_holder');
        }
        $state = fn () => [
            DB::table('User')->where('id', $target->id)->first(['PSW', 'LoginName', 'Name', 'status', 'MustChangePassword']),
            DB::table('UserCampus')->where('UserID', $target->id)->orderBy('CampusID')->get(['CampusID', 'Approved', 'Admin'])->toArray(),
        ];
        $before = $state();

        $this->api($token)->putJson("/api/v1/profiles/{$target->id}", ['password' => 'Takeover123', 'name' => 'x'])->assertForbidden();
        $this->api($token)->postJson("/api/v1/profiles/{$target->id}/reset-password")->assertForbidden();
        $this->api($token)->deleteJson("/api/v1/profiles/{$target->id}")->assertForbidden();
        $this->api($token)->postJson('/api/v1/teacher_branches', [['teacher_id' => $target->id, 'branch_id' => $campus->id]])->assertForbidden();
        $this->api($token)->postJson('/api/v1/teacher_branches', [['teacher_id' => $target->id, 'branch_id' => $other->id]])->assertForbidden();
        $this->api($token)->deleteJson("/api/v1/teacher_branches?teacher_id={$target->id}")->assertForbidden();

        $this->assertEquals($before, $state());
        $this->assertDatabaseHas('User', ['id' => $target->id]);
    }

    public function test_super_admin_still_manages_a_grant_holder_and_a_revoked_grant_makes_it_a_teacher_again(): void
    {
        $campus = CampusFactory::new()->create();
        $token = $this->token($this->user('D', $campus->id));
        $target = $this->user('T', $campus->id);
        $grant = $this->grant($target->id, $campus->id);

        $superToken = $this->token($this->user('S', null));
        $this->api($superToken)->getJson("/api/v1/profiles?single=1&id={$target->id}")->assertOk()
            ->assertJsonPath('profile_management_blocked_by_director_grant', false);
        $this->api($superToken)->putJson("/api/v1/profiles/{$target->id}", ['name' => '超管改名'])->assertOk();
        $this->assertSame('超管改名', (string) $target->fresh()->Name);

        $grant->forceFill(['revoked_at' => now()])->save();
        $this->api($token)->getJson("/api/v1/profiles?single=1&id={$target->id}")->assertOk()
            ->assertJsonPath('profile_management_blocked_by_director_grant', false);
        $this->api($token)->putJson("/api/v1/profiles/{$target->id}", ['name' => '主任改名'])->assertOk();
        $this->assertSame('主任改名', (string) $target->fresh()->Name);
    }

    public function test_a_grant_holder_acting_as_director_still_edits_their_own_profile(): void
    {
        Config::set('staff_capabilities.multi_role_v1_enabled', true);
        $campus = CampusFactory::new()->create();
        $holder = $this->user('T', $campus->id);
        $this->grant($holder->id, $campus->id);
        $this->grant($holder->id, $campus->id, 'teacher');

        $token = $this->token($holder);
        $this->api($token)->withHeaders(['X-Acting-As' => 'director'])
            ->getJson("/api/v1/profiles?single=1&id={$holder->id}")->assertOk()
            ->assertJsonPath('profile_management_blocked_by_director_grant', false);
        $this->api($token)->withHeaders(['X-Acting-As' => 'director'])
            ->putJson("/api/v1/profiles/{$holder->id}", ['name' => '自己改名'])->assertOk();
        $this->assertSame('自己改名', (string) $holder->fresh()->Name);
    }

    public function test_list_shapes_and_grant_lookup_do_not_grow_per_teacher(): void
    {
        $campus = CampusFactory::new()->create();
        $token = $this->token($this->user('D', $campus->id));
        $target = $this->user('T', $campus->id);
        $this->grant($target->id, $campus->id);
        $lookupCount = function () use ($token, $target): int {
            DB::flushQueryLog();
            $response = $this->api($token)->getJson('/api/v1/teachers?per_page=all')->assertOk();
            $item = collect($response->json())->firstWhere('id', $target->id);
            $this->assertTrue($item['profile_management_blocked_by_director_grant']);
            return collect(DB::getQueryLog())->filter(fn ($q) => str_contains(strtolower($q['query']), 'from `user_capability_grants`'))->count();
        };
        DB::enableQueryLog();
        try {
            $before = $lookupCount();
            $this->assertGreaterThanOrEqual(1, $before, 'the query budget must measure actual grant reads');
            for ($i = 0; $i < 8; $i++) $this->user('T', $campus->id);
            $this->assertSame($before, $lookupCount(), 'grant lookup count must stay bounded as the list grows');
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $page = $this->api($token)->getJson('/api/v1/teachers?per_page=200')->assertOk();
        $item = collect($page->json('data'))->firstWhere('id', $target->id);
        $this->assertTrue($item['profile_management_blocked_by_director_grant']);
    }

    private function grant(int $userId, int $campusId, string $capability = 'director'): UserCapabilityGrant
    {
        return UserCapabilityGrant::create([
            'user_id' => $userId, 'capability' => $capability, 'campus_id' => $campusId, 'granted_by' => null, 'granted_at' => now(), 'revoked_at' => null,
        ]);
    }

    private function user(string $type, ?int $campusId): User
    {
        $user = User::create(['LoginName' => 'grant-' . strtolower($type) . '-' . uniqid() . '@example.com', 'Name' => 'u', 'type' => $type, 'status' => 'active',
            'PSW' => password_hash('Password123', PASSWORD_DEFAULT), 'phone' => '09' . random_int(10000000, 99999999), 'MustChangePassword' => false]);
        if ($campusId !== null) {
            UserCampus::create(['UserID' => $user->id, 'CampusID' => $campusId, 'Admin' => $type === 'T' ? 0 : 1, 'Approved' => 1]);
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
