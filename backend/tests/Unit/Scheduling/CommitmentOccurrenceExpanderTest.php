<?php

namespace Tests\Unit\Scheduling;

use App\Services\Scheduling\CommitmentOccurrenceExpander;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class CommitmentOccurrenceExpanderTest extends TestCase
{
    public function test_expands_inclusive_28_day_horizon_for_weekly_slot(): void
    {
        $exp = new CommitmentOccurrenceExpander();
        $from = Carbon::parse('2026-07-13'); // Mon
        $through = $from->copy()->addDays(28);
        $occ = $exp->expand([
            ['iso_weekday' => 1, 'start_hm' => '16:00', 'end_hm' => '18:00'],
        ], $from, $through);

        $this->assertNotEmpty($occ);
        foreach ($occ as $o) {
            $this->assertSame(1, Carbon::parse($o['date'])->dayOfWeekIso);
            $this->assertSame('16:00', $o['start_hm']);
        }
        // 13 Jul .. 10 Aug inclusive → Mondays: 13,20,27,3,10 = 5
        $this->assertCount(5, $occ);
        $this->assertSame('2026-07-13', $occ[0]['date']);
        $this->assertSame('2026-08-10', $occ[4]['date']);
    }

    public function test_respects_contract_end_date(): void
    {
        $exp = new CommitmentOccurrenceExpander();
        $from = Carbon::parse('2026-07-13');
        $through = $from->copy()->addDays(28);
        $occ = $exp->expand(
            [['iso_weekday' => 1, 'start_hm' => '16:00']],
            $from,
            $through,
            null,
            Carbon::parse('2026-07-20'),
        );
        $this->assertCount(2, $occ); // 13 and 20
    }
}
