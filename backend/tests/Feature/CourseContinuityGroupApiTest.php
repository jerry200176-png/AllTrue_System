<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Course Continuity (#1382) — group link API without physical StudentClass merge.
 */
class CourseContinuityGroupApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_group_add_renewal_and_unlink(): void
    {
        $s = $this->seedDirectorCampus(1);
        $student = Student::create([
            'name' => '連延學生', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(),
        ]);
        $original = $this->makeClass($student->id, 1, 10);
        $renewal = $this->makeClass($student->id, 1, 10);

        $create = $this->withAuth($s['token'])->postJson('/api/v1/course-contract-groups', [
            'student_id' => $student->id,
            'campus_id' => 1,
            'subject_id' => 10,
            'label' => '英文延續',
            'members' => [
                ['student_class_id' => $original->ID, 'relation_type' => 'original'],
            ],
        ]);
        $create->assertCreated();
        $groupId = (int) $create->json('data.id');
        $this->assertSame(1, count($create->json('data.members')));

        $add = $this->withAuth($s['token'])->postJson("/api/v1/course-contract-groups/{$groupId}/members", [
            'student_class_id' => $renewal->ID,
            'relation_type' => 'renewal',
            'decision_reason' => '下一期續報',
        ]);
        $add->assertCreated();
        $memberId = (int) $add->json('data.id');

        $list = $this->withAuth($s['token'])->getJson(
            '/api/v1/course-contract-groups?student_id=' . $student->id . '&campus_id=1'
        );
        $list->assertOk();
        $this->assertSame(2, count($list->json('data.0.members')));

        $unlink = $this->withAuth($s['token'])->deleteJson(
            "/api/v1/course-contract-groups/{$groupId}/members/{$memberId}"
        );
        $unlink->assertOk();
        $this->assertNotNull($unlink->json('data.unlinked_at'));

        $this->assertDatabaseHas('StudentClass', ['ID' => $renewal->ID]);
        $list2 = $this->withAuth($s['token'])->getJson(
            '/api/v1/course-contract-groups?student_id=' . $student->id . '&campus_id=1'
        );
        $this->assertSame(1, count($list2->json('data.0.members')));
    }

    public function test_rejects_cross_student_member(): void
    {
        $s = $this->seedDirectorCampus(1);
        $a = Student::create(['name' => 'A', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now()]);
        $b = Student::create(['name' => 'B', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now()]);
        $scA = $this->makeClass($a->id, 1, 10);
        $scB = $this->makeClass($b->id, 1, 10);

        $create = $this->withAuth($s['token'])->postJson('/api/v1/course-contract-groups', [
            'student_id' => $a->id,
            'campus_id' => 1,
            'subject_id' => 10,
            'members' => [
                ['student_class_id' => $scA->ID, 'relation_type' => 'original'],
                ['student_class_id' => $scB->ID, 'relation_type' => 'parallel'],
            ],
        ]);
        $create->assertStatus(422);
        $this->assertStringContainsString('跨學生', json_encode($create->json('errors'), JSON_UNESCAPED_UNICODE));
    }

    public function test_replacement_requires_effective_from(): void
    {
        $s = $this->seedDirectorCampus(1);
        $student = Student::create(['name' => '替換', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now()]);
        $old = $this->makeClass($student->id, 1, 11);
        $neu = $this->makeClass($student->id, 1, 11);

        $create = $this->withAuth($s['token'])->postJson('/api/v1/course-contract-groups', [
            'student_id' => $student->id,
            'campus_id' => 1,
            'subject_id' => 11,
            'members' => [
                ['student_class_id' => $old->ID, 'relation_type' => 'original'],
                ['student_class_id' => $neu->ID, 'relation_type' => 'replacement'],
            ],
        ]);
        $create->assertStatus(422);
    }

    /** @return array{token:string,user:User} */
    private function seedDirectorCampus(int $campusId): array
    {
        $director = User::create([
            'LoginName' => 'dir-cc@example.com', 'Name' => '主任延續', 'PSW' => 'x',
            'type' => 'A', 'phone' => '0900001382', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return ['token' => $token, 'user' => $director];
    }

    private function makeClass(int $studentId, int $campusId, int $subjectId): StudentClass
    {
        $sc = new StudentClass();
        $sc->StudentID = $studentId;
        $sc->GradeID = 1;
        $sc->TeacherID = 1;
        $sc->SubjectID = $subjectId;
        $sc->by1 = $campusId;
        $sc->ClassType = 'one_on_one';
        $sc->StartDate = '2026-07-01';
        $sc->TotalHours = 20;
        $sc->Stop = 0;
        $sc->Paid = 0;
        $sc->Charge = 0;
        $sc->save();

        return $sc->fresh();
    }

    private function withAuth(string $token)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ]);
    }
}
