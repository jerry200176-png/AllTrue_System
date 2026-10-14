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

    /** In-app #400: the binding page's paged list and stat cards. */
    public function test_binding_page_list_and_stats_contract(): void
    {
        $campus = CampusFactory::new()->create();
        $other = CampusFactory::new()->create();
        $director = $this->directorFor($campus);
        $alice = StudentFactory::new()->create(['CampusID' => $campus->id, 'name' => '甲同學', 'enable' => 1]);
        $bob = StudentFactory::new()->create(['CampusID' => $campus->id, 'name' => '乙同學', 'enable' => 1]);
        StudentFactory::new()->create(['CampusID' => $campus->id, 'name' => '丙同學', 'enable' => 1]);
        $this->bindingFor($alice, $campus, 'U' . str_repeat('e', 32));
        $this->bindingFor($bob, $campus, 'U' . str_repeat('f', 32))->update(['verified_at' => null]);
        $this->bindingFor(StudentFactory::new()->create(['CampusID' => $other->id, 'enable' => 1]), $other, 'U' . str_repeat('g', 32));

        $this->getJson('/api/v1/bindings?page=1&per_page=1&campus_id=' . $campus->id, $this->authHeaders($director))
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/v1/bindings?page=9&per_page=1&campus_id=' . $campus->id, $this->authHeaders($director))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.current_page', 2);
        $byName = $this->getJson('/api/v1/bindings?page=1&student_name=' . urlencode('甲'), $this->authHeaders($director))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.student_id', $alice->id)
            ->assertJsonPath('data.0.line_user_id_masked', 'Ueeeeeee…eeee');
        $this->assertArrayNotHasKey('line_user_id', $byName->json('data.0'));
        $this->getJson('/api/v1/bindings?page=1&status=unverified', $this->authHeaders($director))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.student_id', $bob->id);
        $this->getJson('/api/v1/bindings?page=1&student_id=' . $bob->id, $this->authHeaders($director))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.student_id', $bob->id);
        $legacy = $this->getJson('/api/v1/bindings', $this->authHeaders($director))
            ->assertOk()->assertJsonCount(2, 'bindings');
        $this->assertArrayNotHasKey('data', $legacy->json());

        // Bound = verified only; unverified = has bindings, none verified.
        $this->getJson('/api/v1/bindings/stats?campus_id=' . $campus->id, $this->authHeaders($director))
            ->assertOk()
            ->assertJsonPath('bound_count', 1)
            ->assertJsonPath('unverified_bound_count', 1)
            ->assertJsonPath('student_total', 3);
        $this->getJson('/api/v1/bindings/stats?campus_id=' . $other->id, $this->authHeaders($director))
            ->assertForbidden();

        // A transferred student counts at their current campus, whatever the binding's campus_id says.
        \Illuminate\Support\Facades\DB::table('Student')->where('id', $alice->id)->update(['CampusID' => $other->id]);
        $this->getJson('/api/v1/bindings/stats?campus_id=' . $campus->id, $this->authHeaders($director))
            ->assertOk()
            ->assertJsonPath('bound_count', 0)
            ->assertJsonPath('student_total', 2);
        $this->getJson('/api/v1/bindings?page=1&campus_id=' . $campus->id, $this->authHeaders($director))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.student_id', $bob->id);
        // The stale binding (still campus A, student now at B) is not a director row or a bound student at B.
        $directorB = $this->directorFor($other);
        $this->getJson('/api/v1/bindings?page=1&campus_id=' . $other->id, $this->authHeaders($directorB))
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/bindings/stats?campus_id=' . $other->id, $this->authHeaders($directorB))
            ->assertOk()->assertJsonPath('bound_count', 1)->assertJsonPath('student_total', 2);
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
