<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\Scheduling\ContractSessionSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** In-app #506: a weekday edit on a monthly (date) contract must not remap lessons past EndDate. */
class MonthlySessionsStayInPeriodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-08 21:31:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function course(string $mode): int
    {
        $student = Student::create(['name' => 'M506-'.uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $id = (int) StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1, 'ClassType' => 'one_on_one', 'by1' => 1,
            'Period' => 4, 'StartDate' => '2026-10-01', 'EndDate' => '2026-10-31', 'TotalHours' => 8, 'SessionCount' => 4,
            'SessionDuration' => 120, 'RemainingSessions' => 4, 'UsedSessions' => 0, 'Charge' => 0, 'Pay' => 0, 'Paid' => 0,
            'Rate' => 1800, 'Stop' => 0, 'MDate' => now(), 'ScheduleMode' => $mode, 'week' => 7, 'time' => '17:00:00',
        ])->ID;
        foreach (['2026-10-15', '2026-10-22', '2026-10-29'] as $d) { // created on Thursdays, then the weekday became Sunday
            ClassSession::create(['StudentClassID' => $id, 'SessionDate' => $d, 'StartTime' => '17:00:00', 'EndTime' => '19:00:00', 'Status' => 'scheduled', 'IsContractException' => 0]);
        }

        return $id;
    }

    private function dates(int $id): array
    {
        return ClassSession::where('StudentClassID', $id)->orderBy('SessionDate')->get()->map(fn ($s) => substr((string) $s->SessionDate, 0, 10))->all();
    }

    public function test_builder_is_bounded_by_the_period(): void
    {
        $rows = ContractSessionSchedule::buildSessionsFromWeeklySchedule(1, '2026-10-08', '2026-10-31', [['weekday' => 7, 'time' => '17:00']], 120);
        $this->assertSame(['2026-10-11', '2026-10-18', '2026-10-25'], array_column($rows, 'SessionDate'));
    }

    public function test_weekday_remap_never_moves_a_monthly_lesson_past_end_date(): void
    {
        $id = $this->course('date');
        app(ContractSessionSchedule::class)->syncFutureScheduledSessionTimes($id, [['weekday' => 7, 'time' => '17:00', 'duration_minutes' => 120]], 120);
        foreach ($this->dates($id) as $d) {
            $this->assertLessThanOrEqual('2026-10-31', $d, 'no lesson outside the monthly period');
        }
    }

    public function test_removing_a_weekday_never_moves_a_row_onto_a_row_that_stays(): void
    {
        $id = $this->course('date');
        \App\Models\ClassSession::where('StudentClassID', $id)->delete();
        foreach (['2026-10-14', '2026-10-15', '2026-10-21', '2026-10-22', '2026-10-28', '2026-10-29'] as $d) {
            ClassSession::create(['StudentClassID' => $id, 'SessionDate' => $d, 'StartTime' => '17:00:00', 'EndTime' => '19:00:00', 'Status' => 'scheduled', 'IsContractException' => 0]);
        }
        app(ContractSessionSchedule::class)->syncFutureScheduledSessionTimes($id, [['weekday' => 4, 'time' => '17:00', 'duration_minutes' => 120]], 120);
        $dates = $this->dates($id);
        $this->assertCount(6, $dates);
        $this->assertSame($dates, array_values(array_unique($dates)));
        $this->assertLessThanOrEqual('2026-10-31', end($dates));
    }

    public function test_count_mode_remap_is_unchanged(): void
    {
        $id = $this->course('count');
        app(ContractSessionSchedule::class)->syncFutureScheduledSessionTimes($id, [['weekday' => 7, 'time' => '17:00', 'duration_minutes' => 120]], 120);
        $this->assertSame(['2026-10-18', '2026-10-25', '2026-11-01'], $this->dates($id));
    }
}
