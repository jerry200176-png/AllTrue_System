<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MakeupAttendanceEndedSessionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ended_sessions_returns_past_unattended_sessions(): void
    {
        $teacherId = $this->createTeacher(1, 'teacher-ended@example.com');
        $directorToken = $this->createDirectorToken([1], 'director-ended@example.com');
        $student = $this->createStudent(1, '補點名學生');

        $yesterday = Carbon::yesterday()->toDateString();
        $cs = ClassSession::create([
            'StudentClassID' => $this->createStudentClass($student->id, $teacherId),
            'SessionDate' => $yesterday,
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'scheduled',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$directorToken}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/attendance/ended-sessions?branch_id=1&start_date={$yesterday}&end_date={$yesterday}");

        $res->assertOk();
        $data = $res->json('data');
        $this->assertNotEmpty($data);
        $ids = array_column($data, 'class_session_id');
        $this->assertContains($cs->id, $ids);
    }

    public function test_ended_sessions_excludes_paused_student_class(): void
    {
        $teacherId = $this->createTeacher(1, 'teacher-paused-sc@example.com');
        $directorToken = $this->createDirectorToken([1], 'director-paused-sc@example.com');
        $student = $this->createStudent(1, '暫停課學生');

        $yesterday = Carbon::yesterday()->toDateString();
        $scId = $this->createStudentClass($student->id, $teacherId, ['Stop' => 1]);
        $cs = ClassSession::create([
            'StudentClassID' => $scId,
            'SessionDate' => $yesterday,
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'cancelled',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$directorToken}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/attendance/ended-sessions?branch_id=1&start_date={$yesterday}&end_date={$yesterday}");

        $res->assertOk();
        $ids = array_column($res->json('data') ?? [], 'class_session_id');
        $this->assertNotContains($cs->id, $ids);
    }

    public function test_ended_sessions_excludes_sessions_with_active_sign_in(): void
    {
        $teacherId = $this->createTeacher(1, 'teacher-excl@example.com');
        $directorToken = $this->createDirectorToken([1], 'director-excl@example.com');
        $student = $this->createStudent(1, '已點名學生');

        $yesterday = Carbon::yesterday()->toDateString();
        $scId = $this->createStudentClass($student->id, $teacherId);
        $cs = ClassSession::create([
            'StudentClassID' => $scId,
            'SessionDate' => $yesterday,
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'attended',
        ]);

        StudentSignIn::create([
            'StudentClassID' => $scId,
            'StudentID' => $student->id,
            'TeacherID' => $teacherId,
            'SignInDT' => "{$yesterday} 16:00:00",
            'MDT' => now(),
            'ClassSessionID' => $cs->id,
            'Status' => 'present',
            'CampusID' => 1,
            'SessionDeducted' => false,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$directorToken}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/attendance/ended-sessions?branch_id=1&start_date={$yesterday}&end_date={$yesterday}");

        $res->assertOk();
        $ids = array_column($res->json('data') ?? [], 'class_session_id');
        $this->assertNotContains($cs->id, $ids);
    }

    public function test_ended_sessions_includes_sessions_with_only_voided_sign_in(): void
    {
        $teacherId = $this->createTeacher(1, 'teacher-void@example.com');
        $directorToken = $this->createDirectorToken([1], 'director-void@example.com');
        $student = $this->createStudent(1, '作廢後補點');

        $yesterday = Carbon::yesterday()->toDateString();
        $scId = $this->createStudentClass($student->id, $teacherId);
        $cs = ClassSession::create([
            'StudentClassID' => $scId,
            'SessionDate' => $yesterday,
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'scheduled',
        ]);

        StudentSignIn::create([
            'StudentClassID' => $scId,
            'StudentID' => $student->id,
            'TeacherID' => $teacherId,
            'SignInDT' => "{$yesterday} 16:00:00",
            'MDT' => now(),
            'ClassSessionID' => $cs->id,
            'Status' => 'present',
            'CampusID' => 1,
            'SessionDeducted' => false,
            'VoidedAt' => now(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$directorToken}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/attendance/ended-sessions?branch_id=1&start_date={$yesterday}&end_date={$yesterday}");

        $res->assertOk();
        $ids = array_column($res->json('data') ?? [], 'class_session_id');
        $this->assertContains($cs->id, $ids);
    }

    public function test_ended_sessions_requires_branch_id_for_super_admin(): void
    {
        $token = $this->createSuperAdminToken('sa-ended@example.com');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/attendance/ended-sessions');

        $res->assertStatus(422);
    }

    public function test_teacher_only_sees_own_sessions(): void
    {
        $teacher1 = $this->createTeacher(1, 'teacher-own@example.com');
        $teacher2 = $this->createTeacher(1, 'teacher-other@example.com');
        $teacherToken = $this->createTeacherToken($teacher1);

        $student = $this->createStudent(1, '隔離測試學生');
        $yesterday = Carbon::yesterday()->toDateString();

        $sc1 = $this->createStudentClass($student->id, $teacher1);
        ClassSession::create([
            'StudentClassID' => $sc1,
            'SessionDate' => $yesterday,
            'StartTime' => '16:00', 'EndTime' => '18:00',
            'Status' => 'scheduled',
        ]);

        $sc2 = $this->createStudentClass($student->id, $teacher2);
        $csOther = ClassSession::create([
            'StudentClassID' => $sc2,
            'SessionDate' => $yesterday,
            'StartTime' => '10:00', 'EndTime' => '12:00',
            'Status' => 'scheduled',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$teacherToken}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/attendance/ended-sessions?branch_id=1&start_date={$yesterday}&end_date={$yesterday}");

        $res->assertOk();
        $ids = array_column($res->json('data') ?? [], 'class_session_id');
        $this->assertNotContains($csOther->id, $ids);
    }

    public function test_cross_branch_forbidden(): void
    {
        $directorToken = $this->createDirectorToken([1], 'director-cross@example.com');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$directorToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/attendance/ended-sessions?branch_id=99');

        $res->assertForbidden();
    }

    public function test_makeup_attendance_post_deducts_session(): void
    {
        $teacherId = $this->createTeacher(1, 'teacher-deduct@example.com');
        $directorToken = $this->createDirectorToken([1], 'director-deduct@example.com');
        $student = $this->createStudent(1, '扣堂測試');

        $yesterday = Carbon::yesterday()->toDateString();
        $scId = $this->createStudentClass($student->id, $teacherId, ['RemainingSessions' => 10, 'UsedSessions' => 0]);
        $cs = ClassSession::create([
            'StudentClassID' => $scId,
            'SessionDate' => $yesterday,
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'scheduled',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$directorToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/attendance', [
            'StudentID' => $student->id,
            'StudentClassID' => $scId,
            'ClassSessionID' => $cs->id,
            'Status' => 'present',
        ]);

        $res->assertCreated();

        $cs->refresh();
        $this->assertSame('attended', strtolower((string) $cs->Status));

        $signIn = StudentSignIn::where('ClassSessionID', $cs->id)
            ->whereNull('VoidedAt')
            ->first();
        $this->assertNotNull($signIn);
        $this->assertSame('present', (string) $signIn->Status);
    }

    public function test_ended_sessions_excludes_cancelled_sessions(): void
    {
        $teacherId = $this->createTeacher(1, 'teacher-cancel@example.com');
        $directorToken = $this->createDirectorToken([1], 'director-cancel@example.com');
        $student = $this->createStudent(1, '取消堂學生');

        $yesterday = Carbon::yesterday()->toDateString();
        $scId = $this->createStudentClass($student->id, $teacherId);
        $cs = ClassSession::create([
            'StudentClassID' => $scId,
            'SessionDate' => $yesterday,
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'cancelled',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$directorToken}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/attendance/ended-sessions?branch_id=1&start_date={$yesterday}&end_date={$yesterday}");

        $res->assertOk();
        $ids = array_column($res->json('data') ?? [], 'class_session_id');
        $this->assertNotContains($cs->id, $ids);
    }

    public function test_ended_sessions_excludes_leave_adjusted_sessions(): void
    {
        $teacherId = $this->createTeacher(1, 'teacher-la@example.com');
        $directorToken = $this->createDirectorToken([1], 'director-la@example.com');
        $student = $this->createStudent(1, '請假調整學生');

        $yesterday = Carbon::yesterday()->toDateString();
        $scId = $this->createStudentClass($student->id, $teacherId);
        $cs = ClassSession::create([
            'StudentClassID' => $scId,
            'SessionDate' => $yesterday,
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'leave_adjusted',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$directorToken}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/attendance/ended-sessions?branch_id=1&start_date={$yesterday}&end_date={$yesterday}");

        $res->assertOk();
        $ids = array_column($res->json('data') ?? [], 'class_session_id');
        $this->assertNotContains($cs->id, $ids);
    }

    public function test_ended_sessions_excludes_leave_sessions(): void
    {
        $teacherId = $this->createTeacher(1, 'teacher-leave@example.com');
        $directorToken = $this->createDirectorToken([1], 'director-leave@example.com');
        $student = $this->createStudent(1, '已請假學生');

        $yesterday = Carbon::yesterday()->toDateString();
        $scId = $this->createStudentClass($student->id, $teacherId);
        $cs = ClassSession::create([
            'StudentClassID' => $scId,
            'SessionDate' => $yesterday,
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'leave',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$directorToken}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/attendance/ended-sessions?branch_id=1&start_date={$yesterday}&end_date={$yesterday}");

        $res->assertOk();
        $ids = array_column($res->json('data') ?? [], 'class_session_id');
        $this->assertNotContains($cs->id, $ids);
    }

    public function test_ended_sessions_returns_session_status_field(): void
    {
        $teacherId = $this->createTeacher(1, 'teacher-field@example.com');
        $directorToken = $this->createDirectorToken([1], 'director-field@example.com');
        $student = $this->createStudent(1, '欄位測試學生');

        $yesterday = Carbon::yesterday()->toDateString();
        $scId = $this->createStudentClass($student->id, $teacherId);
        ClassSession::create([
            'StudentClassID' => $scId,
            'SessionDate' => $yesterday,
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'scheduled',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$directorToken}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/attendance/ended-sessions?branch_id=1&start_date={$yesterday}&end_date={$yesterday}");

        $res->assertOk();
        $data = $res->json('data');
        $this->assertNotEmpty($data);
        $this->assertArrayHasKey('session_status', $data[0]);
        $this->assertSame('scheduled', $data[0]['session_status']);
    }

    public function test_teacher_substitute_sessions_still_visible(): void
    {
        $contractTeacher = $this->createTeacher(1, 'teacher-contract-sub@example.com');
        $subTeacher = $this->createTeacher(1, 'teacher-sub@example.com');
        $subToken = $this->createTeacherToken($subTeacher);
        $student = $this->createStudent(1, '代課學生');

        $yesterday = Carbon::yesterday()->toDateString();
        $scId = $this->createStudentClass($student->id, $contractTeacher);
        $cs = ClassSession::create([
            'StudentClassID' => $scId,
            'SessionDate' => $yesterday,
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'scheduled',
        ]);

        DB::table('schedules')->insert([
            'student_id' => $student->id,
            'teacher_id' => $subTeacher,
            'subject' => 'Math',
            'day_of_week' => Carbon::parse($yesterday)->dayOfWeekIso,
            'start_time' => '16:00',
            'end_time' => '18:00',
            'class_type' => 'one_on_one',
            'status' => 'scheduled',
            'type' => 'normal',
            'deduction' => 0,
            'branch_id' => 1,
            'schedule_date' => $yesterday,
            'student_course_id' => $scId,
            'original_schedule_id' => 999,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$subToken}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/attendance/ended-sessions?branch_id=1&start_date={$yesterday}&end_date={$yesterday}");

        $res->assertOk();
        $ids = array_column($res->json('data') ?? [], 'class_session_id');
        $this->assertContains($cs->id, $ids);
    }

    // ── helpers ──

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
            UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }

    private function createSuperAdminToken(string $loginName): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => 'Super Admin',
            'PSW' => 'secret',
            'type' => 'S',
            'phone' => '0900000000',
            'MustChangePassword' => false,
        ]);
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
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        return (int) $teacher->id;
    }

    private function createTeacherToken(int $teacherId): string
    {
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $teacherId, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
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

    private function createStudentClass(int $studentId, int $teacherId, array $overrides = []): int
    {
        $sc = StudentClass::create(array_merge([
            'StudentID' => $studentId,
            'TeacherID' => $teacherId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'ClassType' => 'one_on_one',
            'SessionCount' => 10,
            'SessionDuration' => 120,
            'RemainingSessions' => 10,
            'UsedSessions' => 0,
            'Rate' => 500,
            'TotalHours' => 20,
            'Charge' => 5000,
            'Pay' => 5000,
            'Paid' => 0,
            'Stop' => 0,
            'by1' => 0,
            'ScheduleMode' => 'date',
            'StartDate' => Carbon::now()->subMonth()->toDateString(),
            'EndDate' => Carbon::now()->addMonths(3)->toDateString(),
        ], $overrides));
        return (int) $sc->ID;
    }
}
