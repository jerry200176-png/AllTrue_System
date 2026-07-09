<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttendanceLeaveStatusContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Frontend sends Status=leave directly (not excused). Backend must accept it
     * and trigger the leave-cascade flow identically to excused.
     */
    public function test_status_leave_accepted_and_triggers_cascade(): void
    {
        $teacherId = $this->createTeacher(1, 'teacher-leave-contract@test.com');
        $teacherToken = $this->createTeacherToken($teacherId);
        $directorToken = $this->createDirectorToken([1], 'dir-leave-contract@test.com');
        $student = $this->createStudent(1, '直接送leave');

        $dates = $this->futureDates(4, Carbon::WEDNESDAY);

        $this->createCourseViaBatchApi($directorToken, $student->id, $teacherId, [
            'total_classes' => 4,
            'future_dates' => $dates,
            'days_of_week' => [3],
            'start_time' => '16:00',
        ])->assertCreated();

        $courseId = (int) DB::table('StudentClass')
            ->where('StudentID', $student->id)
            ->where('TeacherID', $teacherId)
            ->max('ID');

        $session = ClassSession::where('StudentClassID', $courseId)
            ->orderBy('SessionDate')
            ->first();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$teacherToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/attendance', [
            'StudentID' => $student->id,
            'StudentClassID' => $courseId,
            'ClassSessionID' => $session->id,
            'Status' => 'leave',
            'mark_mode' => 'arrival',
        ]);

        $res->assertCreated();
        $res->assertJsonFragment(['status_label' => '請假']);

        $session->refresh();
        $this->assertSame('leave', strtolower((string) $session->Status));

        $total = ClassSession::where('StudentClassID', $courseId)->count();
        $this->assertSame(5, $total, 'Leave cascade should extend by 1 session');
    }

    /**
     * Excused still works identically (backward compat).
     */
    public function test_status_excused_still_accepted(): void
    {
        $teacherId = $this->createTeacher(1, 'teacher-excused-compat@test.com');
        $teacherToken = $this->createTeacherToken($teacherId);
        $directorToken = $this->createDirectorToken([1], 'dir-excused-compat@test.com');
        $student = $this->createStudent(1, 'excused相容');

        $dates = $this->futureDates(4, Carbon::WEDNESDAY);

        $this->createCourseViaBatchApi($directorToken, $student->id, $teacherId, [
            'total_classes' => 4,
            'future_dates' => $dates,
            'days_of_week' => [3],
            'start_time' => '16:00',
        ])->assertCreated();

        $courseId = (int) DB::table('StudentClass')
            ->where('StudentID', $student->id)
            ->max('ID');

        $session = ClassSession::where('StudentClassID', $courseId)
            ->orderBy('SessionDate')
            ->first();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$teacherToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/attendance', [
            'StudentID' => $student->id,
            'StudentClassID' => $courseId,
            'ClassSessionID' => $session->id,
            'Status' => 'excused',
            'mark_mode' => 'arrival',
        ]);

        $res->assertCreated();

        $session->refresh();
        $this->assertSame('leave', strtolower((string) $session->Status));
    }

    /**
     * Invalid status values still rejected with 422.
     */
    public function test_invalid_status_rejected(): void
    {
        $directorToken = $this->createDirectorToken([1], 'dir-invalid-status@test.com');
        $teacherId = $this->createTeacher(1, 'teacher-invalid-status@test.com');
        $student = $this->createStudent(1, '非法狀態');
        $courseId = $this->bootstrapCourse($student->id, $teacherId, 4);
        $cs = $this->pastClassSession($courseId);

        $this->withHeaders([
            'Authorization' => "Bearer {$directorToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/attendance', [
            'StudentID' => $student->id,
            'StudentClassID' => $courseId,
            'ClassSessionID' => $cs->id,
            'Status' => 'bogus_value',
            'mark_mode' => 'arrival',
        ])->assertStatus(422);
    }

    /**
     * Non-contract, non-substitute teacher gets descriptive 403.
     */
    public function test_non_contract_teacher_gets_descriptive_403(): void
    {
        $contractTeacherId = $this->createTeacher(1, 'contract-teacher@test.com');
        $otherTeacher = User::create([
            'LoginName' => 'other-teacher@test.com',
            'Name' => '他人老師',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0933000000',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $otherTeacher->id, 'Admin' => 0, 'Approved' => 1]);
        $otherToken = $this->createTokenForUser($otherTeacher->id);

        $student = $this->createStudent(1, '權限測試');
        $courseId = $this->bootstrapCourse($student->id, $contractTeacherId, 4);
        $cs = $this->pastClassSession($courseId);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$otherToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/attendance', [
            'StudentID' => $student->id,
            'StudentClassID' => $courseId,
            'ClassSessionID' => $cs->id,
            'Status' => 'leave',
            'mark_mode' => 'arrival',
        ]);

        $res->assertStatus(403);
        $msg = $res->json('message');
        $this->assertStringContainsString('授課', $msg, 'Should mention non-contract teacher reason');
    }

    /**
     * batch-mark also accepts Status=leave in items.
     */
    public function test_batch_mark_accepts_leave_status(): void
    {
        $directorToken = $this->createDirectorToken([1], 'dir-batch-leave@test.com');
        $teacherId = $this->createTeacher(1, 'teacher-batch-leave@test.com');
        $student = $this->createStudent(1, '批次leave');

        $dates = $this->futureDates(4, Carbon::WEDNESDAY);

        $this->createCourseViaBatchApi($directorToken, $student->id, $teacherId, [
            'total_classes' => 4,
            'future_dates' => $dates,
            'days_of_week' => [3],
            'start_time' => '16:00',
        ])->assertCreated();

        $courseId = (int) DB::table('StudentClass')
            ->where('StudentID', $student->id)
            ->max('ID');

        $session = ClassSession::where('StudentClassID', $courseId)
            ->orderBy('SessionDate')
            ->first();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$directorToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/attendance/batch-mark', [
            'items' => [
                [
                    'ClassSessionID' => $session->id,
                    'StudentID' => $student->id,
                    'StudentClassID' => $courseId,
                    'Status' => 'leave',
                    'mark_mode' => 'arrival',
                ],
            ],
        ]);

        $res->assertOk();
        $body = $res->json();
        $this->assertSame(1, $body['success_count']);
        $this->assertTrue($body['results'][0]['success']);
    }

    // ── Helpers ──

    private function futureDates(int $count, int $dayOfWeek): array
    {
        $start = Carbon::now()->next($dayOfWeek);
        $dates = [];
        for ($i = 0; $i < $count; $i++) {
            $dates[] = $start->copy()->addWeeks($i)->toDateString();
        }
        return $dates;
    }

    private function createDirectorToken(array $campusIds, string $loginName): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => '主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
            'MustChangePassword' => false,
        ]);
        foreach ($campusIds as $cid) {
            UserCampus::create(['CampusID' => $cid, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }
        return $this->createTokenForUser($user->id);
    }

    private function createTeacher(int $campusId, string $loginName): int
    {
        $teacher = User::create([
            'LoginName' => $loginName,
            'Name' => '老師',
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
        return $this->createTokenForUser($teacherId);
    }

    private function createTokenForUser(int $userId): string
    {
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $userId, 'token' => $raw, 'expires_at' => now()->addDay()]);
        return $raw;
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

    private function bootstrapCourse(int $studentId, int $teacherId, int $count): int
    {
        $course = StudentClass::create([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => $teacherId,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-03-01',
            'TotalHours' => 40,
            'Memo' => 'leave-contract-test',
            'Paid' => 0,
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => $count,
            'RemainingSessions' => $count,
            'UsedSessions' => 0,
            'SessionDuration' => 120,
            'ClassType' => 'one_on_one',
            'MDate' => now(),
            'Rate' => 500,
        ]);
        return (int) $course->ID;
    }

    private function pastClassSession(int $courseId): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => now()->subDay()->toDateString(),
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'scheduled',
            'Note' => '',
        ]);
    }

    private function createCourseViaBatchApi(string $token, int $studentId, int $teacherId, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        $payload = array_merge([
            'branch_id' => 1,
            'student_id' => $studentId,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'total_classes' => 4,
            'confirmed_dates' => [],
            'future_dates' => [],
            'days_of_week' => [3],
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type' => 'session',
            'start_time' => '16:00',
        ], $overrides);

        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', $payload);
    }
}
