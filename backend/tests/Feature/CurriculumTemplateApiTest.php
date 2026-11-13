<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 教材範本庫 API (#4970 step 2). Fake titles only. */
class CurriculumTemplateApiTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $type, int $campus): string
    {
        $u = User::create([
            'LoginName' => $type . random_int(1, 999999) . '@example.com', 'Name' => 'Staff',
            'PSW' => 'secret', 'type' => $type, 'phone' => (string) random_int(900000000, 999999999),
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campus, 'UserID' => $u->id, 'Admin' => $type === 'A' ? 1 : 0, 'Approved' => 1]);
        $t = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $u->id, 'token' => $t, 'expires_at' => now()->addDay()]);

        return $t;
    }

    private function as(string $t)
    {
        return $this->withHeaders(['Authorization' => "Bearer $t", 'Accept' => 'application/json']);
    }

    private function payload(int $campus, array $o = []): array
    {
        return $o + ['campus_id' => $campus, 'subject' => 'Math', 'grade' => 'J1', 'publisher' => '測試出版社',
            'academic_year' => 115, 'semester' => 1, 'title' => '七上數學範本'];
    }

    public function test_director_crud_teacher_read_only_and_campus_isolation(): void
    {
        $a = (int) CampusFactory::new()->create()->id;
        $b = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $a);
        $teacher = $this->staff('T', $a);
        $otherDir = $this->staff('A', $b);

        $id = $this->as($dir)->postJson('/api/v1/curriculum-templates', $this->payload($a))
            ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

        $this->as($teacher)->postJson('/api/v1/curriculum-templates', $this->payload($a))->assertForbidden();
        $this->as($teacher)->patchJson("/api/v1/curriculum-templates/$id", ['title' => 'x'])->assertForbidden();
        $this->as($teacher)->putJson("/api/v1/curriculum-templates/$id/units", ['units' => []])->assertForbidden();
        $this->as($teacher)->postJson("/api/v1/curriculum-templates/$id/archive")->assertForbidden();
        $this->as($teacher)->getJson("/api/v1/curriculum-templates?campus_id=$a")->assertOk()->assertJsonCount(1, 'data');
        $this->as($teacher)->getJson("/api/v1/curriculum-templates/$id")->assertOk();

        $this->as($otherDir)->getJson("/api/v1/curriculum-templates?campus_id=$a")->assertForbidden();
        $this->as($otherDir)->getJson("/api/v1/curriculum-templates/$id")->assertForbidden();
        $this->as($otherDir)->postJson('/api/v1/curriculum-templates', $this->payload($a))->assertForbidden();
        $this->as($otherDir)->patchJson("/api/v1/curriculum-templates/$id", ['title' => 'x'])->assertForbidden();
        $this->as($otherDir)->putJson("/api/v1/curriculum-templates/$id/units", ['units' => []])->assertForbidden();
        $this->as($otherDir)->postJson("/api/v1/curriculum-templates/$id/archive")->assertForbidden();
        $this->as($otherDir)->getJson("/api/v1/curriculum-templates?campus_id=$b")->assertOk()->assertJsonCount(0, 'data');

        $this->as($dir)->patchJson("/api/v1/curriculum-templates/$id", ['title' => '改名', 'status' => 'active'])
            ->assertOk()->assertJsonPath('data.title', '改名')->assertJsonPath('data.status', 'active');
        $this->as($dir)->patchJson("/api/v1/curriculum-templates/$id", ['campus_id' => $b])->assertStatus(422);
        $this->assertDatabaseHas('curriculum_templates', ['id' => $id, 'campus_id' => $a]);
    }

    public function test_validation(): void
    {
        $a = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $a);
        $this->as($dir)->postJson('/api/v1/curriculum-templates', $this->payload($a, ['title' => '']))->assertStatus(422);
        $this->as($dir)->postJson('/api/v1/curriculum-templates', $this->payload($a, ['grade' => 'Z9']))->assertStatus(422);
        $this->as($dir)->postJson('/api/v1/curriculum-templates', $this->payload($a, ['semester' => 3]))->assertStatus(422);
        $this->as($dir)->postJson('/api/v1/curriculum-templates', $this->payload($a, ['status' => 'bogus']))->assertStatus(422);

        $id = $this->as($dir)->postJson('/api/v1/curriculum-templates', $this->payload($a))->json('data.id');
        $url = "/api/v1/curriculum-templates/$id/units";
        $this->as($dir)->putJson($url, ['units' => [['title' => '  ']]])->assertStatus(422);
        $this->as($dir)->putJson($url, ['units' => [['title' => '']]])->assertStatus(422);
        $this->as($dir)->putJson($url, ['units' => [['title' => '單元', 'exam_sequence' => 0]]])->assertStatus(422);
        $this->as($dir)->putJson($url, ['units' => [['title' => '單元', 'exam_sequence' => 4]]])->assertStatus(422);
        $this->as($dir)->putJson($url, [])->assertStatus(422);
    }

    public function test_replace_units_orders_by_array_and_is_atomic(): void
    {
        $a = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $a);
        $id = $this->as($dir)->postJson('/api/v1/curriculum-templates', $this->payload($a))->json('data.id');
        $url = "/api/v1/curriculum-templates/$id/units";

        $this->as($dir)->putJson($url, ['units' => [
            ['title' => '第一章', 'exam_sequence' => 1],
            ['title' => '第二章', 'note' => ' 含習作 ', 'exam_sequence' => 2],
            ['title' => '第三章'],
        ]])->assertOk()->assertJsonCount(3, 'data.units')
            ->assertJsonPath('data.units.1.note', '含習作')->assertJsonPath('data.units.2.exam_sequence', null);

        // A bad row in the new list must leave the previous units untouched.
        $this->as($dir)->putJson($url, ['units' => [['title' => '新的'], ['title' => '']]])->assertStatus(422);
        $this->as($dir)->getJson("/api/v1/curriculum-templates/$id")
            ->assertJsonPath('data.units.0.title', '第一章')->assertJsonCount(3, 'data.units');

        // Reorder + shrink: sort_order follows array order.
        $res = $this->as($dir)->putJson($url, ['units' => [['title' => '第三章'], ['title' => '第一章']]])->assertOk();
        $this->assertSame(['第三章', '第一章'], array_column($res->json('data.units'), 'title'));
        $this->assertSame([1, 2], array_column($res->json('data.units'), 'sort_order'));
        $this->assertDatabaseCount('curriculum_template_units', 2);
    }

    public function test_archive_hides_from_default_list_and_keeps_rows(): void
    {
        $a = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $a);
        $keep = $this->as($dir)->postJson('/api/v1/curriculum-templates', $this->payload($a, ['subject' => 'English']))->json('data.id');
        $id = $this->as($dir)->postJson('/api/v1/curriculum-templates', $this->payload($a))->json('data.id');
        $this->as($dir)->putJson("/api/v1/curriculum-templates/$id/units", ['units' => [['title' => '第一章']]])->assertOk();

        $this->as($dir)->postJson("/api/v1/curriculum-templates/$id/archive")->assertOk()->assertJsonPath('data.status', 'archived');

        $this->as($dir)->getJson("/api/v1/curriculum-templates?campus_id=$a")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $keep);
        $this->as($dir)->getJson("/api/v1/curriculum-templates?campus_id=$a&status=archived")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->as($dir)->getJson("/api/v1/curriculum-templates?campus_id=$a&subject=English")->assertJsonCount(1, 'data');
        $this->as($dir)->getJson("/api/v1/curriculum-templates?campus_id=$a&grade=H1")->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('curriculum_template_units', ['template_id' => $id]);
    }
}
