<?php

namespace Tests\Feature;

use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuthRegisterMultiBranchesTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_register_with_multi_branches_links_user_campus_and_teacher_branches(): void
    {
        $home = CampusFactory::new()->create();
        $other = CampusFactory::new()->create();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Multi Branch Teacher',
            'email' => 'mbt@example.com',
            'password' => 'secret12',
            'role' => 'teacher',
            'branch_id' => $home->id,
            'multi_branches' => [$other->id],
        ])->assertStatus(201);

        $userId = DB::table('User')->where('LoginName', 'mbt@example.com')->value('id');
        $this->assertNotNull($userId);

        $this->assertDatabaseHas('UserCampus', [
            'UserID' => $userId,
            'CampusID' => $home->id,
        ]);
        $this->assertDatabaseHas('UserCampus', [
            'UserID' => $userId,
            'CampusID' => $other->id,
        ]);

        if (Schema::hasTable('teacher_branches')) {
            $this->assertDatabaseHas('teacher_branches', [
                'teacher_id' => $userId,
                'branch_id' => $home->id,
            ]);
            $this->assertDatabaseHas('teacher_branches', [
                'teacher_id' => $userId,
                'branch_id' => $other->id,
            ]);
        }
    }

    public function test_teacher_register_rejects_unknown_multi_branch(): void
    {
        $home = CampusFactory::new()->create();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Bad Multi',
            'email' => 'badmb@example.com',
            'password' => 'secret12',
            'role' => 'teacher',
            'branch_id' => $home->id,
            'multi_branches' => [999_999],
        ])->assertStatus(422)
            ->assertJsonFragment(['message' => '跨校分校不存在']);
    }

    public function test_director_register_ignores_multi_branches(): void
    {
        $a = CampusFactory::new()->create();
        $b = CampusFactory::new()->create();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Director Only',
            'email' => 'dironly@example.com',
            'password' => 'secret12',
            'role' => 'director',
            'branch_id' => $a->id,
            'multi_branches' => [$b->id],
        ])->assertStatus(201);

        $userId = DB::table('User')->where('LoginName', 'dironly@example.com')->value('id');

        $this->assertDatabaseHas('UserCampus', [
            'UserID' => $userId,
            'CampusID' => $a->id,
        ]);
        $this->assertDatabaseMissing('UserCampus', [
            'UserID' => $userId,
            'CampusID' => $b->id,
        ]);
    }
}
