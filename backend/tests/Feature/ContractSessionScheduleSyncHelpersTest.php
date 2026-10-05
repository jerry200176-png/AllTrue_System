<?php

namespace Tests\Feature;

use App\Services\Scheduling\ContractSessionSchedule;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Characterization of the sync/remap helpers moved out of StudentClassController (ADR-003 / #966).
 * The DB-backed behaviour is covered by SyncFutureSessionTimesTwoPhaseTest / RealignReflowTwoPhaseTest.
 */
class ContractSessionScheduleSyncHelpersTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_slots_by_weekday_map_sorts_by_time_and_defaults_duration(): void
    {
        $map = ContractSessionSchedule::buildSlotsByWeekdayMap([
            ['weekday' => 6, 'time' => '17:00:00'],
            ['weekday' => 6, 'time' => '13:00', 'duration_minutes' => 90],
            ['weekday' => 9, 'time' => '10:00'],
            ['weekday' => 2, 'time' => ''],
        ], 60);

        $this->assertSame([6 => [['time' => '13:00', 'dur' => 90], ['time' => '17:00', 'dur' => 60]]], $map);
    }

    public function test_snap_prefers_previous_day_on_tie_and_never_goes_before_today(): void
    {
        Carbon::setTestNow('2026-09-01 08:00:00');
        $sat = [6 => [['time' => '13:00', 'dur' => 60]]];

        // Sunday 09-06 -> Saturday 09-05 (previous day, distance 1) rather than 09-12.
        $this->assertSame('2026-09-05', ContractSessionSchedule::snapDateToContractWeekday('2026-09-06', $sat));
        // notBeforeAnchor skips the earlier day.
        $this->assertSame('2026-09-12', ContractSessionSchedule::snapDateToContractWeekday('2026-09-06', $sat, true));
        // Anchor already on a contract weekday and not in the past stays.
        $this->assertSame('2026-09-05', ContractSessionSchedule::snapDateToContractWeekday('2026-09-05', $sat));
    }

    public function test_schedule_fields_present_in_mapped(): void
    {
        $this->assertTrue(ContractSessionSchedule::scheduleFieldsPresentInMapped(['time2' => '10:00']));
        $this->assertTrue(ContractSessionSchedule::scheduleFieldsPresentInMapped(['SessionDuration' => null]));
        $this->assertFalse(ContractSessionSchedule::scheduleFieldsPresentInMapped(['Charge' => 1]));
    }
}
