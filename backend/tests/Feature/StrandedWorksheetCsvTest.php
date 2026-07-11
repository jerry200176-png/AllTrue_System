<?php

namespace Tests\Feature;

use App\Console\Commands\AuditStrandedPaidSessions;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #1152: the `sessions:audit-stranded --csv` prepaid dormant-liability worksheet must
 * classify each stranded count-mode course into active / dormant / no_schedule so a
 * director can decide per course (default policy: preserve balances).
 */
class StrandedWorksheetCsvTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_worksheet_segments_and_totals(): void
    {
        Carbon::setTestNow('2026-07-11 08:00:00');

        $student = Student::create([
            'name' => '休眠測試生', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);

        // active: recent session (6 days ago), weekday set, remaining balance, no future session.
        $active = $this->course($student->id, ['week' => 5, 'Rate' => 1000, 'RemainingSessions' => 4]);
        $this->insertSession($active->ID, '2026-07-05');

        // dormant: last session 71 days ago, weekday set.
        $dormant = $this->course($student->id, ['week' => 5, 'Rate' => 1000, 'RemainingSessions' => 3]);
        $this->insertSession($dormant->ID, '2026-05-01');

        // no_schedule: never materialized, no weekday, old enrollment.
        $noSchedule = $this->course($student->id, ['week' => 0, 'Rate' => 2000, 'RemainingSessions' => 5, 'StartDate' => '2026-01-01']);

        $data = app(AuditStrandedPaidSessions::class)->worksheetRows(0, '2026-07-11');

        // amount = 4*1000 + 3*1000 + 5*2000 = 17,000
        $this->assertSame(17000, $data['total']);
        $this->assertSame(['active' => 1, 'dormant' => 1, 'no_schedule' => 1], $data['seg']);
        $this->assertCount(3, $data['rows']);

        // segment (last column, index 10) per course id (first column, index 0)
        $byId = [];
        foreach ($data['rows'] as $row) {
            $byId[$row[0]] = $row[10];
        }
        $this->assertSame('active', $byId[$active->ID]);
        $this->assertSame('dormant', $byId[$dormant->ID]);
        $this->assertSame('no_schedule', $byId[$noSchedule->ID]);

        Carbon::setTestNow();
    }

    private function course(int $studentId, array $overrides): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99,
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-03-01', 'TotalHours' => 20,
            'Charge' => 0, 'Paid' => 0, 'Rate' => 1000, 'MDate' => now(), 'Stop' => 0,
            'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 120,
            'RemainingSessions' => 4, 'UsedSessions' => 0, 'ClassType' => 'one_on_one',
        ], $overrides));
    }

    private function insertSession(int $courseId, string $date): void
    {
        ClassSession::create([
            'StudentClassID' => $courseId, 'SessionDate' => $date,
            'StartTime' => '16:00:00', 'EndTime' => '18:00:00', 'Status' => 'attended', 'Note' => '',
        ]);
    }
}
