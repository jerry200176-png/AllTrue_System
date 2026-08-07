<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R99 (in-app #219/#223) root-cause prevention: HTTP surface over the already-tested
 * TeacherUserMergeService so duplicate teacher accounts (the actual root cause of the
 * calendar-visibility bug) can be detected and merged without SSH/artisan access to
 * production.
 */
class TeacherDuplicateControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_reports_teachers_sharing_the_same_display_name(): void
    {
        $token = $this->superAdminToken();
        $campus = CampusFactory::new()->create();

        $keep = User::create([
            'LoginName' => 'dup-keep@test.local', 'Name' => '重複姓名老師',
            'PSW' => 'secret', 'type' => 'T', 'phone' => 911111101, 'status' => 'active',
        ]);
        $dup = User::create([
            'LoginName' => 'dup-merge@test.local', 'Name' => '重複姓名老師',
            'PSW' => 'secret', 'type' => 'T', 'phone' => 911111102, 'status' => 'active',
        ]);
        $lone = User::create([
            'LoginName' => 'lone-teacher@test.local', 'Name' => '唯一老師',
            'PSW' => 'secret', 'type' => 'T', 'phone' => 911111103, 'status' => 'active',
        ]);

        $student = Student::create([
            'name' => '重複偵測測試生', 'CampusID' => $campus->id, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $keep->id, 'by1' => 1, 'Period' => 4,
            'StartDate' => now(), 'TotalHours' => 8, 'Charge' => 0, 'Paid' => 0,
            'Rate' => 500, 'MDate' => now(), 'Stop' => 0,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/admin/teachers/duplicates');

        $res->assertOk();
        $names = collect($res->json('data'))->pluck('name')->all();
        $this->assertContains('重複姓名老師', $names);
        $this->assertNotContains('唯一老師', $names, 'A teacher with no name collision must not be reported');
        $this->assertNotContains('lone', $names);
        unset($lone);

        $group = collect($res->json('data'))->firstWhere('name', '重複姓名老師');
        $accountIds = collect($group['accounts'])->pluck('id')->all();
        $this->assertContains($keep->id, $accountIds);
        $this->assertContains($dup->id, $accountIds);
        $keepAccount = collect($group['accounts'])->firstWhere('id', $keep->id);
        $this->assertGreaterThan(0, $keepAccount['session_count'], 'The account carrying the real course must show a non-zero session count');
    }

    public function test_merge_requires_confirm_flag(): void
    {
        $token = $this->superAdminToken();
        [$keep, $dup] = $this->makeTeacherPair();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/admin/teachers/merge', [
            'keep_id' => $keep->id,
            'merge_id' => $dup->id,
        ]);

        $res->assertStatus(422);
        $this->assertDatabaseHas('User', ['id' => $dup->id, 'status' => 'active']);
    }

    public function test_merge_retargets_courses_and_deactivates_duplicate(): void
    {
        $token = $this->superAdminToken();
        [$keep, $dup, $courseId] = $this->makeTeacherPair(withCourse: true);

        $preview = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/admin/teachers/merge-preview', [
            'keep_id' => $keep->id,
            'merge_id' => $dup->id,
        ]);
        $preview->assertOk();
        $this->assertSame(1, $preview->json('preview.StudentClass'));

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/admin/teachers/merge', [
            'keep_id' => $keep->id,
            'merge_id' => $dup->id,
            'confirm' => true,
        ]);

        $res->assertOk();
        $this->assertDatabaseHas('StudentClass', ['ID' => $courseId, 'TeacherID' => $keep->id]);
        $this->assertDatabaseHas('User', ['id' => $dup->id, 'status' => 'inactive']);
    }

    public function test_merge_rejects_non_teacher_accounts(): void
    {
        $token = $this->superAdminToken();
        $keep = User::create([
            'LoginName' => 'not-a-teacher@test.local', 'Name' => '非老師帳號',
            'PSW' => 'secret', 'type' => 'D', 'phone' => 911111199, 'status' => 'active',
        ]);
        [, $dup] = $this->makeTeacherPair();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/admin/teachers/merge', [
            'keep_id' => $keep->id,
            'merge_id' => $dup->id,
            'confirm' => true,
        ]);

        $res->assertStatus(422);
    }

    /**
     * @return array{0: User, 1: User, 2?: int}
     */
    private function makeTeacherPair(bool $withCourse = false): array
    {
        $keep = User::create([
            'LoginName' => 'pair-keep-' . uniqid() . '@test.local', 'Name' => '配對老師',
            'PSW' => 'secret', 'type' => 'T', 'phone' => 911111201, 'status' => 'active',
        ]);
        $dup = User::create([
            'LoginName' => 'pair-dup-' . uniqid() . '@test.local', 'Name' => '配對老師',
            'PSW' => 'secret', 'type' => 'T', 'phone' => 911111202, 'status' => 'active',
        ]);

        if (!$withCourse) {
            return [$keep, $dup];
        }

        $campus = CampusFactory::new()->create();
        $student = Student::create([
            'name' => '合併端點測試生', 'CampusID' => $campus->id, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $course = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $dup->id, 'by1' => 1, 'Period' => 4,
            'StartDate' => now(), 'TotalHours' => 8, 'Charge' => 0, 'Paid' => 0,
            'Rate' => 500, 'MDate' => now(), 'Stop' => 0,
        ]);

        return [$keep, $dup, $course->ID];
    }

    private function superAdminToken(): string
    {
        $user = User::create([
            'LoginName' => 'dup-super-' . uniqid() . '@test.com',
            'Name' => 'Super',
            'PSW' => 'secret',
            'type' => 'S',
            'phone' => 912000099,
        ]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        return $raw;
    }
}
