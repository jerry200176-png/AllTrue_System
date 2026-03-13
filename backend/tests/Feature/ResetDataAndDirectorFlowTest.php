<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Factories\CampusFactory;
use Database\Factories\StudentFactory;
use Database\Factories\UserCampusFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResetDataAndDirectorFlowTest extends TestCase
{
    use RefreshDatabase;

    protected $connectionsToTransact = [];

    /**
     * @test
     */
    public function it_resets_data_for_super_admin(): void
    {
        $campus = CampusFactory::new()->create();
        $superAdmin = UserFactory::new()->superAdmin()->create();
        $director = UserFactory::new()->director()->create();

        UserCampusFactory::new()->create([
            'UserID' => $director->id,
            'CampusID' => $campus->id,
            'Admin' => 1,
            'Approved' => true,
        ]);

        $student = StudentFactory::new()->create([
            'CampusID' => $campus->id,
        ]);

        $this->assertDatabaseHas('User', ['id' => $superAdmin->id, 'type' => 'S']);
        $this->assertDatabaseHas('User', ['id' => $director->id, 'type' => 'D']);
        $this->assertDatabaseHas('Student', ['id' => $student->id]);

        $response = $this->postJson('/api/v1/admin/reset-data', [], [
            'X-User-Id' => (string) $superAdmin->id,
        ]);

        $response->assertOk()->assertJsonStructure(['message', 'cleared']);

        $this->assertDatabaseHas('User', ['id' => $superAdmin->id, 'type' => 'S']);
        $this->assertDatabaseMissing('User', ['id' => $director->id]);
        $this->assertDatabaseMissing('Student', ['id' => $student->id]);
        $this->assertDatabaseMissing('UserCampus', ['UserID' => $director->id, 'CampusID' => $campus->id]);
    }

    /**
     * @test
     */
    public function it_registers_new_director_as_pending(): void
    {
        $campus = CampusFactory::new()->create();

        $payload = [
            'name' => 'Pending Director',
            'email' => 'pending.director@example.com',
            'password' => 'secret1234',
            'campus_id' => $campus->id,
        ];

        $response = $this->postJson('/api/v1/directors/register', $payload);

        $response->assertStatus(201);

        $director = User::where('LoginName', $payload['email'])->first();
        $this->assertNotNull($director);

        $this->assertDatabaseHas('User', [
            'id' => $director->id,
            'LoginName' => $payload['email'],
            'Name' => $payload['name'],
            'type' => 'U',
        ]);

        $this->assertDatabaseHas('UserCampus', [
            'UserID' => $director->id,
            'CampusID' => $campus->id,
            'Admin' => 0,
            'Approved' => 0,
        ]);
    }

    /**
     * @test
     */
    public function it_approves_director_application(): void
    {
        $campus = CampusFactory::new()->create();
        $superAdmin = UserFactory::new()->superAdmin()->create();
        $pendingDirector = UserFactory::new()->pendingDirector()->create([
            'LoginName' => 'approve.me@example.com',
        ]);

        UserCampusFactory::new()->create([
            'UserID' => $pendingDirector->id,
            'CampusID' => $campus->id,
            'Admin' => 0,
            'Approved' => false,
        ]);

        $response = $this->postJson("/api/v1/directors/{$pendingDirector->id}/approve", [], [
            'X-User-Id' => (string) $superAdmin->id,
        ]);

        $response->assertOk()->assertJsonFragment(['message' => '已通過審核']);

        $this->assertDatabaseHas('User', [
            'id' => $pendingDirector->id,
            'type' => 'D',
        ]);

        $this->assertDatabaseHas('UserCampus', [
            'UserID' => $pendingDirector->id,
            'CampusID' => $campus->id,
            'Approved' => 1,
        ]);
    }

    /**
     * @test
     */
    public function it_allows_director_to_create_student_and_list_it(): void
    {
        $campus = CampusFactory::new()->create();
        $director = UserFactory::new()->director()->create();

        UserCampusFactory::new()->create([
            'UserID' => $director->id,
            'CampusID' => $campus->id,
            'Admin' => 1,
            'Approved' => true,
        ]);

        $createPayload = [
            'name' => 'Test Student',
            'grade' => 'J1',
            'school' => 'Test Junior High',
            'phone' => '0911222333',
            'branch_id' => $campus->id,
        ];

        $createResponse = $this->postJson('/api/v1/students', $createPayload, [
            'X-User-Id' => (string) $director->id,
        ]);

        $createResponse
            ->assertStatus(201)
            ->assertJsonFragment([
                'name' => 'Test Student',
                'branch_id' => $campus->id,
            ]);

        $this->assertDatabaseHas('Student', [
            'name' => 'Test Student',
            'CampusID' => $campus->id,
        ]);

        $listResponse = $this->getJson('/api/v1/students?per_page=all', [
            'X-User-Id' => (string) $director->id,
        ]);

        $listResponse
            ->assertOk()
            ->assertJsonFragment([
                'name' => 'Test Student',
                'branch_id' => $campus->id,
            ]);
    }
}
