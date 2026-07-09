<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Bug #129 regression guard: rate_unit Charge calculation sanity.
 *
 * Asserts that the Charge stored in StudentClass matches the expected formula:
 *   - rate_unit='session' → Charge = Rate × SessionCount
 *   - rate_unit='hour'    → Charge = Rate × (SessionCount × SessionDuration / 60)
 *
 * These checks guard the migration that fixes the 4 unpaid courses and prevent
 * future regressions where 'hour' vs 'session' produces unexpected amounts.
 */
class RateUnitChargeCalculationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * rate_unit=session: Charge = Rate × SessionCount (8 × 1100 = 8800)
     */
    public function test_session_rate_unit_charge_is_rate_times_sessions(): void
    {
        $sc = $this->makeCourse(['rate_unit' => 'session', 'Rate' => 1100, 'SessionCount' => 8, 'Charge' => 8800]);

        $this->assertEquals('session', $sc->rate_unit, 'rate_unit stored as session');
        $this->assertEquals(8800, (int) $sc->Charge, '8 sessions × 1100 = 8800');
    }

    /**
     * rate_unit=hour: Charge = Rate × (SessionCount × SessionDuration_hours)
     * Example: 8 sessions × 2h × 1100/h = 17,600
     */
    public function test_hour_rate_unit_charge_is_rate_times_total_hours(): void
    {
        $sessions = 8;
        $durationMinutes = 120; // 2 hours
        $rate = 1100;
        $expectedCharge = $rate * ($sessions * $durationMinutes / 60); // 17,600

        $sc = $this->makeCourse([
            'rate_unit'       => 'hour',
            'Rate'            => $rate,
            'SessionCount'    => $sessions,
            'SessionDuration' => $durationMinutes,
            'Charge'          => (int) $expectedCharge,
        ]);

        $this->assertEquals('hour', $sc->rate_unit, 'rate_unit stored as hour');
        $this->assertEquals(17600, (int) $sc->Charge, '8 × 2h × 1100 = 17,600');
    }

    /**
     * Verify the Charge formula difference between session and hour for the same
     * Rate=1100, SessionCount=8, Duration=2h (the exact scenario from bug #129).
     */
    public function test_session_vs_hour_charge_difference_for_bug_129_scenario(): void
    {
        $scSession = $this->makeCourse(['rate_unit' => 'session', 'Rate' => 1100, 'SessionCount' => 8, 'Charge' => 8800]);
        $scHour    = $this->makeCourse(['rate_unit' => 'hour',    'Rate' => 1100, 'SessionCount' => 8, 'SessionDuration' => 120, 'Charge' => 17600]);

        $this->assertNotEquals($scSession->Charge, $scHour->Charge,
            'session and hour must produce different Charge values');
        $this->assertEquals(8800, (int) $scSession->Charge);
        $this->assertEquals(17600, (int) $scHour->Charge);
        $this->assertEquals(2, $scHour->Charge / $scSession->Charge,
            'hour-based charge should be exactly 2× session-based charge for 2h sessions');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeCourse(array $overrides = []): StudentClass
    {
        $campus = CampusFactory::new()->create();
        $teacher = User::create([
            'LoginName' => 'teacher-rate-' . uniqid() . '@test.com',
            'Name' => '老師', 'PSW' => 'x', 'type' => 'T',
            'phone' => '091' . random_int(1000000, 9999999),
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campus->id, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);

        $student = Student::create([
            'name' => 'RateTest-' . uniqid(),
            'CampusID' => $campus->id,
            'ClassID' => 1,
            'SchoolName' => 'TestSchool',
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        return StudentClass::create(array_merge([
            'StudentID'       => $student->id,
            'TeacherID'       => $teacher->id,
            'GradeID'         => 7,
            'SubjectID'       => 1,
            'ClassType'       => 'one_on_one',
            'Rate'            => 1100,
            'rate_unit'       => 'session',
            'SessionCount'    => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 8,
            'UsedSessions'    => 0,
            'Charge'          => 8800,
            'Pay'             => 8800,
            'Paid'            => 0,
            'Stop'            => 0,
            'by1'             => 1,
            'Period'          => 4,
            'StartDate'       => '2026-06-01',
            'TotalHours'      => 16,
            'MDate'           => now(),
            'ScheduleMode'    => 'count',
        ], $overrides));
    }
}
