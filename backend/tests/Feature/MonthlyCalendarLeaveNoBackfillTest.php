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
use Tests\TestCase;

/**
 * #3901 / in-app #393: a monthly (ScheduleMode=date) leave must stick (R136).
 * A same-day read may not bring back a "replacement" lesson on the leave date.
 */
class MonthlyCalendarLeaveNoBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_same_day_teacher_read_does_not_recreate_a_lesson_on_a_monthly_leave_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00', 'Asia/Taipei'));
        [$teacherId, $teacherToken] = $this->createUserWithToken('T', 'teacher-3901-a@example.com');
        $course = $this->createMonthlyCourse($teacherId);
        // The contract slot is Tue 18:00, but this lesson was moved to 19:00 on the
        // same day and then put on leave. The contract time no longer matches it.
        $leave = $this->createSession($course, '2026-10-13', '19:00', '21:00', 'leave');

        $this->withHeaders(['Authorization' => "Bearer {$teacherToken}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/class-sessions?start=2026-10-13&end=2026-10-13')
            ->assertOk();

        $onDate = ClassSession::where('StudentClassID', $course->ID)->whereDate('SessionDate', '2026-10-13')->get();
        $this->assertCount(1, $onDate, 'A monthly leave date must not get an auto-created replacement lesson.');
        $this->assertSame((int) $leave->id, (int) $onDate->first()->id);
        $this->assertSame('leave', strtolower((string) $onDate->first()->Status));
    }

    public function test_same_day_teacher_read_still_fills_the_missing_slot_of_a_two_slot_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00', 'Asia/Taipei'));
        [$teacherId, $teacherToken] = $this->createUserWithToken('T', 'teacher-3901-b@example.com');
        $course = $this->createMonthlyCourse($teacherId, ['week1' => 2, 'time1' => '13:00']);
        // Only the 13:00 contract slot exists (cancelled); the 18:00 slot is still unmaterialized.
        $this->createSession($course, '2026-10-13', '13:00', '15:00', 'cancelled');

        $this->withHeaders(['Authorization' => "Bearer {$teacherToken}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/class-sessions?start=2026-10-13&end=2026-10-13')
            ->assertOk();

        $starts = ClassSession::where('StudentClassID', $course->ID)->whereDate('SessionDate', '2026-10-13')
            ->orderBy('StartTime')->get()->map(fn ($s) => substr((string) $s->StartTime, 0, 5).' '.strtolower((string) $s->Status))->all();
        $this->assertSame(['13:00 cancelled', '18:00 scheduled'], $starts);
    }

    /** @param array<string, mixed> $extra */
    private function createMonthlyCourse(int $teacherId, array $extra = []): StudentClass
    {
        $student = Student::create([
            'name' => '月結請假不遞補', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
            'MDT' => now(), 'Notify_Token' => '',
        ]);

        return StudentClass::create($extra + [
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $teacherId,
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-10-01', 'EndDate' => '2026-10-31',
            'TotalHours' => 8, 'Charge' => 4000, 'Paid' => 0, 'Rate' => 1000, 'MDate' => now(), 'Stop' => 0,
            'ScheduleMode' => 'date', 'SessionCount' => 4, 'SessionDuration' => 120, 'RemainingSessions' => 0,
            'ClassType' => 'one_on_one', 'UsedSessions' => 0, 'monthly_sessions' => 4, 'rate_unit' => 'session',
            'week' => 2, 'time' => '18:00',
        ]);
    }

    private function createSession(StudentClass $course, string $date, string $start, string $end, string $status): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $course->ID, 'SessionDate' => $date,
            'StartTime' => $start, 'EndTime' => $end, 'Status' => $status,
        ]);
    }

    /** @return array{0:int,1:string} */
    private function createUserWithToken(string $type, string $login, int $admin = 0): array
    {
        $user = User::create(['LoginName' => $login, 'Name' => '測試', 'PSW' => 'secret', 'type' => $type,
            'phone' => '0912345678', 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => $admin, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDays(30)]);

        return [(int) $user->id, $token];
    }
}
