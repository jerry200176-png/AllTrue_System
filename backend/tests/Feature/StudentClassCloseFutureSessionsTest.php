<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudentClassCloseFutureSessionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pause_endpoint_cancels_future_scheduled_sessions_for_settled_course(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-27 10:00:00'));
        try {
            $token = $this->createDirectorToken([1]);
            $student = $this->createStudent();
            $course = $this->createStudentClass($student->id, [
                'Paid' => 1,
                'RemainingSessions' => 0,
                'UsedSessions' => 8,
                'SessionCount' => 8,
            ]);
            $futureSession = $this->createClassSession((int) $course->ID, '2026-04-28', 'scheduled');
            $pastSession = $this->createClassSession((int) $course->ID, '2026-04-20', 'attended');

            $this->postJson(
                "/api/v1/student-classes/{$course->ID}/pause",
                ['action' => 'pause', 'reason' => 'settled'],
                ['Authorization' => "Bearer {$token}"]
            )->assertOk();

            $this->assertSame('cancelled', DB::table('ClassSession')->where('id', $futureSession)->value('Status'));
            $this->assertSame('attended', DB::table('ClassSession')->where('id', $pastSession)->value('Status'));
            $course->refresh();
            $this->assertSame(1, (int) $course->Stop);
            $this->assertSame('settled', $course->closed_reason);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_direct_status_update_to_inactive_cannot_leave_future_scheduled_sessions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-27 10:00:00'));
        try {
            $token = $this->createDirectorToken([1]);
            $student = $this->createStudent();
            $course = $this->createStudentClass($student->id, [
                'Paid' => 1,
                'RemainingSessions' => 0,
                'UsedSessions' => 8,
                'SessionCount' => 8,
            ]);
            $futureSession = $this->createClassSession((int) $course->ID, '2026-04-28', 'scheduled');

            $this->putJson(
                "/api/v1/student-classes/{$course->ID}",
                ['status' => 'inactive'],
                ['Authorization' => "Bearer {$token}"]
            )->assertOk();

            $this->assertSame(
                'cancelled',
                DB::table('ClassSession')->where('id', $futureSession)->value('Status'),
                'Direct PUT status=inactive must share the same future-session cancellation behavior as the pause endpoint'
            );
            $course->refresh();
            $this->assertSame(1, (int) $course->Stop);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_resaving_already_inactive_course_also_cleans_future_scheduled_sessions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-27 10:00:00'));
        try {
            $token = $this->createDirectorToken([1]);
            $student = $this->createStudent();
            $course = $this->createStudentClass($student->id, [
                'Stop' => 1,
                'closed_reason' => 'settled',
                'Paid' => 1,
                'RemainingSessions' => 0,
            ]);
            $futureSession = $this->createClassSession((int) $course->ID, '2026-04-28', 'scheduled');

            $this->putJson(
                "/api/v1/student-classes/{$course->ID}",
                ['status' => 'inactive', 'Memo' => 'resave historical course'],
                ['Authorization' => "Bearer {$token}"]
            )->assertOk();

            $this->assertSame('cancelled', DB::table('ClassSession')->where('id', $futureSession)->value('Status'));
        } finally {
            Carbon::setTestNow();
        }
    }

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'close-test-' . uniqid() . '@example.com',
            'Name' => '結案測試主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0900000000',
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

    private function createStudent(): Student
    {
        return Student::create([
            'name' => '周宏謙測試生',
            'CampusID' => 1,
            'ClassID' => 1,
            'SchoolName' => 'Test School',
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    private function createStudentClass(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-04-01',
            'TotalHours' => 20,
            'Charge' => 0,
            'Paid' => 0,
            'Rate' => 0,
            'RoomID' => 'R1',
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 60,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ], $overrides));
    }

    private function createClassSession(int $courseId, string $date, string $status): int
    {
        return DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $courseId,
            'SessionDate' => $date,
            'StartTime' => '23:00',
            'EndTime' => '23:30',
            'Status' => $status,
        ]);
    }
}
