<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bug #496 / in-app #124：cancelAutoMaterializedDuplicateSession() 寫入的
 * 「cancelled-duplicate-reschedule-placeholder」屬內部 bookkeeping，
 * 預設不應外露給課程管理等 UI；提供 include_internal_placeholder=1 給 audit 用。
 */
class ClassSessionPlaceholderHideTest extends TestCase
{
    use RefreshDatabase;

    public function test_placeholder_hidden_by_default(): void
    {
        [$token, $courseId, $placeholderId, $normalCancelledId, $attendedId] = $this->seedScenario();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/class-sessions?branch_id=1&student_class_id={$courseId}&per_page=100");

        $res->assertOk();
        $ids = array_map(fn ($r) => (int) ($r['id'] ?? 0), $res->json('data') ?? []);

        $this->assertNotContains($placeholderId, $ids, 'placeholder should be hidden by default');
        $this->assertContains($attendedId, $ids, 'attended row must remain visible');
        $this->assertContains($normalCancelledId, $ids, 'non-placeholder cancelled row must remain visible');
    }

    public function test_non_placeholder_cancelled_still_visible(): void
    {
        [$token, $courseId, , $normalCancelledId] = $this->seedScenario();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/class-sessions?branch_id=1&student_class_id={$courseId}&per_page=100");

        $res->assertOk();
        $hit = collect($res->json('data'))->firstWhere('id', $normalCancelledId);
        $this->assertNotNull($hit, 'manually cancelled rows should still be visible');
        $this->assertSame('cancelled', strtolower((string) ($hit['status'] ?? '')));
    }

    public function test_placeholder_visible_when_opt_in(): void
    {
        [$token, $courseId, $placeholderId] = $this->seedScenario();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/class-sessions?branch_id=1&student_class_id={$courseId}&per_page=100&include_internal_placeholder=1");

        $res->assertOk();
        $ids = array_map(fn ($r) => (int) ($r['id'] ?? 0), $res->json('data') ?? []);
        $this->assertContains($placeholderId, $ids, 'opt-in must surface placeholder for audit');
    }

    /**
     * @return array{0:string,1:int,2:int,3:int,4:int}
     */
    private function seedScenario(): array
    {
        static $seq = 0;
        $seq++;

        $token = $this->createDirectorToken([1], 'director-placeholder-' . $seq . '@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-placeholder-' . $seq . '@example.com');
        $student = $this->createStudent(1, '幽靈堂-' . $seq);

        $course = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => $teacherId,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-03-01',
            'EndDate' => null,
            'TotalHours' => 40,
            'Memo' => '#496 測試',
            'Paid' => 0,
            'Rate' => 500,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'RemainingSessions' => 8,
            'UsedSessions' => 0,
            'SessionDuration' => 120,
            'ClassType' => 'one_on_one',
        ]);
        $courseId = (int) $course->ID;

        $date = now()->subDay()->toDateString();

        $attended = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => $date,
            'StartTime' => '17:00',
            'EndTime' => '19:00',
            'Status' => 'attended',
            'Note' => '',
        ]);

        $placeholder = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => $date,
            'StartTime' => '17:00',
            'EndTime' => '19:00',
            'Status' => 'cancelled',
            'Note' => 'auto-materialized-from-schedule; cancelled-duplicate-reschedule-placeholder',
        ]);

        $normalCancelled = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => now()->subDays(2)->toDateString(),
            'StartTime' => '10:00',
            'EndTime' => '12:00',
            'Status' => 'cancelled',
            'Note' => '主任手動取消',
        ]);

        return [$token, $courseId, (int) $placeholder->id, (int) $normalCancelled->id, (int) $attended->id];
    }

    private function createDirectorToken(array $campusIds, string $loginName): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
            'MustChangePassword' => false,
        ]);
        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID' => $user->id,
                'Admin' => 1,
                'Approved' => 1,
            ]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }

    private function createTeacher(int $campusId, string $loginName): int
    {
        $teacher = User::create([
            'LoginName' => $loginName,
            'Name' => '老師測試',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0922000000',
            'MustChangePassword' => false,
        ]);
        UserCampus::create([
            'CampusID' => $campusId,
            'UserID' => $teacher->id,
            'Admin' => 0,
            'Approved' => 1,
        ]);
        return (int) $teacher->id;
    }

    private function createStudent(int $campusId, string $name): Student
    {
        return Student::create([
            'name' => $name,
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }
}
