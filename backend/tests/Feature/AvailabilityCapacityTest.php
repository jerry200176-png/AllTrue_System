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
 * FR-005 テスト：GET /api/v1/teachers/{id}/availability の容量情報レスポンス
 *
 * Case 1: one_on_two 課程に 1 学生 → remaining_capacity = 1
 * Case 2: one_on_one 課程に 1 学生 → remaining_capacity = 0
 * Case 3: 授業なし               → busy_slots = []
 */
class AvailabilityCapacityTest extends TestCase
{
    use RefreshDatabase;

    private string $dirToken;
    private int $campusId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $director = User::create([
            'LoginName' => 'dir-avail@example.com',
            'Name' => '主任可用性測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0911000001',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $this->campusId, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        $this->dirToken = $token;
    }

    /**
     * Case 1: one_on_two 課程に 1 学生 → remaining_capacity = 1（まだ余裕あり）
     * FR-006 のフロントエンドが「尚有容量 ⚠」と表示するための基盤。
     */
    public function test_one_on_two_with_one_student_has_remaining_capacity_1(): void
    {
        $teacher = $this->createTeacher('teacher-avail1@example.com');
        $student = $this->createStudent('容量テスト学生1');

        $sc = $this->createStudentClass($student->id, $teacher->id, 'one_on_two');

        ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate'    => '2026-05-15',
            'StartTime'      => '14:00:00',
            'EndTime'        => '16:00:00',
            'Status'         => 'scheduled',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->dirToken}",
            'Accept'        => 'application/json',
        ])->getJson("/api/v1/teachers/{$teacher->id}/availability?date=2026-05-15");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'teacher_id',
            'date',
            'busy_slots' => [
                '*' => ['start_time', 'end_time', 'campus_id', 'class_type', 'remaining_capacity'],
            ],
        ]);

        $busySlots = $response->json('busy_slots');
        $this->assertNotEmpty($busySlots, 'busy_slots should contain at least one slot');

        // Find the slot that overlaps with 14:00-16:00
        $overlappingSlot = collect($busySlots)->first(fn ($s) =>
            $s['start_time'] === '14:00' && $s['end_time'] === '16:00'
        );
        $this->assertNotNull($overlappingSlot, 'Should have a slot at 14:00-16:00');
        $this->assertEquals('one_on_two', $overlappingSlot['class_type']);
        $this->assertEquals(1, $overlappingSlot['remaining_capacity'],
            'one_on_two with 1 student should have remaining_capacity = 1'
        );
    }

    /**
     * Case 2: one_on_one 課程に 1 学生 → remaining_capacity = 0（満員）
     * FR-006 のフロントエンドが「已滿 ✗」と表示し選択不可にするための基盤。
     */
    public function test_one_on_one_with_one_student_has_remaining_capacity_0(): void
    {
        $teacher = $this->createTeacher('teacher-avail2@example.com');
        $student = $this->createStudent('容量テスト学生2');

        $sc = $this->createStudentClass($student->id, $teacher->id, 'one_on_one');

        ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate'    => '2026-05-16',
            'StartTime'      => '10:00:00',
            'EndTime'        => '12:00:00',
            'Status'         => 'scheduled',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->dirToken}",
            'Accept'        => 'application/json',
        ])->getJson("/api/v1/teachers/{$teacher->id}/availability?date=2026-05-16");

        $response->assertStatus(200);

        $busySlots = $response->json('busy_slots');
        $this->assertNotEmpty($busySlots);

        $slot = collect($busySlots)->first(fn ($s) =>
            $s['start_time'] === '10:00' && $s['end_time'] === '12:00'
        );
        $this->assertNotNull($slot, 'Should have a slot at 10:00-12:00');
        $this->assertEquals('one_on_one', $slot['class_type']);
        $this->assertEquals(0, $slot['remaining_capacity'],
            'one_on_one with 1 student should have remaining_capacity = 0'
        );
    }

    /**
     * Case 3: 授業なし → busy_slots = []
     * FR-006 のフロントエンドが「有空 ✓」と表示するための基盤。
     */
    public function test_no_sessions_returns_empty_busy_slots(): void
    {
        $teacher = $this->createTeacher('teacher-avail3@example.com');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->dirToken}",
            'Accept'        => 'application/json',
        ])->getJson("/api/v1/teachers/{$teacher->id}/availability?date=2026-05-17");

        $response->assertStatus(200);
        $response->assertJson([
            'busy_slots' => [],
        ]);
    }

    private function createTeacher(string $loginName): User
    {
        $teacher = User::create([
            'LoginName' => $loginName,
            'Name' => '老師' . substr($loginName, 0, 5),
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '09' . substr(md5($loginName), 0, 8),
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $this->campusId, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        return $teacher;
    }

    private function createStudent(string $name): Student
    {
        return Student::create([
            'name' => $name,
            'CampusID' => $this->campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    private function createStudentClass(int $studentId, int $teacherId, string $classType): StudentClass
    {
        return StudentClass::create([
            'StudentID'        => $studentId,
            'GradeID'          => 1,
            'SubjectID'        => 1,
            'TeacherID'        => $teacherId,
            'ClassType'        => $classType,
            'by1'              => 1,
            'Period'           => 4,
            'StartDate'        => '2026-04-01',
            'TotalHours'       => 8,
            'SessionCount'     => 4,
            'SessionDuration'  => 120,
            'RemainingSessions'=> 4,
            'UsedSessions'     => 0,
            'Charge'           => 800,
            'Pay'              => 3200,
            'Paid'             => 0,
            'Rate'             => 800,
            'Stop'             => 0,
            'MDate'            => now(),
            'ScheduleMode'     => 'count',
        ]);
    }
}
