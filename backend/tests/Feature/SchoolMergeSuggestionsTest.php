<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\SchoolNameSuggester;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 學校整理 suggestions (#517). Fake school names only. */
class SchoolMergeSuggestionsTest extends TestCase
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

    private function student(int $campus, string $school): void
    {
        Student::create(['name' => 'S', 'CampusID' => $campus, 'ClassID' => 8, 'SchoolName' => $school, 'enable' => 1, 'MDT' => now()]);
    }

    public function test_key_collapses_width_and_grade_suffix(): void
    {
        $s = app(SchoolNameSuggester::class);
        $this->assertSame($s->key('景文高中'), $s->key('景文高一'));
        $this->assertSame($s->key('甲測國中'), $s->key(' 甲測國二 '));
        $this->assertNotSame($s->key('甲測國中'), $s->key('甲測國小'));
    }

    public function test_groups_are_suggested_per_campus_and_director_only(): void
    {
        $a = (int) CampusFactory::new()->create()->id;
        $b = (int) CampusFactory::new()->create()->id;
        foreach (['再興國中', '再興國中', '在興國中', '景文高中', '景文高一', '乙測國中'] as $n) {
            $this->student($a, $n);
        }
        $this->student($b, '在興國中');
        $dir = $this->staff('A', $a);
        $teacher = $this->staff('T', $a);

        $groups = $this->as($dir)->getJson("/api/v1/school-merge/suggestions?campus_id=$a")->assertOk()->json('data');
        $this->assertCount(2, $groups);
        $this->assertSame(3, $groups[0]['student_count']);
        $this->assertSame('similar', $groups[0]['reason']);
        $this->assertSame('same_key', $groups[1]['reason']);

        $this->as($teacher)->getJson("/api/v1/school-merge/suggestions?campus_id=$a")->assertForbidden();
        $this->as($dir)->getJson("/api/v1/school-merge/suggestions?campus_id=$b")->assertForbidden();
        $this->as($teacher)->getJson("/api/v1/school-names?campus_id=$a")->assertOk()->assertJsonCount(5, 'data');
        $this->as($teacher)->getJson("/api/v1/school-names?campus_id=$b")->assertForbidden();
    }
}
