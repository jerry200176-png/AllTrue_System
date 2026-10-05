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
}
