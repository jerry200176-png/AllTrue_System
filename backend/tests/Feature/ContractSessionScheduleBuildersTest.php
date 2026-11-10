<?php

namespace Tests\Feature;

use App\Services\Scheduling\ContractSessionSchedule;
use Tests\TestCase;

/**
 * Characterization of the count-mode builder and cancelled-date rule moved out of
 * StudentClassController (ADR-003 / #966). Pure functions: no DB rows needed.
 */
class ContractSessionScheduleBuildersTest extends TestCase
{
    public function test_build_for_count_takes_start_date_first_even_off_weekday_then_follows_slots(): void
    {
        // 2026-09-02 is a Wednesday; contract is Saturday 17:00 + 13:00;
        // an off-weekday opening date takes the first listed slot's time.
        $s = ContractSessionSchedule::buildSessionsForCount(7, '2026-09-02', 4, [
            ['weekday' => 6, 'time' => '17:00'],
            ['weekday' => 6, 'time' => '13:00'],
        ], 60);

        $this->assertSame(
            ['2026-09-02|17:00:00', '2026-09-05|13:00:00', '2026-09-05|17:00:00', '2026-09-12|13:00:00'],
            array_map(fn ($r) => $r['SessionDate'] . '|' . $r['StartTime'], $s)
        );
        $this->assertSame('14:00:00', $s[1]['EndTime']);
        $this->assertSame(7, $s[0]['StudentClassID']);
    }

    public function test_build_for_count_treats_js_zero_sunday_like_iso_seven(): void
    {
        // #4994 shrunk counterexample: JS 0 used to be dropped, giving 1 row instead of 2.
        $dates = fn (array $slots) => array_map(
            fn ($r) => $r['SessionDate'],
            ContractSessionSchedule::buildSessionsForCount(1, '2026-01-05', 2, $slots, 60)
        );
        $this->assertSame(['2026-01-05', '2026-01-11'], $dates([['weekday' => 7, 'time' => '09:00']]));
        $this->assertSame(['2026-01-05', '2026-01-11'], $dates([['weekday' => 0, 'time' => '09:00']]));
        $this->assertSame(['2026-01-05', '2026-01-11'], $dates([['weekday' => '0', 'time' => '09:00']]));
    }

    public function test_build_for_count_empty_for_no_slots_or_zero_count(): void
    {
        $this->assertSame([], ContractSessionSchedule::buildSessionsForCount(1, '2026-09-02', 0, [['weekday' => 1, 'time' => '10:00']], 60));
        $this->assertSame([], ContractSessionSchedule::buildSessionsForCount(1, '2026-09-02', 3, [], 60));
    }
}
