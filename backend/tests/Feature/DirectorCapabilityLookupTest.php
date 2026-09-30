<?php

namespace Tests\Feature;

use App\Console\Commands\SendTuitionReminders;
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
