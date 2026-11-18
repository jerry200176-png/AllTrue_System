<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\Scheduling\ContractSessionSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** In-app #524 (issue #5133): a start-date edit on a fully-planned count course planned lessons it never bought. */
class ExtendSessionsEndedUnmarkedLessonsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-08 16:30:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function course(string $startDate, array $lessonDates): StudentClass
    {
        $student = Student::create(['name' => 'T', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $course = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 2,
            'StartDate' => $startDate, 'TotalHours' => 4, 'Charge' => 0, 'Paid' => 0, 'Rate' => 500, 'MDate' => now(), 'Stop' => 0,
            'ScheduleMode' => 'count', 'SessionCount' => 2, 'SessionDuration' => 120, 'RemainingSessions' => 2, 'UsedSessions' => 0,
            'ClassType' => 'one_on_one', 'week' => 3, 'time' => '19:00:00',
        ]);
        foreach ($lessonDates as $date) {
            ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => '19:00:00', 'EndTime' => '21:00:00', 'Status' => 'scheduled']);
        }

        return $course;
    }

    public function test_ended_unmarked_lessons_are_not_swapped_for_lessons_at_the_new_start_date(): void
    {
        $course = $this->course('2027-04-07', ['2026-09-02', '2026-09-09']);

        app(ContractSessionSchedule::class)->extendSessionsIfNeeded($course, 2);

        $rows = ClassSession::where('StudentClassID', $course->ID)->orderBy('SessionDate')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['scheduled', 'scheduled'], $rows->pluck('Status')->all());
    }

    public function test_one_future_lesson_off_the_new_start_date_is_replaced_by_exactly_one(): void
    {
        $course = $this->course('2026-12-02', ['2026-11-18', '2026-11-25']);
        $course->update(['SessionCount' => 2]);

        app(ContractSessionSchedule::class)->extendSessionsIfNeeded($course, 2);

        $live = ClassSession::where('StudentClassID', $course->ID)->where('Status', '!=', 'cancelled')->count();
        $this->assertSame(2, $live);
    }
}
