<?php

namespace Tests\Feature;

use App\Models\StudentClass;
use App\Services\MonthlyBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudentClassPricingAmendmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_session_billing_uses_effective_amendment_without_mutating_course_rate(): void
    {
        $course = $this->course();
        DB::table('ClassSession')->insert([
            ['StudentClassID' => $course->ID, 'SessionDate' => '2026-08-04', 'StartTime' => '13:00:00', 'EndTime' => '15:00:00', 'Status' => 'attended'],
            ['StudentClassID' => $course->ID, 'SessionDate' => '2026-08-05', 'StartTime' => '13:00:00', 'EndTime' => '15:00:00', 'Status' => 'attended'],
        ]);
        DB::table('student_class_pricing_amendments')->insert([
            'student_class_id' => $course->ID,
            'effective_from' => '2026-08-05',
            'rate' => 2750,
            'rate_unit' => 'session',
            'source_reference' => 'test-pricing-amendment',
            'reason' => 'test',
            'created_at' => now(),
        ]);

        $summary = app(MonthlyBillingService::class)->summarizePeriod($course, '2026-08');

        $this->assertSame(2, $summary['period_sessions']);
        $this->assertSame(4950, $summary['charge']);
        $this->assertSame(2200, (int) DB::table('StudentClass')->where('ID', $course->ID)->value('Rate'));
    }

    public function test_voided_amendment_falls_back_to_legacy_rate(): void
    {
        $course = $this->course();
        DB::table('ClassSession')->insert([
            'StudentClassID' => $course->ID, 'SessionDate' => '2026-08-05',
            'StartTime' => '13:00:00', 'EndTime' => '15:00:00', 'Status' => 'attended',
        ]);
        DB::table('student_class_pricing_amendments')->insert([
            'student_class_id' => $course->ID,
            'effective_from' => '2026-08-05',
            'rate' => 2750,
            'rate_unit' => 'session',
            'source_reference' => 'test-pricing-amendment',
            'reason' => 'test',
            'created_at' => now(),
            'voided_at' => now(),
            'voided_by_user_id' => 4,
            'void_reason' => 'test rollback',
        ]);

        $summary = app(MonthlyBillingService::class)->summarizePeriod($course, '2026-08');

        $this->assertSame(2200, $summary['charge']);
    }

    private function course(): StudentClass
    {
        $studentId = DB::table('Student')->insertGetId([
            'name' => 'pricing amendment student', 'CampusID' => 15, 'ClassID' => 1, 'enable' => 1,
        ]);

        return StudentClass::query()->create([
            'ID' => 316,
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 64,
            'TeacherID' => 60,
            'by1' => 1,
            'Period' => 4,
            'TotalHours' => 0,
            'StartDate' => '2026-04-01 00:00:00',
            'EndDate' => '2026-09-30 00:00:00',
            'Charge' => 6600,
            'Rate' => 2200,
            'SessionDuration' => 120,
            'ScheduleMode' => 'date',
            'SessionCount' => 0,
            'UsedSessions' => 0,
            'RemainingSessions' => 0,
            'Stop' => 1,
        ]);
    }
}
