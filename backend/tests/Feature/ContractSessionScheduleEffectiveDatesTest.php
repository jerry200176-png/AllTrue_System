<?php

namespace Tests\Feature;

use App\Services\Scheduling\ContractSessionSchedule;
use Tests\TestCase;

/**
 * Characterization of the cancelled-date rule and date/time normalizers moved out of
 * StudentClassController (ADR-003 / #966). Effective-date walks are covered by CountModeCalendarContractCapTest.
 */
class ContractSessionScheduleEffectiveDatesTest extends TestCase
{
    public function test_cancelled_date_set_ignores_cancelled_row_beside_live_row_in_same_slot(): void
    {
        $rows = [
            (object) ['SessionDate' => '2026-09-05', 'StartTime' => '13:00:00', 'Status' => 'cancelled'],
            (object) ['SessionDate' => '2026-09-05', 'StartTime' => '13:00:00', 'Status' => 'scheduled'],
            (object) ['SessionDate' => '2026-09-12', 'StartTime' => '13:00:00', 'Status' => 'Cancelled'],
        ];

        $this->assertSame(['2026-09-12' => true], ContractSessionSchedule::cancelledDateSet($rows));
    }

    public function test_normalizers(): void
    {
        $this->assertSame(7, ContractSessionSchedule::isoWeekday(0));
        $this->assertSame(3, ContractSessionSchedule::isoWeekday('3'));
        $this->assertNull(ContractSessionSchedule::normalizeDateString(''));
        $this->assertNull(ContractSessionSchedule::normalizeDateString('not a date'));
        $this->assertSame('2026-09-05', ContractSessionSchedule::normalizeDateString('2026-09-05 13:00:00'));
        $this->assertSame('09:05:00', ContractSessionSchedule::normalizeSessionTime('9:05'));
        $this->assertSame('16:00:00', ContractSessionSchedule::normalizeSessionTime(null));
    }

    public function test_monthly_effective_dates_use_weekdays_minus_leave_plus_scheduled(): void
    {
        $class = new \App\Models\StudentClass(['StartDate' => '2026-09-01', 'EndDate' => '2026-09-30', 'week' => 6, 'time' => '13:00:00']);

        $dates = ContractSessionSchedule::computeMonthlyEffectiveSessionDates(
            $class, '2026-09-01', '2026-09-30', ['2026-09-12' => true], ['2026-09-16' => true], []
        );

        $this->assertSame(['2026-09-05', '2026-09-16', '2026-09-19', '2026-09-26'], $dates);
    }

    public function test_resolve_slots_from_columns_dedupes_and_orders(): void
    {
        $class = new \App\Models\StudentClass([
            'week' => 6, 'time' => '17:00:00', 'week1' => 6, 'time1' => '17:00:00', 'week2' => 2, 'time2' => '09:00', 'SessionDuration' => 90,
        ]);

        $this->assertSame([
            ['weekday' => 2, 'time' => '09:00', 'duration_minutes' => 90],
            ['weekday' => 6, 'time' => '17:00', 'duration_minutes' => 90],
        ], ContractSessionSchedule::resolveScheduleSlotsForRebuild($class));
    }
}
