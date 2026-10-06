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
