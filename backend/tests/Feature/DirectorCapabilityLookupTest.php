<?php

namespace Tests\Feature;

use App\Console\Commands\SendTuitionReminders;
use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use App\Models\UserCapabilityGrant;
use App\Services\StaffCapabilityAuthorizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/** Rollout step 2 of #2908: "directors of campus X" lookups are capability-aware. */
class DirectorCapabilityLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_flag_off_returns_legacy_type_d_only_even_with_grants(): void
    {
        Config::set('staff_capabilities.multi_role_v1_enabled', false);
        $d = $this->makeUser('D', 1);
        $t = $this->makeUser('T', 1);
        $this->grant($t->id, 'director', 1);

        $this->assertSame([(int) $d->id], $this->helper()->directorUserIds(1));
        $this->assertSame([], $this->helper()->directorUserIds(2));
        $this->assertSame([(int) $d->id], $this->helper()->directorUserIds(null));
    }

    public function test_flag_on_granted_teacher_appears_on_granted_campus_only(): void
    {
        Config::set('staff_capabilities.multi_role_v1_enabled', true);
        $d = $this->makeUser('D', 1);
        $t = $this->makeUser('T', 1);
        $this->grant($t->id, 'director', 1);
        $this->grant($t->id, 'teacher', 2);

        $this->assertEqualsCanonicalizing([(int) $d->id, (int) $t->id], $this->helper()->directorUserIds(1));
        $this->assertSame([], $this->helper()->directorUserIds(2));
    }

    public function test_flag_on_revoked_grant_and_pure_teacher_are_excluded(): void
    {
        Config::set('staff_capabilities.multi_role_v1_enabled', true);
        $revoked = $this->makeUser('T', 1);
        $this->grant($revoked->id, 'director', 1, now());
        $pure = $this->makeUser('T', 1);
        $this->grant($pure->id, 'teacher', 1);
        $this->makeUser('T', 1); // legacy synthesized teacher, no grants

        $this->assertSame([], $this->helper()->directorUserIds(1));
    }

    public function test_tuition_reminder_recipients_include_granted_teacher_director(): void
    {
        Config::set('staff_capabilities.multi_role_v1_enabled', true);
        $d = $this->makeUser('D', 1);
        $t = $this->makeUser('T', 1);
        $this->grant($t->id, 'director', 1);
        $pure = $this->makeUser('T', 1);

        $cmd = new class extends SendTuitionReminders {
            public function recipients(int $campusId): array
            {
                return $this->directorRecipientIds($campusId);
            }
        };

        $this->assertEqualsCanonicalizing([(int) $d->id, (int) $t->id], $cmd->recipients(1));
        $this->assertNotContains((int) $pure->id, $cmd->recipients(1));
    }

    public function test_grants_on_deleted_suspended_inactive_or_wrong_type_users_are_excluded(): void
    {
        Config::set('staff_capabilities.multi_role_v1_enabled', true);
        $suspended = $this->makeUser('T', 1);
        $suspended->forceFill(['status' => 'suspended'])->save();
        $inactive = $this->makeUser('T', 1);
        $inactive->forceFill(['status' => 'inactive'])->save();
        $student = $this->makeUser('S', 1);
        $ok = $this->makeUser('T', 1);
        foreach ([$suspended, $inactive, $student, $ok] as $u) {
            $this->grant($u->id, 'director', 1);
        }
        $this->grant(987654, 'director', 1); // user row missing

        $this->assertSame([(int) $ok->id], $this->helper()->directorUserIds(1));
    }

    public function test_destroy_director_revokes_active_grants(): void
    {
        $admin = $this->makeUser('S', 1);
        $d = $this->makeUser('D', 1);
        $this->grant($d->id, 'director', 1);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $admin->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        $this->withHeader('Authorization', 'Bearer '.$token)->deleteJson('/api/v1/directors/'.$d->id)->assertOk();

        $this->assertSame(0, UserCapabilityGrant::query()->where('user_id', $d->id)->whereNull('revoked_at')->count());
        $this->assertSame(1, UserCapabilityGrant::query()->where('user_id', $d->id)->whereNotNull('revoked_at')->count());
    }

    public function test_director_index_exposes_type_for_capability_directors(): void
    {
        Config::set('staff_capabilities.multi_role_v1_enabled', true);
        $admin = $this->makeUser('S', 1);
        $d = $this->makeUser('D', 1);
        $t = $this->makeUser('T', 1);
        $this->grant($t->id, 'director', 1);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $admin->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        $rows = collect($this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/directors')->assertOk()->json());

        $this->assertSame('D', $rows->firstWhere('id', $d->id)['type']);
        $this->assertSame('T', $rows->firstWhere('id', $t->id)['type']);
    }

    private function helper(): StaffCapabilityAuthorizer
    {
        return app(StaffCapabilityAuthorizer::class);
    }

    private function makeUser(string $type, int $campusId): User
    {
        $user = User::create([
            'LoginName' => 'dir-lookup-'.uniqid('', true).'@example.com',
            'Name' => 'Dir Lookup',
            'PSW' => password_hash('secret-123', PASSWORD_DEFAULT),
            'type' => $type,
            'phone' => '09'.random_int(10000000, 99999999),
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 0, 'Approved' => 1]);

        return $user;
    }

    private function grant(int $userId, string $capability, int $campusId, $revokedAt = null): void
    {
        UserCapabilityGrant::create([
            'user_id' => $userId,
            'capability' => $capability,
            'campus_id' => $campusId,
            'granted_by' => null,
            'granted_at' => now(),
            'revoked_at' => $revokedAt,
        ]);
    }
}
