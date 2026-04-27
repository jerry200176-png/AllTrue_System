<?php

namespace Tests\Feature;

use App\Http\Controllers\StudentClassController;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentClassExtendSessionsGapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-27 08:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_extend_sessions_fills_missing_contract_gap_before_appending_tail_session(): void
    {
        $student = Student::create([
            'name' => '簡采柔',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $course = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-04-01',
            'TotalHours' => 24,
            'Charge' => 0,
            'Paid' => 0,
            'Rate' => 500,
            'RoomID' => '1',
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 12,
            'SessionDuration' => 120,
            'RemainingSessions' => 12,
            'UsedSessions' => 0,
            'ClassType' => 'one_on_one',
            'week' => 3,
            'time' => '18:00:00',
        ]);

        foreach ([
            '2026-04-01', '2026-04-08', '2026-04-15', '2026-04-22',
            // 2026-04-29 is missing; old code appended 2026-06-24 as the 13th visible session.
            '2026-05-06', '2026-05-13', '2026-05-20', '2026-05-27',
            '2026-06-03', '2026-06-10', '2026-06-17', '2026-06-24',
        ] as $date) {
            ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => $date,
                'StartTime' => '18:00:00',
                'EndTime' => '20:00:00',
                'Status' => 'scheduled',
            ]);
        }

        app(StudentClassController::class)->extendSessionsIfNeeded($course, 12);

        $activeDates = ClassSession::where('StudentClassID', $course->ID)
            ->whereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted', 'excused'])
            ->orderBy('SessionDate')
            ->pluck('SessionDate')
            ->map(fn ($date) => substr((string) $date, 0, 10))
            ->all();

        $this->assertSame([
            '2026-04-01', '2026-04-08', '2026-04-15', '2026-04-22',
            '2026-04-29',
            '2026-05-06', '2026-05-13', '2026-05-20', '2026-05-27',
            '2026-06-03', '2026-06-10', '2026-06-17',
        ], $activeDates);

        $this->assertSame(
            'cancelled',
            (string) ClassSession::where('StudentClassID', $course->ID)
                ->whereDate('SessionDate', '2026-06-24')
                ->value('Status')
        );
    }
}
