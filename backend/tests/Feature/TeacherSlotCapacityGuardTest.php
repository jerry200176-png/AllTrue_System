<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\ScheduleGuardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeacherSlotCapacityGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-07 10:00:00', 'Asia/Taipei'));

        // Ensure Subject 1 exists
        if (!DB::table('Subject')->where('id', 1)->exists()) {
            DB::table('Subject')->insert([
                'id' => 1,
                'School_id' => 1,
                'Grade_no' => 1,
                'Subject_Name' => '數學',
                'CampusID' => 1,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_enrollment_blocks_when_teacher_slot_already_has_one_on_one_course(): void
    {
        $token = $this->createDirectorToken([1], 'dir-cap-1@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-cap-1@example.com');
        $studentA = $this->createStudent(1, '學生A');

        // Existing 1-on-1 course and session for student A with teacher
        $scA = StudentClass::create([
            'StudentID' => $studentA->id,
            'TeacherID' => $teacherId,
            'SubjectID' => 1,
            'ClassType' => 'one_on_one',
            'GradeID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-09-07',
            'EndDate' => '2026-10-31',
            'week' => 1,
            'time' => '18:00',
            'SessionDuration' => 120,
            'TotalHours' => 16,
            'SessionCount' => 8,
            'RemainingSessions' => 8,
            'UsedSessions' => 0,
            'Rate' => 1000,
            'Charge' => 8000,
            'Pay' => 0,
            'Paid' => 0,
            'Stop' => 0,
        ]);

        ClassSession::create([
            'StudentClassID' => $scA->ID,
            'SessionDate' => '2026-09-07',
            'StartTime' => '18:00:00',
            'EndTime' => '20:00:00',
            'Status' => 'scheduled',
        ]);

        // Now attempt to enroll student B (new 1-on-2 course) with same teacher at same slot
        $studentB = $this->createStudent(1, '學生B');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/enrollments', [
            'branch_id' => 1,
            'student_id' => $studentB->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => 'one_on_two',
            'payment_type' => 'session',
            'total_classes' => 1,
            'price_per_session' => 800,
            'course_start_date' => '2026-09-07',
            'confirmed_dates' => [],
            'future_dates' => ['2026-09-07'],
            'start_time' => '18:00',
            'duration_minutes' => 120,
            'session_plan' => [
                [
                    'date' => '2026-09-07',
                    'start_time' => '18:00',
                    'duration_minutes' => 120,
                    'kind' => 'future',
                    'subject' => 'Math',
                ],
            ],
        ]);

        $res->assertStatus(409)
            ->assertJsonPath('code', 'teacher_schedule_conflict');
        $this->assertStringContainsString('一對一', (string) $res->json('message'));
    }

    public function test_add_session_and_check_add_session_block_when_teacher_slot_has_one_on_one(): void
    {
        $token = $this->createDirectorToken([1], 'dir-cap-2@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-cap-2@example.com');
        $studentA = $this->createStudent(1, '學生A2');
        $studentB = $this->createStudent(1, '學生B2');

        // Course A: 1-on-1 with session at 18:00 on 2026-09-07
        $scA = StudentClass::create([
            'StudentID' => $studentA->id,
            'TeacherID' => $teacherId,
            'SubjectID' => 1,
            'ClassType' => 'one_on_one',
            'GradeID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-09-07',
            'EndDate' => '2026-10-31',
            'week' => 1,
            'time' => '18:00',
            'SessionDuration' => 120,
            'TotalHours' => 16,
            'SessionCount' => 8,
            'RemainingSessions' => 8,
            'UsedSessions' => 0,
            'Rate' => 1000,
            'Charge' => 8000,
            'Pay' => 0,
            'Paid' => 0,
            'Stop' => 0,
        ]);

        ClassSession::create([
            'StudentClassID' => $scA->ID,
            'SessionDate' => '2026-09-07',
            'StartTime' => '18:00:00',
            'EndTime' => '20:00:00',
            'Status' => 'scheduled',
        ]);

        // Course B: 1-on-2 for student B with same teacher (different day)
        $scB = StudentClass::create([
            'StudentID' => $studentB->id,
            'TeacherID' => $teacherId,
            'SubjectID' => 1,
            'ClassType' => 'one_on_two',
            'GradeID' => 1,
            'by1' => 2,
            'Period' => 4,
            'StartDate' => '2026-09-08',
            'EndDate' => '2026-10-31',
            'week' => 2,
            'time' => '18:00',
            'SessionDuration' => 120,
            'TotalHours' => 16,
            'SessionCount' => 8,
            'RemainingSessions' => 8,
            'UsedSessions' => 0,
            'Rate' => 800,
            'Charge' => 6400,
            'Pay' => 0,
            'Paid' => 0,
            'Stop' => 0,
        ]);

        // Check add session on Course B for 2026-09-07 at 18:00
        $checkRes = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$scB->ID}/add-session/check", [
            'session_date' => '2026-09-07',
            'start_time' => '18:00',
            'duration_minutes' => 120,
        ]);

        $checkRes->assertOk();
        $this->assertFalse($checkRes->json('can_add'));
        $this->assertSame('teacher_capacity', $checkRes->json('conflict_type'));

        // Attempt actual add-session
        $addRes = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$scB->ID}/add-session", [
            'session_date' => '2026-09-07',
            'start_time' => '18:00',
            'duration_minutes' => 120,
        ]);

        $addRes->assertStatus(409)
            ->assertJsonPath('code', 'teacher_capacity_conflict');
    }

    public function test_update_course_blocks_when_changing_to_conflicting_teacher(): void
    {
        $token = $this->createDirectorToken([1], 'dir-cap-3@example.com');
        $teacher1Id = $this->createTeacher(1, 'teacher-cap-3a@example.com');
        $teacher2Id = $this->createTeacher(1, 'teacher-cap-3b@example.com');

        $studentA = $this->createStudent(1, '學生A3');
        $studentB = $this->createStudent(1, '學生B3');

        // Teacher 2 has 1-on-1 with Student A on future date 2026-09-14 18:00-20:00
        $scA = StudentClass::create([
            'StudentID' => $studentA->id,
            'TeacherID' => $teacher2Id,
            'SubjectID' => 1,
            'ClassType' => 'one_on_one',
            'GradeID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-09-14',
            'EndDate' => '2026-10-31',
            'week' => 1,
            'time' => '18:00',
            'SessionDuration' => 120,
            'TotalHours' => 16,
            'SessionCount' => 8,
            'RemainingSessions' => 8,
            'UsedSessions' => 0,
            'Rate' => 1000,
            'Charge' => 8000,
            'Pay' => 0,
            'Paid' => 0,
            'Stop' => 0,
        ]);

        ClassSession::create([
            'StudentClassID' => $scA->ID,
            'SessionDate' => '2026-09-14',
            'StartTime' => '18:00:00',
            'EndTime' => '20:00:00',
            'Status' => 'scheduled',
        ]);

        // Student B has course with Teacher 1 on Mondays 18:00-20:00
        $scB = StudentClass::create([
            'StudentID' => $studentB->id,
            'TeacherID' => $teacher1Id,
            'SubjectID' => 1,
            'ClassType' => 'one_on_two',
            'GradeID' => 1,
            'by1' => 2,
            'Period' => 4,
            'StartDate' => '2026-09-14',
            'EndDate' => '2026-10-31',
            'week' => 1,
            'time' => '18:00',
            'SessionDuration' => 120,
            'TotalHours' => 16,
            'SessionCount' => 8,
            'RemainingSessions' => 8,
            'UsedSessions' => 0,
            'Rate' => 800,
            'Charge' => 6400,
            'Pay' => 0,
            'Paid' => 0,
            'Stop' => 0,
        ]);

        ClassSession::create([
            'StudentClassID' => $scB->ID,
            'SessionDate' => '2026-09-14',
            'StartTime' => '18:00:00',
            'EndTime' => '20:00:00',
            'Status' => 'scheduled',
        ]);

        // Attempt to update scB's teacher from Teacher 1 to Teacher 2
        $updateRes = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/student-classes/{$scB->ID}", [
            'teacher_id' => $teacher2Id,
        ]);

        $updateRes->assertStatus(409)
            ->assertJsonPath('code', 'teacher_schedule_conflict');
    }

    public function test_validate_schedule_occurrence_respects_exclude_course_id(): void
    {
        $teacherId = $this->createTeacher(1, 'teacher-cap-4@example.com');
        $student = $this->createStudent(1, '學生A4');

        $sc = StudentClass::create([
            'StudentID' => $student->id,
            'TeacherID' => $teacherId,
            'SubjectID' => 1,
            'ClassType' => 'one_on_one',
            'GradeID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-09-07',
            'EndDate' => '2026-10-31',
            'week' => 1,
            'time' => '18:00',
            'SessionDuration' => 120,
            'TotalHours' => 16,
            'SessionCount' => 8,
            'RemainingSessions' => 8,
            'UsedSessions' => 0,
            'Rate' => 1000,
            'Charge' => 8000,
            'Pay' => 0,
            'Paid' => 0,
            'Stop' => 0,
        ]);

        ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-09-07',
            'StartTime' => '18:00:00',
            'EndTime' => '20:00:00',
            'Status' => 'scheduled',
        ]);

        $guard = app(ScheduleGuardService::class);

        // Without exclude_course_id: should detect conflict
        $conflictsWithoutExclude = $guard->validateScheduleOccurrence([
            'teacher_id' => $teacherId,
            'class_type' => 'one_on_one',
            'branch_id' => 1,
            'schedule_date' => '2026-09-07',
            'start_time' => '18:00',
            'end_time' => '20:00',
        ]);
        $this->assertNotEmpty($conflictsWithoutExclude);

        // With exclude_course_id: should NOT conflict with own course
        $conflictsWithExclude = $guard->validateScheduleOccurrence([
            'teacher_id' => $teacherId,
            'class_type' => 'one_on_one',
            'branch_id' => 1,
            'schedule_date' => '2026-09-07',
            'start_time' => '18:00',
            'end_time' => '20:00',
            'exclude_course_id' => (int) $sc->ID,
        ]);
        $this->assertEmpty($conflictsWithExclude);
    }

    public function test_enrollment_succeeds_when_no_teacher_conflict(): void
    {
        $token = $this->createDirectorToken([1], 'dir-cap-ok@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-cap-ok@example.com');
        $student = $this->createStudent(1, '學生OK');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/enrollments', [
            'branch_id' => 1,
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'payment_type' => 'session',
            'total_classes' => 1,
            'price_per_session' => 1000,
            'course_start_date' => '2026-09-07',
            'confirmed_dates' => [],
            'future_dates' => ['2026-09-07'],
            'start_time' => '18:00',
            'duration_minutes' => 120,
            'session_plan' => [
                [
                    'date' => '2026-09-07',
                    'start_time' => '18:00',
                    'duration_minutes' => 120,
                    'kind' => 'future',
                    'subject' => 'Math',
                ],
            ],
        ]);

        $res->assertCreated();
    }

    public function test_purchase_batch_blocks_when_teacher_slot_has_conflict(): void
    {
        $token = $this->createDirectorToken([1], 'dir-cap-pb@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-cap-pb@example.com');
        $studentA = $this->createStudent(1, '學生PB1');
        $studentB = $this->createStudent(1, '學生PB2');

        // Student A has 1-on-1 on Tuesdays (week 2) 18:00-20:00, with a session on 2026-09-08
        $scA = StudentClass::create([
            'StudentID' => $studentA->id,
            'TeacherID' => $teacherId,
            'SubjectID' => 1,
            'ClassType' => 'one_on_one',
            'GradeID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-09-08',
            'EndDate' => '2026-10-31',
            'week' => 2,
            'time' => '18:00',
            'SessionDuration' => 120,
            'TotalHours' => 16,
            'SessionCount' => 8,
            'RemainingSessions' => 8,
            'UsedSessions' => 0,
            'Rate' => 1000,
            'Charge' => 8000,
            'Pay' => 0,
            'Paid' => 0,
            'Stop' => 0,
        ]);

        ClassSession::create([
            'StudentClassID' => $scA->ID,
            'SessionDate' => '2026-09-08',
            'StartTime' => '18:00:00',
            'EndTime' => '20:00:00',
            'Status' => 'scheduled',
        ]);

        // Student B has a finished/expiring course on Tuesdays with same teacher
        $scB = StudentClass::create([
            'StudentID' => $studentB->id,
            'TeacherID' => $teacherId,
            'SubjectID' => 1,
            'ClassType' => 'one_on_two',
            'GradeID' => 1,
            'by1' => 2,
            'Period' => 0,
            'ScheduleMode' => 'count',
            'StartDate' => '2026-08-01',
            'EndDate' => '2026-09-01',
            'week' => 2,
            'time' => '18:00',
            'SessionDuration' => 120,
            'TotalHours' => 8,
            'SessionCount' => 4,
            'RemainingSessions' => 0,
            'UsedSessions' => 4,
            'Rate' => 800,
            'Charge' => 3200,
            'Pay' => 0,
            'Paid' => 1,
            'Stop' => 0,
        ]);

        // Attempt purchase-batch for student B starting 2026-09-08 (conflicts with student A's 1-on-1!)
        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$scB->ID}/purchase-batch", [
            'sessions' => 2,
            'start_date' => '2026-09-08',
            'mode' => 'new_purchase',
        ]);

        $res->assertStatus(409)
            ->assertJsonPath('code', 'purchase_batch_schedule_conflict');
    }

    private function createDirectorToken(array $campusIds, string $email): string
    {
        $user = User::create([
            'LoginName' => $email,
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
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

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
