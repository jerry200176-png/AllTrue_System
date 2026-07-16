<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminDuplicateSessionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_p2_review_is_scoped_to_director_campus(): void
    {
        $ownCampus = Campus::factory()->create();
        $otherCampus = Campus::factory()->create();
        $token = $this->directorToken($ownCampus->id);

        $this->seedDuplicatePair(studentId: 91001, campusId: $ownCampus->id);
        $this->seedDuplicatePair(studentId: 91002, campusId: $otherCampus->id);

        $res = $this->withToken($token)->getJson('/api/v1/admin/duplicate-sessions/p2-review');
        $res->assertOk();

        $groups = $res->json('data.groups');
        $this->assertCount(1, $groups);
        $this->assertSame(91001, $groups[0]['student_id']);
        $this->assertSame('學生91001', $groups[0]['student_name']);
        $this->assertNotEmpty($groups[0]['id']);
    }

    public function test_patch_p2_review_cancels_non_kept_side_and_returns_kept_session_ids(): void
    {
        $campus = Campus::factory()->create();
        $token = $this->directorToken($campus->id);

        [$studentId, $keepSc, , $keepCs, $dropCs] = $this->seedDuplicatePair(studentId: 91011, campusId: $campus->id);
        $groupId = rtrim(strtr(base64_encode($studentId . ':2026-06-10:17:00'), '+/', '-_'), '=');

        $res = $this->withToken($token)->patchJson("/api/v1/admin/duplicate-sessions/p2-review/{$groupId}", [
            'keep_student_class_id' => $keepSc,
            'reason' => '保留有效課程',
        ]);

        $res->assertOk()
            ->assertJsonPath('data.cancelled_count', 1)
            ->assertJsonPath('data.kept_session_ids.0', $keepCs);

        $this->assertSame('attended', DB::table('ClassSession')->where('id', $keepCs)->value('Status'));
        $this->assertSame('cancelled', DB::table('ClassSession')->where('id', $dropCs)->value('Status'));
    }

    public function test_patch_p2_review_rejects_keep_student_class_id_outside_group_without_partial_write(): void
    {
        $campus = Campus::factory()->create();
        $token = $this->directorToken($campus->id);

        [$studentId, , , $keepCs, $dropCs] = $this->seedDuplicatePair(studentId: 91021, campusId: $campus->id);
        $outsiderSc = $this->createCourse(91022, $campus->id, $this->ensureTeacher(), $this->ensureSubject());
        $groupId = rtrim(strtr(base64_encode($studentId . ':2026-06-10:17:00'), '+/', '-_'), '=');

        $res = $this->withToken($token)->patchJson("/api/v1/admin/duplicate-sessions/p2-review/{$groupId}", [
            'keep_student_class_id' => $outsiderSc,
            'reason' => '錯誤資料',
        ]);

        $res->assertStatus(422);
        $this->assertSame('attended', DB::table('ClassSession')->where('id', $keepCs)->value('Status'));
        $this->assertSame('completed', DB::table('ClassSession')->where('id', $dropCs)->value('Status'));
    }

    public function test_legacy_post_decide_and_execute_routes_are_removed(): void
    {
        $campus = Campus::factory()->create();
        $token = $this->directorToken($campus->id);

        $this->withToken($token)->postJson('/api/v1/admin/duplicate-sessions/decide', [])->assertStatus(404);
        $this->withToken($token)->postJson('/api/v1/admin/duplicate-sessions/execute', [])->assertStatus(404);
    }

    private function directorToken(int $campusId): string
    {
        $user = User::create([
            'LoginName' => 'dup-dir-' . uniqid() . '@test.com',
            'Name' => 'Duplicate Director',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => 912000111,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);
        return $raw;
    }

    /**
     * @return array{int,int,int,int,int}
     */
    private function seedDuplicatePair(int $studentId, int $campusId): array
    {
        $teacherId = $this->ensureTeacher();
        $subjectId = $this->ensureSubject();
        $keepSc = $this->createCourse($studentId, $campusId, $teacherId, $subjectId, 8);
        $dropSc = $this->createCourse($studentId, $campusId, $teacherId, $subjectId, 4);
        $keepCs = $this->createSession($keepSc, 'attended');
        $dropCs = $this->createSession($dropSc, 'completed');
        return [$studentId, $keepSc, $dropSc, $keepCs, $dropCs];
    }

    private function ensureStudent(int $studentId, int $campusId): void
    {
        if (!DB::table('Student')->where('id', $studentId)->exists()) {
            DB::table('Student')->insert([
                'id' => $studentId,
                'name' => '學生' . $studentId,
                'CampusID' => $campusId,
                'ClassID' => 1,
                'enable' => 1,
            ]);
        }
    }

    private function ensureTeacher(): int
    {
        $id = 81101;
        if (!DB::table('User')->where('ID', $id)->exists()) {
            DB::table('User')->insert([
                'ID' => $id,
                'LoginName' => 'dup-teacher@test.com',
                'Name' => '王老師',
                'PSW' => 'secret',
                'type' => 'T',
                'phone' => '0912000222',
            ]);
        }
        return $id;
    }

    private function ensureSubject(): int
    {
        $id = 81101;
        if (!DB::table('Subject')->where('id', $id)->exists()) {
            DB::table('Subject')->insert([
                'id' => $id,
                'School_id' => 1,
                'Grade_no' => 1,
                'Subject_Name' => '數學',
                'CampusID' => null,
            ]);
        }
        return $id;
    }

    private function createCourse(int $studentId, int $campusId, int $teacherId, int $subjectId, int $sessionCount = 8): int
    {
        $this->ensureStudent($studentId, $campusId);

        return (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => $subjectId,
            'TeacherID' => $teacherId,
            'by1' => 1,
            'Period' => 4,
            'TotalHours' => 0,
            'Charge' => 0,
            'Pay' => 0,
            'Paid' => 0,
            'Rate' => 0,
            'ClassType' => 'one_on_two',
            'StartDate' => now()->subDays(30)->toDateTimeString(),
            'SessionCount' => $sessionCount,
            'SessionDuration' => 60,
            'RemainingSessions' => max(0, $sessionCount - 1),
            'UsedSessions' => $sessionCount > 0 ? 1 : 0,
            'Stop' => 0,
            'ScheduleMode' => 'count',
        ]);
    }

    private function createSession(int $scId, string $status): int
    {
        return (int) DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $scId,
            'SessionDate' => '2026-06-10',
            'StartTime' => '17:00:00',
            'EndTime' => '18:00:00',
            'Status' => $status,
            'Note' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
