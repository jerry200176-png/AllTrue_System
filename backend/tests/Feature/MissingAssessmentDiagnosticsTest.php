<?php

namespace Tests\Feature;

use App\Console\Commands\SchedulerEvidenceSummary;
use App\Services\BusinessDigestService;
use App\Support\AttendanceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** #1078: diagnostic residuals must follow the existing assessment contract. */
class MissingAssessmentDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_digest_counts_required_assessments_and_excludes_nonattendance(): void
    {
        $sessionId = $this->makeSession();
        foreach ($this->statuses() as $status) {
            DB::table('ClassSession')->where('id', $sessionId)->update(['Status' => strtoupper($status)]);
            $expected = in_array($status, AttendanceStatus::requiresLogSessionStatuses(), true) ? 1 : 0;
            $metrics = app(BusinessDigestService::class)->metrics();
            $this->assertSame($expected, $metrics['data_quality']['attended_without_lr'], $status);
        }
    }

    public function test_scheduler_counts_required_assessments_and_excludes_nonattendance(): void
    {
        $sessionId = $this->makeSession();
        $method = new \ReflectionMethod(SchedulerEvidenceSummary::class, 'databaseChecks');
        $method->setAccessible(true);
        foreach ($this->statuses() as $status) {
            DB::table('ClassSession')->where('id', $sessionId)->update(['Status' => strtoupper($status)]);
            $expected = in_array($status, AttendanceStatus::requiresLogSessionStatuses(), true) ? 1 : 0;
            $checks = $method->invoke(app(SchedulerEvidenceSummary::class), now()->toDateString(), []);
            $this->assertSame($expected, $checks['past_attended_sessions_without_learning_record'], $status);
        }
    }

    private function statuses(): array
    {
        return array_unique(array_merge(
            array_column(AttendanceStatus::META, 'session_status'),
            ['completed', 'scheduled', 'cancelled', 'unknown']
        ));
    }

    public function test_diagnostics_preserve_future_active_voided_and_campus_boundaries(): void
    {
        $sessionId = $this->makeSession();
        $method = new \ReflectionMethod(SchedulerEvidenceSummary::class, 'databaseChecks');
        $method->setAccessible(true);
        $counts = function () use ($method): array {
            return [
                app(BusinessDigestService::class)->metrics(1)['data_quality']['attended_without_lr'],
                $method->invoke(app(SchedulerEvidenceSummary::class), now()->toDateString(), [])
                    ['past_attended_sessions_without_learning_record'],
            ];
        };
        $this->assertSame([1, 1], $counts());
        $this->assertSame(0, app(BusinessDigestService::class)->metrics(2)['data_quality']['attended_without_lr']);
        DB::table('ClassSession')->where('id', $sessionId)->update(['SessionDate' => now()->addDay()->toDateString()]);
        $this->assertSame([0, 0], $counts());
        DB::table('ClassSession')->where('id', $sessionId)->update(['SessionDate' => now()->subDay()->toDateString()]);
        $session = DB::table('ClassSession')->where('id', $sessionId)->first();
        $recordId = DB::table('LearningRecord')->insertGetId([
            'StudentClassID' => $session->StudentClassID, 'ClassSessionID' => $sessionId,
            'TeacherID' => 1, 'Content' => '', 'Subject' => 'Synthetic assessment',
            'SessionDate' => $session->SessionDate, 'StartTime' => $session->StartTime,
            'EndTime' => $session->EndTime, 'Status' => 'pending',
        ]);
        $this->assertSame([0, 0], $counts());
        DB::table('LearningRecord')->where('id', $recordId)->update(['VoidedAt' => now()]);
        $before = DB::table('LearningRecord')->where('id', $recordId)->first();
        $this->assertSame([1, 1], $counts());
        $this->assertEquals($before, DB::table('LearningRecord')->where('id', $recordId)->first());
    }

    private function makeSession(): int
    {
        $student = DB::table('Student')->insertGetId([
            'name' => 'Synthetic assessment diagnostic', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
        ]);
        $course = DB::table('StudentClass')->insertGetId([
            'StudentID' => $student, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0,
            'Paid' => 1, 'Rate' => 500, 'ClassType' => 'one_on_one',
            'StartDate' => now()->subDays(30)->toDateTimeString(), 'SessionCount' => 8,
            'SessionDuration' => 60, 'RemainingSessions' => 7, 'UsedSessions' => 1,
            'Stop' => 0, 'ScheduleMode' => 'count',
        ]);
        return (int) DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $course, 'SessionDate' => now()->subDay()->toDateString(),
            'StartTime' => '13:00:00', 'EndTime' => '14:00:00', 'Status' => 'attended',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
