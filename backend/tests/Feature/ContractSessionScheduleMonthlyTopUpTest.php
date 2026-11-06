<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\Scheduling\ContractSessionSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Interface-level characterization of the monthly top-up moved out of StudentClassController (ADR-003 / #966).
 */
class ContractSessionScheduleMonthlyTopUpTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function course(array $over = []): StudentClass
    {
        $student = Student::create(['name' => '月結補堂', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);

        return StudentClass::create($over + [
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 1,
            'StartDate' => '2026-09-01', 'EndDate' => '2026-09-30', 'TotalHours' => 8, 'Charge' => 0, 'Paid' => 0, 'Rate' => 500,
            'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'date', 'SessionDuration' => 60, 'ClassType' => 'one_on_one',
            'week' => 6, 'time' => '22:00:00',
        ]);
    }

    public function test_creates_future_contract_sessions_once_and_is_idempotent(): void
    {
        Carbon::setTestNow('2026-09-10 08:00:00');
        $course = $this->course();

        $first = app(ContractSessionSchedule::class)->ensureMonthlyFutureScheduledSessions($course);
        $second = app(ContractSessionSchedule::class)->ensureMonthlyFutureScheduledSessions($course);

        $this->assertSame(3, $first['created_sessions']); // Sep 12, 19, 26
        $this->assertSame('already_complete', $second['reason']);
        $this->assertSame(3, ClassSession::where('StudentClassID', $course->ID)->count());
    }

    public function test_top_up_never_plans_past_session_count(): void
    {
        // in-app #512: 4 lessons bought, November holds 5 Sundays. The rebuild planned 4; the top-up added the 5th.
        Carbon::setTestNow('2026-11-06 10:00:00');
        $course = $this->course(['StartDate' => '2026-11-01', 'EndDate' => '2026-11-30', 'week' => 7, 'time' => '15:00:00', 'SessionCount' => 4]);
        foreach (['2026-11-01', '2026-11-08', '2026-11-15', '2026-11-22'] as $date) {
            ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => '15:00:00', 'EndTime' => '16:00:00', 'Status' => $date < '2026-11-06' ? 'completed' : 'scheduled']);
        }

        $res = app(ContractSessionSchedule::class)->ensureMonthlyFutureScheduledSessions($course);

        $this->assertSame('session_count_reached', $res['reason']);
        $this->assertSame(4, ClassSession::where('StudentClassID', $course->ID)->count());
    }

    public function test_top_up_fills_only_the_room_left_under_session_count(): void
    {
        Carbon::setTestNow('2026-11-06 10:00:00');
        $course = $this->course(['StartDate' => '2026-11-01', 'EndDate' => '2026-11-30', 'week' => 7, 'time' => '15:00:00', 'SessionCount' => 3]);
        ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-11-01', 'StartTime' => '15:00:00', 'EndTime' => '16:00:00', 'Status' => 'completed']);

        $res = app(ContractSessionSchedule::class)->ensureMonthlyFutureScheduledSessions($course);

        $this->assertSame(2, $res['created_sessions']); // Nov 8, 15 only; 22 and 29 are past the 3 bought
        $this->assertSame(3, ClassSession::where('StudentClassID', $course->ID)->count());
    }

    public function test_skips_non_monthly_and_stopped_courses(): void
    {
        Carbon::setTestNow('2026-09-10 08:00:00');
        $svc = app(ContractSessionSchedule::class);

        $this->assertSame('not_monthly', $svc->ensureMonthlyFutureScheduledSessions($this->course(['ScheduleMode' => 'count']))['reason']);
        $this->assertSame('inactive_course', $svc->ensureMonthlyFutureScheduledSessions($this->course(['Stop' => 1]))['reason']);
    }

    public function test_count_unaligned_future_sessions(): void
    {
        Carbon::setTestNow('2026-09-10 08:00:00');
        $course = $this->course();
        ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-09-12', 'StartTime' => '22:00:00', 'EndTime' => '23:00:00', 'Status' => 'scheduled']);
        ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-09-13', 'StartTime' => '22:00:00', 'EndTime' => '23:00:00', 'Status' => 'scheduled']);

        $this->assertSame(1, app(ContractSessionSchedule::class)->countUnalignedFutureContractSessions($course));
    }
}
