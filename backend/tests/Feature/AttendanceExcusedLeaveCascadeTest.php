<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttendanceExcusedLeaveCascadeTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_attendance_excused_triggers_leave_cascade(): void
    {
        $teacherId = $this->createTeacher(1, 'teacher-att-leave@example.com');
        $teacherToken = $this->createTeacherToken($teacherId, 1);
        $directorToken = $this->createDirectorToken([1], 'director-att-leave@example.com');
        $student = $this->createStudent(1, '出缺勤請假順延');

        $firstWednesday = Carbon::now()->next(Carbon::WEDNESDAY);
        $futureDates = [];
        for ($i = 0; $i < 8; $i++) {
            $futureDates[] = $firstWednesday->copy()->addWeeks($i)->toDateString();
        }

        $this->createCourseViaBatchApi($directorToken, $student->id, $teacherId, [
            'total_classes' => 8,
            'confirmed_dates' => [],
            'future_dates' => $futureDates,
            'days_of_week' => [3],
            'start_time' => '16:00',
        ])->assertCreated();

        $courseId = (int) DB::table('StudentClass')
            ->where('StudentID', $student->id)
            ->where('TeacherID', $teacherId)
            ->max('ID');
        $this->assertTrue($courseId > 0);

        $sessions = ClassSession::where('StudentClassID', $courseId)
            ->orderBy('SessionDate', 'asc')
            ->get();
        $this->assertCount(8, $sessions);

        $targetSession = $sessions[2];
        $targetDate = Carbon::parse($targetSession->SessionDate)->toDateString();
        $nextSession = $sessions[3];
        $lastSession = $sessions[7];
        $lastSessionOriginalDate = Carbon::parse($lastSession->SessionDate)->toDateString();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$teacherToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/attendance', [
            'StudentID' => $student->id,
            'StudentClassID' => $courseId,
            'ClassSessionID' => $targetSession->id,
            'Status' => 'excused',
            'mark_mode' => 'arrival',
        ]);

        $res->assertCreated();
        $res->assertJsonFragment(['status_label' => '請假']);
        $this->assertNotNull($res->json('leave_session_date'));
        $this->assertNotNull($res->json('extended_end_date'));
        $this->assertNotNull($res->json('class_sessions'));

        $targetSession->refresh();
        $this->assertSame('leave', strtolower((string) $targetSession->Status));

        $this->assertDatabaseHas('schedules', [
            'student_course_id' => $courseId,
            'schedule_date' => $targetDate,
            'status' => 'leave',
        ]);

        $activeSignIn = StudentSignIn::where('ClassSessionID', $targetSession->id)
            ->whereNull('VoidedAt')
            ->first();
        $this->assertNotNull($activeSignIn, 'Leave session should have an active sign-in record');
        $this->assertSame('leave', (string) $activeSignIn->Status);
        $this->assertSame(0, (int) $activeSignIn->SessionDeducted);

        $totalSessions = ClassSession::where('StudentClassID', $courseId)->count();
        $this->assertSame(9, $totalSessions);

        $course = StudentClass::findOrFail($courseId);
        $extendedEnd = Carbon::parse($course->EndDate)->toDateString();
        $this->assertTrue($extendedEnd > $lastSessionOriginalDate);
    }

    public function test_director_attendance_excused_also_triggers_cascade(): void
    {
        $teacherId = $this->createTeacher(1, 'teacher-dir-excused@example.com');
        $directorToken = $this->createDirectorToken([1], 'director-dir-excused@example.com');
        $student = $this->createStudent(1, '主任請假順延');

        $firstWednesday = Carbon::now()->next(Carbon::WEDNESDAY);
        $futureDates = [];
        for ($i = 0; $i < 4; $i++) {
            $futureDates[] = $firstWednesday->copy()->addWeeks($i)->toDateString();
        }

        $this->createCourseViaBatchApi($directorToken, $student->id, $teacherId, [
            'total_classes' => 4,
            'confirmed_dates' => [],
            'future_dates' => $futureDates,
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

        $total = ClassSession::where('StudentClassID', $courseId)->count();
        $this->assertSame(5, $total);
    }

    public function test_attendance_excused_without_class_session_id_falls_back_to_old_behavior(): void
    {
        $directorToken = $this->createDirectorToken([1], 'director-fallback@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-fallback@example.com');
        $student = $this->createStudent(1, '舊行為測試');

        $firstWednesday = Carbon::now()->next(Carbon::WEDNESDAY);
        $futureDates = [];
        for ($i = 0; $i < 4; $i++) {
            $futureDates[] = $firstWednesday->copy()->addWeeks($i)->toDateString();
        }

        $this->createCourseViaBatchApi($directorToken, $student->id, $teacherId, [
            'total_classes' => 4,
            'confirmed_dates' => [],
            'future_dates' => $futureDates,
            'days_of_week' => [3],
            'start_time' => '16:00',
        ])->assertCreated();

        $courseId = (int) DB::table('StudentClass')
            ->where('StudentID', $student->id)
            ->max('ID');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$directorToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/attendance', [
            'StudentID' => $student->id,
            'StudentClassID' => $courseId,
            'SessionDate' => $futureDates[0],
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'excused',
            'mark_mode' => 'arrival',
        ]);

        $res->assertCreated();

        $this->assertDatabaseMissing('schedules', [
            'student_course_id' => $courseId,
            'status' => 'leave',
        ]);

        $signIn = StudentSignIn::where('StudentClassID', $courseId)
            ->whereNull('VoidedAt')
            ->first();
        $this->assertNotNull($signIn);
        $this->assertSame('leave', (string) $signIn->Status);
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

    private function createTeacherToken(int $teacherId, int $campusId): string
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

    private function createCourseViaBatchApi(string $token, int $studentId, int $teacherId, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        $payload = array_merge([
            'branch_id' => 1,
            'student_id' => $studentId,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'total_classes' => 8,
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
