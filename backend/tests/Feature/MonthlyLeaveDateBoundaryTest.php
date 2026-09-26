<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\CourseLeaveCascadeService;
use App\Services\MonthlyBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MonthlyLeaveDateBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_month_end_leave_does_not_append_outside_month_or_extend_contract(): void
    {
        $course = $this->createMonthlyCourse('2026-09-01', '2026-09-30', 5);
        $sessions = $this->createSessions($course, ['2026-09-01', '2026-09-08', '2026-09-15', '2026-09-22', '2026-09-29']);
        $sessions->take(4)->each(function (ClassSession $session): void {
            $session->Status = 'attended';
            $session->save();
        });

        $result = DB::transaction(fn () => CourseLeaveCascadeService::applyLeaveCascade(
            (int) $course->ID,
            '2026-09-29'
        ));

        $course->refresh();
        $this->assertSame('2026-09-30', substr((string) $course->EndDate, 0, 10));
        $this->assertNull($result[1], 'Date-mode leave must not report a changed EndDate.');
        $this->assertSame('2026-09-29', $result[2]);
        $this->assertSame(5, ClassSession::where('StudentClassID', $course->ID)->count());
        $this->assertSame(0, ClassSession::where('StudentClassID', $course->ID)->whereDate('SessionDate', '>', '2026-09-30')->count());
        $this->assertDatabaseHas('ClassSession', ['id' => $sessions[4]->id, 'Status' => 'leave']);

        $preview = CourseLeaveCascadeService::previewLeaveCascadeForCourse($course->ID, '2026-09-29');
        $this->assertNull($preview['append']);
        $this->assertNull($preview['extended_end_date']);
        $this->assertSame('2026-09-30', $preview['contract_end_date']);
        $this->assertTrue($preview['future_dates_unchanged']);
    }

    public function test_monthly_preview_ignores_legacy_shift_policy_and_keeps_billing_boundary(): void
    {
        $course = $this->createMonthlyCourse('2026-09-01', '2026-09-30', 5);
        $this->createSessions($course, ['2026-09-01', '2026-09-08', '2026-09-15', '2026-09-22', '2026-09-29']);

        // The preview endpoint accepts a policy value for the explicit
        // count-based course-pause workflow. It must not let that client value
        // make a date/monthly course plan a cross-period shift or tail.
        $preview = CourseLeaveCascadeService::previewLeaveCascadeForCourse(
            (int) $course->ID,
            '2026-09-29',
            null,
            CourseLeaveCascadeService::POLICY_SHIFT_FUTURE_DATES_APPEND_TAIL
        );

        $this->assertSame(CourseLeaveCascadeService::POLICY_KEEP_FUTURE_DATES_APPEND_TAIL, $preview['policy']);
        $this->assertSame([], $preview['moves']);
        $this->assertSame([], $preview['vacated']);
        $this->assertNull($preview['append']);
        $this->assertNull($preview['extended_end_date']);
        $this->assertSame('2026-09-30', $preview['contract_end_date']);
        $this->assertTrue($preview['future_dates_unchanged']);
    }

    public function test_monthly_undo_restores_only_the_leave_without_a_tail(): void
    {
        $course = $this->createMonthlyCourse('2026-09-01', '2026-09-30', 5);
        $sessions = $this->createSessions($course, ['2026-09-01', '2026-09-08', '2026-09-15', '2026-09-22', '2026-09-29']);

        DB::transaction(fn () => CourseLeaveCascadeService::applyLeaveCascade((int) $course->ID, '2026-09-15'));
        $this->assertSame('leave', strtolower((string) $sessions[2]->fresh()->Status));

        $result = DB::transaction(fn () => CourseLeaveCascadeService::undoLeaveCascade((int) $course->ID, '2026-09-15'));

        $course->refresh();
        $this->assertSame('scheduled', strtolower((string) $sessions[2]->fresh()->Status));
        $this->assertSame('2026-09-30', substr((string) $course->EndDate, 0, 10));
        $this->assertSame('2026-09-30', $result[1]);
        $this->assertSame(5, ClassSession::where('StudentClassID', $course->ID)->count());
        $this->assertSame(0, ClassSession::where('StudentClassID', $course->ID)->whereDate('SessionDate', '>', '2026-09-30')->count());
    }

    public function test_explicit_shift_helper_cannot_append_or_move_a_monthly_course(): void
    {
        $course = $this->createMonthlyCourse('2026-09-01', '2026-09-30', 5);
        $sessions = $this->createSessions($course, ['2026-09-01', '2026-09-08', '2026-09-15', '2026-09-22', '2026-09-29']);
        $leaveSession = $sessions[4];
        $leaveSession->Status = 'leave';
        $leaveSession->save();

        [$rows, $extendedEndDate] = DB::transaction(fn () => CourseLeaveCascadeService::shiftAndAppendAfterLeave(
            (int) $course->ID,
            '2026-09-29',
            $leaveSession
        ));

        $this->assertNull($extendedEndDate);
        $this->assertCount(5, $rows);
        $this->assertSame(
            ['2026-09-01', '2026-09-08', '2026-09-15', '2026-09-22', '2026-09-29'],
            ClassSession::where('StudentClassID', $course->ID)->orderBy('SessionDate')->pluck('SessionDate')->map(fn ($date) => substr((string) $date, 0, 10))->all()
        );
        $this->assertSame(0, ClassSession::where('StudentClassID', $course->ID)->whereDate('SessionDate', '>', '2026-09-30')->count());
    }

    public function test_last_session_leave_only_changes_that_session(): void
    {
        $course = $this->createMonthlyCourse('2026-09-01', '2026-09-30', 5);
        $sessions = $this->createSessions($course, ['2026-09-02', '2026-09-09', '2026-09-16', '2026-09-23', '2026-09-30']);

        DB::transaction(fn () => CourseLeaveCascadeService::applyLeaveCascade(
            (int) $course->ID,
            '2026-09-30'
        ));

        $course->refresh();
        $this->assertSame('2026-09-30', substr((string) $course->EndDate, 0, 10));
        $this->assertSame('leave', strtolower((string) $sessions[4]->fresh()->Status));
        $this->assertSame(5, ClassSession::where('StudentClassID', $course->ID)->count());
        $this->assertSame(0, ClassSession::where('StudentClassID', $course->ID)->whereDate('SessionDate', '>', '2026-09-30')->count());
    }

    public function test_multiple_monthly_leaves_reduce_actual_sessions_without_creating_makeups(): void
    {
        $course = $this->createMonthlyCourse('2026-09-01', '2026-09-30', 4);
        $sessions = $this->createSessions($course, ['2026-09-03', '2026-09-10', '2026-09-17', '2026-09-24']);
        $sessions[2]->Status = 'attended';
        $sessions[2]->save();
        $sessions[3]->Status = 'attended';
        $sessions[3]->save();

        foreach (['2026-09-03', '2026-09-10'] as $leaveDate) {
            DB::transaction(fn () => CourseLeaveCascadeService::applyLeaveCascade((int) $course->ID, $leaveDate));
        }

        $course->refresh();
        $this->assertSame('2026-09-30', substr((string) $course->EndDate, 0, 10));
        $this->assertSame(4, ClassSession::where('StudentClassID', $course->ID)->count());
        $this->assertSame(2, ClassSession::where('StudentClassID', $course->ID)->where('Status', 'leave')->count());
        $this->assertSame(0, ClassSession::where('StudentClassID', $course->ID)->whereDate('SessionDate', '>', '2026-09-30')->count());

        $summary = app(MonthlyBillingService::class)->summarizePeriod($course, '2026-09');
        $this->assertSame(2, $summary['period_sessions']);
        $this->assertSame(2000, $summary['charge']);
    }

    public function test_monthly_billing_ignores_billable_rows_outside_course_interval(): void
    {
        $course = $this->createMonthlyCourse('2026-09-01', '2026-09-28', 0);
        $this->createSessions($course, ['2026-09-27', '2026-09-29'])->each(function (ClassSession $session): void {
            $session->Status = 'attended';
            $session->save();
        });

        $summary = app(MonthlyBillingService::class)->summarizePeriod($course, '2026-09');

        $this->assertSame(1, $summary['period_sessions']);
        $this->assertSame(1000, $summary['charge']);
    }

    private function createMonthlyCourse(string $startDate, string $endDate, int $sessionCount): StudentClass
    {
        $student = Student::create([
            'name' => '月結日期邊界測試',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        return StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => $startDate,
            'EndDate' => $endDate,
            'TotalHours' => 10,
            'Charge' => 5000,
            'Paid' => 0,
            'Rate' => 1000,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'date',
            'SessionCount' => $sessionCount,
            'SessionDuration' => 120,
            'RemainingSessions' => 0,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
            'monthly_sessions' => $sessionCount,
            'rate_unit' => 'session',
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, ClassSession> */
    private function createSessions(StudentClass $course, array $dates)
    {
        return collect($dates)->map(fn (string $date) => ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => $date,
            'StartTime' => '18:00',
            'EndTime' => '20:00',
            'Status' => 'scheduled',
        ]));
    }
}
