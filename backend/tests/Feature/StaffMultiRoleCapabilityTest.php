<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use App\Models\UserCapabilityGrant;
use App\Services\StaffCapabilityAuthorizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class StaffMultiRoleCapabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('staff_capabilities.multi_role_v1_enabled', true);
    }

    public function test_director_capability_on_a_and_b_teacher_only_on_a(): void
    {
        $user = $this->makeUser('A');
        $this->grant($user->id, 'director', 1);
        $this->grant($user->id, 'director', 2);
        $this->grant($user->id, 'teacher', 1);

        $auth = app(StaffCapabilityAuthorizer::class);
        $this->assertTrue($auth->hasCapabilityOnCampus($user, 'director', 1));
        $this->assertTrue($auth->hasCapabilityOnCampus($user, 'director', 2));
        $this->assertTrue($auth->hasCapabilityOnCampus($user, 'teacher', 1));
        $this->assertFalse($auth->hasCapabilityOnCampus($user, 'teacher', 2));

        $asTeacher = $auth->resolve($user, 'teacher');
        $this->assertSame('teacher', $asTeacher['role']);
        $this->assertSame([(int) $user->id], [$asTeacher['teacher_id']]);
        $this->assertSame([1], $asTeacher['campus_ids']);

        $asDirector = $auth->resolve($user, 'director');
        $this->assertSame('director', $asDirector['role']);
        $this->assertNull($asDirector['teacher_id']);
        $this->assertEqualsCanonicalizing([1, 2], $asDirector['campus_ids']);
    }

    public function test_acting_as_teacher_without_teacher_capability_does_not_escalate(): void
    {
        $user = $this->makeUser('A');
        $this->grant($user->id, 'director', 1);

        $resolved = app(StaffCapabilityAuthorizer::class)->resolve($user, 'teacher');
        $this->assertSame('forbidden', $resolved['role']);
        $this->assertNull($resolved['teacher_id']);
        $this->assertTrue($resolved['context_denied']);
        $this->assertSame([], $resolved['campus_ids']);
    }

    public function test_unknown_acting_as_context_is_rejected_instead_of_defaulting_to_director(): void
    {
        $user = $this->makeUser('A');
        $this->grant($user->id, 'director', 1);
        $this->grant($user->id, 'teacher', 1);

        $resolved = app(StaffCapabilityAuthorizer::class)->resolve($user, 'super_admin');

        $this->assertSame('forbidden', $resolved['role']);
        $this->assertTrue($resolved['context_denied']);
        $this->assertSame([], $resolved['campus_ids']);
    }

    public function test_teacher_only_capability_cannot_resolve_director_context(): void
    {
        $user = $this->makeUser('T');
        $this->grant($user->id, 'teacher', 1);

        $resolved = app(StaffCapabilityAuthorizer::class)->resolve($user, 'director');
        $this->assertSame('teacher', $resolved['role']);
        $this->assertSame((int) $user->id, $resolved['teacher_id']);
    }

    public function test_flag_off_preserves_legacy_type_mapping(): void
    {
        Config::set('staff_capabilities.multi_role_v1_enabled', false);
        $user = $this->makeUser('T');
        UserCampus::create(['UserID' => $user->id, 'CampusID' => 1, 'Admin' => 0, 'Approved' => 1]);

        $this->assertFalse(app(StaffCapabilityAuthorizer::class)->enabled());
        $this->grant($user->id, 'director', 1);
        $this->assertFalse(app(StaffCapabilityAuthorizer::class)->enabled());
        $token = $this->tokenFor($user);
        $this->withHeaders($this->bearer($token, null))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('role', 'teacher')
            ->assertJsonMissingPath('capabilities');
    }

    public function test_me_exposes_capability_map_and_honors_acting_as_context(): void
    {
        $user = $this->makeUser('A');
        $this->grant($user->id, 'director', 1);
        $this->grant($user->id, 'director', 2);
        $this->grant($user->id, 'teacher', 1);
        $token = $this->tokenFor($user);

        $asTeacher = $this->withHeaders($this->bearer($token, 'teacher'))
            ->getJson('/api/v1/me')
            ->assertOk();
        $asTeacher->assertJsonPath('id', $user->id)
            ->assertJsonPath('role', 'teacher')
            ->assertJsonPath('acting_as', 'teacher')
            ->assertJsonPath('campuses', [1]);
        $this->assertEqualsCanonicalizing(['director', 'teacher'], $asTeacher->json('capabilities'));
        $this->assertEqualsCanonicalizing([1, 2], $asTeacher->json('capability_campuses.director'));
        $this->assertSame([1], $asTeacher->json('capability_campuses.teacher'));

        $asDirector = $this->withHeaders($this->bearer($token, 'director'))
            ->getJson('/api/v1/me')
            ->assertOk();
        $asDirector->assertJsonPath('id', $user->id)
            ->assertJsonPath('role', 'director')
            ->assertJsonPath('acting_as', 'director');
        $this->assertEqualsCanonicalizing([1, 2], $asDirector->json('campuses'));
    }

    public function test_acting_as_header_alone_cannot_escalate_to_director_route(): void
    {
        $user = $this->makeUser('T');
        $this->grant($user->id, 'teacher', 1);
        UserCampus::create(['UserID' => $user->id, 'CampusID' => 1, 'Admin' => 0, 'Approved' => 1]);
        $token = $this->tokenFor($user);

        $this->withHeaders($this->bearer($token, 'director'))
            ->getJson('/api/v1/invoices')
            ->assertForbidden();
    }

    public function test_unrecognized_acting_as_header_is_forbidden_for_shared_me(): void
    {
        $user = $this->makeUser('A');
        $this->grant($user->id, 'director', 1);
        $this->grant($user->id, 'teacher', 1);
        $token = $this->tokenFor($user);

        $this->withHeaders($this->bearer($token, 'super_admin'))
            ->getJson('/api/v1/me')
            ->assertForbidden();
    }

    public function test_dual_capability_teacher_context_forbidden_on_director_only_route(): void
    {
        $user = $this->makeUser('A');
        $this->grant($user->id, 'director', 1);
        $this->grant($user->id, 'teacher', 1);
        $token = $this->tokenFor($user);

        $this->withHeaders($this->bearer($token, 'teacher'))
            ->getJson('/api/v1/invoices')
            ->assertForbidden();

        // Same identity as director context is allowed past role middleware
        // (campus/data access still applies inside the controller).
        $this->withHeaders($this->bearer($token, 'director'))
            ->getJson('/api/v1/invoices')
            ->assertOk();
    }

    public function test_shared_me_succeeds_without_acting_as_for_dual_capability(): void
    {
        $user = $this->makeUser('A');
        $this->grant($user->id, 'director', 1);
        $this->grant($user->id, 'teacher', 1);
        $token = $this->tokenFor($user);

        $this->withHeaders($this->bearer($token, null))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('role', 'director');
    }

    private function makeUser(string $type): User
    {
        return User::create([
            'LoginName' => 'multi-role-'.uniqid('', true).'@example.com',
            'Name' => 'Multi Role',
            'PSW' => password_hash('secret-123', PASSWORD_DEFAULT),
            'type' => $type,
            'phone' => '09'.random_int(10000000, 99999999),
            'MustChangePassword' => false,
        ]);
    }

    private function grant(int $userId, string $capability, int $campusId): void
    {
        UserCapabilityGrant::create([
            'user_id' => $userId,
            'capability' => $capability,
            'campus_id' => $campusId,
            'granted_by' => null,
            'granted_at' => now(),
            'revoked_at' => null,
        ]);
    }

    private function tokenFor(User $user): string
    {
        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }

    /** @return array<string, string> */
    private function bearer(string $token, ?string $actingAs): array
    {
        $headers = [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ];
        if ($actingAs !== null && $actingAs !== '') {
            $headers['X-Acting-As'] = $actingAs;
        }

        return $headers;
    }
}
