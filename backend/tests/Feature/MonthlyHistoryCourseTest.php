<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * b3：月結制課程停用後應進入歷史課程區。
 *
 * 修復前行為：togglePause 對月結課（ScheduleMode='date'）不自動寫入 closed_reason，
 * 導致前端 effectiveClosedReason 回傳 null，課程永遠不在歷史區。
 *
 * 修復後行為：月結課停用時自動補 closed_reason='completed'（reason 未傳時）。
 */
class MonthlyHistoryCourseTest extends TestCase
{
    use RefreshDatabase;

    public function test_pause_monthly_course_auto_sets_closed_reason_completed(): void
    {
        $token = $this->createDirectorToken([1], 'director-monthly-pause@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'     => 'date',
            'SessionCount'     => 0,
            'RemainingSessions' => 0,
            'settlement_day'   => 15,
            'monthly_sessions' => 8,
            'Paid'             => 1,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/pause", [
            'action' => 'pause',
        ]);

        $res->assertOk();

        $course->refresh();
        $this->assertSame(1, (int) $course->Stop);
        $this->assertSame('completed', (string) $course->closed_reason,
            '月結課停用後應自動寫入 closed_reason=completed');
    }

    public function test_pause_monthly_course_with_explicit_reason_is_not_overridden(): void
    {
        $token = $this->createDirectorToken([1], 'director-monthly-settled@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'     => 'date',
            'settlement_day'   => 10,
            'monthly_sessions' => 4,
            'Paid'             => 1,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/pause", [
            'action' => 'pause',
            'reason' => 'settled',
        ]);

        $res->assertOk();

        $course->refresh();
        $this->assertSame('settled', (string) $course->closed_reason,
            '明確傳入 reason=settled 時，不應被自動推斷覆蓋');
    }

    public function test_pause_session_mode_unchanged_when_remaining_positive(): void
    {
        $token = $this->createDirectorToken([1], 'director-session-pause@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'     => 'count',
            'SessionCount'     => 8,
            'RemainingSessions' => 5,
            'UsedSessions'     => 3,
            'Paid'             => 1,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/pause", [
            'action' => 'pause',
        ]);

        $res->assertOk();

        $course->refresh();
        $this->assertSame(1, (int) $course->Stop);
        $this->assertNull($course->closed_reason,
            '堂數制課程仍有剩餘堂數時，不應被標為 completed');
    }

    private function createDirectorToken(array $campusIds, string $loginName): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name'      => '主任測試',
            'PSW'       => 'secret',
            'type'      => 'A',
            'phone'     => '0912345678',
        ]);

        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID'   => $user->id,
                'Admin'    => 1,
                'Approved' => 1,
            ]);
        }

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id'    => $user->id,
            'token'      => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }

    private function createStudent(): Student
    {
        return Student::create([
            'name'         => '月結學生',
            'CampusID'     => 1,
            'ClassID'      => 1,
            'enable'       => 1,
            'MDT'          => now(),
            'Notify_Token' => '',
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createStudentClass(int $studentId, array $overrides = []): StudentClass
    {
        $defaults = [
            'StudentID'        => $studentId,
            'GradeID'          => 1,
            'SubjectID'        => 1,
            'TeacherID'        => 99,
            'by1'              => 1,
            'Period'           => 4,
            'StartDate'        => now()->toDateString(),
            'EndDate'          => null,
            'TotalHours'       => 20,
            'Charge'           => 0,
            'Paid'             => 0,
            'Rate'             => 0,
            'RoomID'           => 'R1',
            'MDate'            => now(),
            'Stop'             => 0,
            'ScheduleMode'     => 'count',
            'SessionCount'     => 8,
            'SessionDuration'  => 60,
            'RemainingSessions' => 8,
            'ClassType'        => 'one_on_one',
            'UsedSessions'     => 0,
        ];

        return StudentClass::create(array_merge($defaults, $overrides));
    }
}
