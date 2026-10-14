<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\Student;
use App\Models\StudentLineBinding;
use App\Models\User;
use Database\Factories\CampusFactory;
use Database\Factories\StudentFactory;
use Database\Factories\UserCampusFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BindingManagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_binding_management_returns_401(): void
    {
        $this->getJson('/api/v1/bindings')->assertUnauthorized();
    }

    public function test_teacher_cannot_access_binding_management(): void
    {
        $campus = CampusFactory::new()->create();
        $teacher = UserFactory::new()->teacher()->create();
        UserCampusFactory::new()->create([
            'UserID' => $teacher->id,
            'CampusID' => $campus->id,
            'Approved' => true,
        ]);

        $this->getJson('/api/v1/bindings', $this->authHeaders($teacher))
            ->assertForbidden();
    }

    public function test_director_is_campus_scoped_for_binding_management(): void
    {
        $campusA = CampusFactory::new()->create();
        $campusB = CampusFactory::new()->create();
        $director = $this->directorFor($campusA);
        $studentA = StudentFactory::new()->create(['CampusID' => $campusA->id]);
        $studentB = StudentFactory::new()->create(['CampusID' => $campusB->id]);
        $bindingA = $this->bindingFor($studentA, $campusA, 'U' . str_repeat('a', 32));
        $bindingB = $this->bindingFor($studentB, $campusB, 'U' . str_repeat('b', 32));

        $this->getJson('/api/v1/bindings', $this->authHeaders($director))
            ->assertOk()
            ->assertJsonCount(1, 'bindings')
            ->assertJsonPath('bindings.0.id', $bindingA->id);

        $this->getJson('/api/v1/bindings?campus_id=' . $campusB->id, $this->authHeaders($director))
            ->assertForbidden();
        $this->getJson('/api/v1/bindings/' . $bindingB->id, $this->authHeaders($director))
            ->assertForbidden();
        $this->getJson('/api/v1/bindings/metrics?campus_id=' . $campusB->id, $this->authHeaders($director))
            ->assertForbidden();
        $this->getJson('/api/v1/bindings/metrics', $this->authHeaders($director))
            ->assertOk()
            ->assertJsonPath('total_bindings', 1)
            ->assertJsonPath('active_bindings', 1);
        $this->postJson('/api/v1/bindings', [
            'student_id' => $studentB->id,
            'line_user_id' => 'U' . str_repeat('c', 32),
            'campus_id' => $campusB->id,
        ], $this->authHeaders($director))->assertForbidden();
        $this->deleteJson('/api/v1/bindings/' . $bindingB->id, [], $this->authHeaders($director))
            ->assertForbidden();

        $this->assertDatabaseHas('student_line_bindings', ['id' => $bindingB->id]);
    }

    public function test_authorized_director_can_create_binding_in_own_campus(): void
    {
        $campus = CampusFactory::new()->create();
        $director = $this->directorFor($campus);
        $student = StudentFactory::new()->create(['CampusID' => $campus->id]);
        $lineUserId = 'U' . str_repeat('d', 32);

        $this->postJson('/api/v1/bindings', [
            'student_id' => $student->id,
            'line_user_id' => $lineUserId,
            'campus_id' => $campus->id,
        ], $this->authHeaders($director))
            ->assertCreated()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('student_line_bindings', [
            'student_id' => $student->id,
            'line_user_id' => $lineUserId,
            'campus_id' => $campus->id,
        ]);
    }

    public function test_authorized_bearer_token_uses_existing_auth_token_pipeline(): void
    {
        $campus = CampusFactory::new()->create();
        $director = $this->directorFor($campus);
        $token = 'binding-test-' . str_repeat('f', 24);

        AuthToken::create([
            'user_id' => $director->id,
            'token' => $token,
            'expires_at' => now()->addHour(),
        ]);

        $this->getJson('/api/v1/bindings', [
            'Authorization' => 'Bearer ' . $token,
        ])->assertOk();
    }

    public function test_super_admin_can_manage_bindings_across_campuses(): void
    {
        $campus = CampusFactory::new()->create();
        $superAdmin = UserFactory::new()->superAdmin()->create();
        $student = StudentFactory::new()->create(['CampusID' => $campus->id]);
        $lineUserId = 'U' . str_repeat('e', 32);

        $this->postJson('/api/v1/bindings', [
            'student_id' => $student->id,
            'line_user_id' => $lineUserId,
            'campus_id' => $campus->id,
        ], $this->authHeaders($superAdmin))
            ->assertCreated()
            ->assertJsonPath('status', 'success');

        $this->getJson('/api/v1/bindings?campus_id=' . $campus->id, $this->authHeaders($superAdmin))
            ->assertOk()
            ->assertJsonFragment(['line_user_id' => $lineUserId]);
    }

    private function directorFor(Campus $campus): User
    {
        $director = UserFactory::new()->director()->create();
        UserCampusFactory::new()->create([
            'UserID' => $director->id,
            'CampusID' => $campus->id,
            'Approved' => true,
        ]);

        return $director;
    }

    private function bindingFor(Student $student, Campus $campus, string $lineUserId): StudentLineBinding
    {
        return StudentLineBinding::create([
            'student_id' => $student->id,
            'line_user_id' => $lineUserId,
            'campus_id' => $campus->id,
            'bound_at' => now(),
            'verified_at' => now(),
            'verification_method' => 'contact_phone',
        ]);
    }

    /** @return array<string, string> */
    private function authHeaders(User $user): array
    {
        return ['X-User-Id' => (string) $user->id];
    }
}
