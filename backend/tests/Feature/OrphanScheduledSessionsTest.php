<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TD-016 regression: 停用課程（Stop=1）時取消未來 scheduled 堂次
 *
 * 兩個場景：
 * 1. API PUT /student-classes/{id} 設定 Stop=1 → 未來 scheduled 堂次全部取消
 * 2. artisan fix:orphan-scheduled-sessions 能掃描並修復歷史孤兒
 */
class OrphanScheduledSessionsTest extends TestCase
{
    use RefreshDatabase;

    // ── Scenario 1: API Stop=1 立即取消未來堂次 ──────────────────────────────

    public function test_set_stop_via_api_cancels_future_scheduled_sessions(): void
    {
        $token = $this->makeDirectorToken(1, 'td016-dir@example.com');
        $teacherId = $this->makeTeacher(1, 'td016-teacher@example.com');
        $student = $this->makeStudent(1, 'TD016學生');
        $courseId = $this->makeCourse($student->id, $teacherId);

        // 建立：1 筆過去 scheduled（不應被取消）、3 筆未來 scheduled（應被取消）
        $yesterday = now()->subDay()->toDateString();
        $future1   = now()->addDays(7)->toDateString();
        $future2   = now()->addDays(14)->toDateString();
        $future3   = now()->addDays(21)->toDateString();

        ClassSession::insert([
            ['StudentClassID' => $courseId, 'SessionDate' => $yesterday, 'StartTime' => '10:00', 'EndTime' => '12:00', 'Status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()],
            ['StudentClassID' => $courseId, 'SessionDate' => $future1,   'StartTime' => '23:00', 'EndTime' => '23:30', 'Status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()],
            ['StudentClassID' => $courseId, 'SessionDate' => $future2,   'StartTime' => '23:00', 'EndTime' => '23:30', 'Status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()],
            ['StudentClassID' => $courseId, 'SessionDate' => $future3,   'StartTime' => '23:00', 'EndTime' => '23:30', 'Status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->putJson("/api/v1/student-classes/{$courseId}", $this->stopPayload($teacherId))
            ->assertOk();

        // 未來 3 筆應變 cancelled
        $this->assertSame(3, ClassSession::where('StudentClassID', $courseId)
            ->where('Status', 'cancelled')
            ->whereIn('SessionDate', [$future1, $future2, $future3])
            ->count());

        // 過去 1 筆不應被碰（仍是 scheduled 或原狀態）
        $this->assertSame('scheduled', ClassSession::where('StudentClassID', $courseId)
            ->where('SessionDate', $yesterday)
            ->value('Status'));
    }

    public function test_stop_does_not_affect_already_attended_or_cancelled_sessions(): void
    {
        $token = $this->makeDirectorToken(1, 'td016-dir2@example.com');
        $teacherId = $this->makeTeacher(1, 'td016-teacher2@example.com');
        $student = $this->makeStudent(1, 'TD016學生B');
        $courseId = $this->makeCourse($student->id, $teacherId);

        $future = now()->addDays(7)->toDateString();

        ClassSession::insert([
            ['StudentClassID' => $courseId, 'SessionDate' => $future, 'StartTime' => '23:00', 'EndTime' => '23:30', 'Status' => 'attended',  'created_at' => now(), 'updated_at' => now()],
            ['StudentClassID' => $courseId, 'SessionDate' => $future, 'StartTime' => '23:00', 'EndTime' => '23:30', 'Status' => 'cancelled', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->putJson("/api/v1/student-classes/{$courseId}", $this->stopPayload($teacherId))
            ->assertOk();

        // attended / cancelled 不應被改動
        $this->assertSame(1, ClassSession::where('StudentClassID', $courseId)->where('Status', 'attended')->count());
        $this->assertSame(1, ClassSession::where('StudentClassID', $courseId)->where('Status', 'cancelled')->count());
        $this->assertSame(0, ClassSession::where('StudentClassID', $courseId)->where('Status', 'scheduled')->count());
    }

    // ── Scenario 2: artisan command 修復歷史孤兒 ─────────────────────────────

    public function test_artisan_command_dry_run_reports_orphans_without_modifying(): void
    {
        $teacherId = $this->makeTeacher(1, 'td016-teacher3@example.com');
        $student   = $this->makeStudent(1, 'TD016學生C');
        $courseId  = $this->makeStoppedCourse($student->id, $teacherId);

        $future = now()->addDays(7)->toDateString();
        ClassSession::insert([
            ['StudentClassID' => $courseId, 'SessionDate' => $future, 'StartTime' => '23:00', 'EndTime' => '23:30', 'Status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->artisan('fix:orphan-scheduled-sessions', ['--dry-run' => true])
            ->assertExitCode(0);

        // dry-run 不應實際修改
        $this->assertSame('scheduled', ClassSession::where('StudentClassID', $courseId)
            ->where('SessionDate', $future)
            ->value('Status'));
    }

    public function test_artisan_command_execute_cancels_orphan_sessions(): void
    {
        $teacherId = $this->makeTeacher(1, 'td016-teacher4@example.com');
        $student   = $this->makeStudent(1, 'TD016學生D');
        $courseId  = $this->makeStoppedCourse($student->id, $teacherId);

        $future1 = now()->addDays(7)->toDateString();
        $future2 = now()->addDays(14)->toDateString();
        ClassSession::insert([
            ['StudentClassID' => $courseId, 'SessionDate' => $future1, 'StartTime' => '23:00', 'EndTime' => '23:30', 'Status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()],
            ['StudentClassID' => $courseId, 'SessionDate' => $future2, 'StartTime' => '23:00', 'EndTime' => '23:30', 'Status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->artisan('fix:orphan-scheduled-sessions')
            ->assertExitCode(0);

        $this->assertSame(0, ClassSession::where('StudentClassID', $courseId)
            ->where('Status', 'scheduled')
            ->whereDate('SessionDate', '>=', now()->toDateString())
            ->count());

        $this->assertSame(2, ClassSession::where('StudentClassID', $courseId)
            ->where('Status', 'cancelled')
            ->count());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** 完整 PUT payload，附帶 Stop=1，通過後端所有 required 驗證 */
    private function stopPayload(int $teacherId): array
    {
        return [
            'subject'          => 'Math',
            'class_type'       => 'one_on_one',
            'duration_hours'   => 1,
            'payment_type'     => 'session',
            'days_of_week'     => [1],
            'start_time'       => '10:00',
            'day_time_slots'   => [['day' => 1, 'start_time' => '10:00']],
            'sessions_purchased' => 10,
            'remaining_sessions' => 10,
            'Stop'             => 1,
        ];
    }

    private function makeDirectorToken(int $campusId, string $loginName): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => '主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0911000001',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }

    private function makeTeacher(int $campusId, string $loginName): int
    {
        $teacher = User::create([
            'LoginName' => $loginName,
            'Name' => '老師',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0922000001',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        return (int) $teacher->id;
    }

    private function makeStudent(int $campusId, string $name): Student
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

    private function baseStudentClass(int $studentId, int $teacherId, array $overrides = []): int
    {
        return (int) DB::table('StudentClass')->insertGetId(array_merge([
            'StudentID'         => $studentId,
            'TeacherID'         => $teacherId,
            'GradeID'           => 1,
            'SubjectID'         => 1,
            'ClassType'         => 'one_on_one',
            'ScheduleMode'      => 'count',
            'RemainingSessions' => 10,
            'UsedSessions'      => 0,
            'SessionCount'      => 10,
            'SessionDuration'   => 60,
            'TotalHours'        => 10,
            'Rate'              => 500,
            'Charge'            => 5000,
            'Pay'               => 5000,
            'Paid'              => 0,
            'Stop'              => 0,
            'StartDate'         => now()->toDateString(),
            'Period'            => 4,
            'by1'               => $teacherId,
            'RoomID'            => 'R1',
            'MDate'             => now(),
        ], $overrides));
    }

    /** 建立 Stop=0 的課程，回傳 ID */
    private function makeCourse(int $studentId, int $teacherId): int
    {
        return $this->baseStudentClass($studentId, $teacherId);
    }

    /** 建立 Stop=1 的課程（模擬歷史孤兒），回傳 ID */
    private function makeStoppedCourse(int $studentId, int $teacherId): int
    {
        return $this->baseStudentClass($studentId, $teacherId, [
            'RemainingSessions' => 0,
            'UsedSessions'      => 5,
            'SessionCount'      => 5,
            'TotalHours'        => 5,
            'Charge'            => 2500,
            'Pay'               => 2500,
            'Paid'              => 1,
            'Stop'              => 1,
            'StartDate'         => now()->subMonth()->toDateString(),
        ]);
    }
}
