<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** PUT /profiles/{id} applies the same target rule as reset-password and delete. Synthetic data only. */
class ProfileUpdateTargetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_director_can_update_only_teachers_of_their_campuses(): void
    {
        $campus = CampusFactory::new()->create();
        $other = CampusFactory::new()->create();
        $director = $this->user('A', [$campus->id]);
        $token = $this->token($director);

        $targets = [
            'super admin' => $this->user('S', []),
            'another director' => $this->user('A', [$campus->id]),
            'the director themself' => $director,
            'teacher of another campus' => $this->user('T', [$other->id]),
        ];
        foreach ($targets as $label => $target) {
            $before = DB::table('User')->where('id', $target->id)->first(['PSW', 'LoginName', 'Name']);
            $this->api($token)->putJson("/api/v1/profiles/{$target->id}", [
                'password' => 'NewPassword123', 'account' => 'changed-' . $target->id . '@example.com', 'name' => 'changed',
            ])->assertForbidden();
            $this->assertEquals($before, DB::table('User')->where('id', $target->id)->first(['PSW', 'LoginName', 'Name']), "{$label} unchanged");
        }
        $this->assertSame(0, DB::table('UserCampus')->where('UserID', $director->id)->where('CampusID', $other->id)->count());

        $teacher = $this->user('T', [$campus->id]);
        $this->api($token)->putJson("/api/v1/profiles/{$teacher->id}", ['name' => '新名字'])->assertOk();
        $this->assertSame('新名字', (string) $teacher->fresh()->Name);
    }

    public function test_super_admin_can_still_update_a_director(): void
    {
        $campus = CampusFactory::new()->create();
        $target = $this->user('A', [$campus->id]);
        $this->api($this->token($this->user('S', [])))->putJson("/api/v1/profiles/{$target->id}", ['name' => '主任新名'])->assertOk();
        $this->assertSame('主任新名', (string) $target->fresh()->Name);
    }

    /** @param list<int> $campusIds */
    private function user(string $type, array $campusIds): User
    {
        $user = User::create([
            'LoginName' => strtolower($type) . '-' . uniqid() . '@example.com', 'Name' => 'u', 'type' => $type,
            'PSW' => password_hash('Password123', PASSWORD_DEFAULT), 'phone' => '09' . random_int(10000000, 99999999),
        ]);
        foreach ($campusIds as $campusId) {
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
