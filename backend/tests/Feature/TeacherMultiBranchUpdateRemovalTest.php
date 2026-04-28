<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TeacherMultiBranchUpdateRemovalTest extends TestCase
{
    use RefreshDatabase;

    private function issueToken(int $userId): string
    {
        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id'    => $userId,
            'token'      => $token,
            'expires_at' => now()->addDay(),
        ]);
        return $token;
    }

    public function test_removing_a_campus_from_multi_branches_deletes_user_campus_row(): void
    {
        $campus1 = CampusFactory::new()->create();
        $campus2 = CampusFactory::new()->create();
        $campus3 = CampusFactory::new()->create();

        $director = User::create([
            'LoginName' => 'director-mb-remove',
            'Name'      => 'Director',
            'PSW'       => password_hash('secret123', PASSWORD_DEFAULT),
            'type'      => 'A',
            'phone'     => '0900000010',
        ]);
        UserCampus::create(['UserID' => $director->id, 'CampusID' => $campus1->id, 'Admin' => 1, 'Approved' => 1]);

        $teacher = User::create([
            'LoginName' => 'teacher-mb-remove',
            'Name'      => '測試老師',
            'PSW'       => password_hash('secret123', PASSWORD_DEFAULT),
            'type'      => 'T',
            'phone'     => '0900000011',
        ]);
        UserCampus::create(['UserID' => $teacher->id, 'CampusID' => $campus1->id, 'Approved' => 1]);
        UserCampus::create(['UserID' => $teacher->id, 'CampusID' => $campus2->id, 'Approved' => 1]);
        UserCampus::create(['UserID' => $teacher->id, 'CampusID' => $campus3->id, 'Approved' => 1]);

        $token = $this->issueToken($director->id);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->putJson("/api/v1/profiles/{$teacher->id}", [
            'branch_id'      => $campus1->id,
            'multi_branches' => [$campus2->id],
        ])->assertOk();

        $this->assertDatabaseMissing('UserCampus', [
            'UserID'   => $teacher->id,
            'CampusID' => $campus3->id,
        ]);
        $this->assertDatabaseHas('UserCampus', [
            'UserID'   => $teacher->id,
            'CampusID' => $campus1->id,
        ]);
        $this->assertDatabaseHas('UserCampus', [
            'UserID'   => $teacher->id,
            'CampusID' => $campus2->id,
        ]);

        if (Schema::hasTable('teacher_branches')) {
            $this->assertDatabaseMissing('teacher_branches', [
                'teacher_id' => $teacher->id,
                'branch_id'  => $campus3->id,
            ]);
        }
    }

    public function test_removing_all_multi_branches_leaves_only_main_campus(): void
    {
        $campus1 = CampusFactory::new()->create();
        $campus2 = CampusFactory::new()->create();

        $director = User::create([
            'LoginName' => 'director-mb-empty',
            'Name'      => 'Director2',
            'PSW'       => password_hash('secret123', PASSWORD_DEFAULT),
            'type'      => 'A',
            'phone'     => '0900000012',
        ]);
        UserCampus::create(['UserID' => $director->id, 'CampusID' => $campus1->id, 'Admin' => 1, 'Approved' => 1]);

        $teacher = User::create([
            'LoginName' => 'teacher-mb-empty',
            'Name'      => '測試老師2',
            'PSW'       => password_hash('secret123', PASSWORD_DEFAULT),
            'type'      => 'T',
            'phone'     => '0900000013',
        ]);
        UserCampus::create(['UserID' => $teacher->id, 'CampusID' => $campus1->id, 'Approved' => 1]);
        UserCampus::create(['UserID' => $teacher->id, 'CampusID' => $campus2->id, 'Approved' => 1]);

        $token = $this->issueToken($director->id);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->putJson("/api/v1/profiles/{$teacher->id}", [
            'branch_id'      => $campus1->id,
            'multi_branches' => [],
        ])->assertOk();

        $this->assertDatabaseMissing('UserCampus', [
            'UserID'   => $teacher->id,
            'CampusID' => $campus2->id,
        ]);
        $this->assertDatabaseHas('UserCampus', [
            'UserID'   => $teacher->id,
            'CampusID' => $campus1->id,
        ]);
    }
}
