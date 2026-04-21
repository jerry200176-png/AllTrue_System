<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * FR-A：當 ClassSession 時間異動時，對應的 schedules exception 列應同步更新。
 *
 * 防止「課程管理改 18:30，行事曆還顯示 18:00」的顯示漂移。
 */
class ClassSessionTimeSyncSchedulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_session_time_syncs_matching_rescheduled_exception(): void
    {
        [$token, $courseId, $session, $studentId] = $this->setupCourseWithSession();
        $oldStart = substr((string) $session->StartTime, 0, 5);
        $oldEnd   = substr((string) $session->EndTime, 0, 5);
        $dateStr  = substr((string) $session->SessionDate, 0, 10);

        $teacher2Id = $this->createTeacher(1, 'teacher-sync-2-' . uniqid() . '@example.com');

        $schedId = $this->insertSchedule($courseId, $studentId, $teacher2Id, $dateStr, $oldStart, $oldEnd, 'rescheduled');

        $this->patchSession($token, $session->id, [
            'status'     => 'scheduled',
            'start_time' => '18:30',
            'end_time'   => '20:30',
        ])->assertOk();

        $after = DB::table('schedules')->where('id', $schedId)->first();
        $this->assertSame('18:30', substr((string) $after->start_time, 0, 5));
        $this->assertSame('20:30', substr((string) $after->end_time, 0, 5));
    }

    public function test_schedules_row_with_different_time_is_not_touched(): void
    {
        [$token, $courseId, $session, $studentId] = $this->setupCourseWithSession();
        $dateStr = substr((string) $session->SessionDate, 0, 10);

        $teacher2Id = $this->createTeacher(1, 'teacher-sync-3-' . uniqid() . '@example.com');

        $schedId = $this->insertSchedule($courseId, $studentId, $teacher2Id, $dateStr, '09:00', '10:00', 'rescheduled');

        $this->patchSession($token, $session->id, [
            'status'     => 'scheduled',
            'start_time' => '18:30',
            'end_time'   => '20:30',
        ])->assertOk();

        $after = DB::table('schedules')->where('id', $schedId)->first();
        $this->assertSame('09:00', substr((string) $after->start_time, 0, 5));
        $this->assertSame('10:00', substr((string) $after->end_time, 0, 5));
    }

    public function test_leave_status_schedule_is_not_touched(): void
    {
        [$token, $courseId, $session, $studentId] = $this->setupCourseWithSession();
        $oldStart = substr((string) $session->StartTime, 0, 5);
        $oldEnd   = substr((string) $session->EndTime, 0, 5);
        $dateStr  = substr((string) $session->SessionDate, 0, 10);

        $teacher2Id = $this->createTeacher(1, 'teacher-sync-4-' . uniqid() . '@example.com');

        $schedId = $this->insertSchedule($courseId, $studentId, $teacher2Id, $dateStr, $oldStart, $oldEnd, 'leave');

        $this->patchSession($token, $session->id, [
            'status'     => 'scheduled',
            'start_time' => '18:30',
            'end_time'   => '20:30',
        ])->assertOk();

        $after = DB::table('schedules')->where('id', $schedId)->first();
        $this->assertSame($oldStart, substr((string) $after->start_time, 0, 5));
        $this->assertSame($oldEnd, substr((string) $after->end_time, 0, 5));
    }

    // ── Helpers ──

    private function setupCourseWithSession(): array
    {
        $token = $this->createDirectorToken([1], 'dir-sync-' . uniqid() . '@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-sync-' . uniqid() . '@example.com');
        $student = $this->createStudent(1, '同步測試' . uniqid());
        $courseId = $this->createCourseWithSessions($token, $student->id, $teacherId);
        $session = ClassSession::where('StudentClassID', $courseId)->first();
        return [$token, $courseId, $session, $student->id];
    }

    private function insertSchedule(int $courseId, int $studentId, int $teacherId, string $date, string $start, string $end, string $status): int
    {
        $dayOfWeek = Carbon::parse($date)->dayOfWeekIso;
        return (int) DB::table('schedules')->insertGetId([
            'student_id'           => $studentId,
            'student_course_id'    => $courseId,
            'teacher_id'           => $teacherId,
            'day_of_week'          => $dayOfWeek,
            'schedule_date'        => $date,
            'start_time'           => $start,
            'end_time'             => $end,
            'status'               => $status,
            'type'                 => 'course',
            'original_schedule_id' => null,
            'branch_id'            => 1,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);
    }

    private function createCourseWithSessions(string $token, int $studentId, int $teacherId): int
    {
        $firstWednesday = Carbon::now()->next(Carbon::WEDNESDAY);
        $futureDates = [];
        for ($i = 0; $i < 4; $i++) {
            $futureDates[] = $firstWednesday->copy()->addWeeks($i)->toDateString();
        }
        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'branch_id'         => 1,
            'student_id'        => $studentId,
            'teacher_id'        => $teacherId,
            'subject'           => 'Math',
            'class_type'        => 'one_on_one',
            'total_classes'     => 4,
            'confirmed_dates'   => [],
            'future_dates'      => $futureDates,
            'days_of_week'      => [3],
            'duration_minutes'  => 120,
            'price_per_session' => 500,
            'payment_type'      => 'session',
            'start_time'        => '16:00',
        ])->assertCreated();
        return (int) DB::table('StudentClass')
            ->where('StudentID', $studentId)
            ->where('TeacherID', $teacherId)
            ->max('ID');
    }

    private function patchSession(string $token, int $sessionId, array $data)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->patchJson("/api/v1/class-sessions/{$sessionId}", $data);
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
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => '老師' . uniqid(),
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0912345678',
            'MustChangePassword' => false,
        ]);
        UserCampus::create([
            'CampusID' => $campusId,
            'UserID' => $user->id,
            'Admin' => 0,
            'Approved' => 1,
        ]);
        return $user->id;
    }

    private function createStudent(int $campusId, string $name): \App\Models\Student
    {
        return \App\Models\Student::create([
            'name' => $name,
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }
}
