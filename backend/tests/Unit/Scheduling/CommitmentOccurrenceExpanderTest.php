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
        $from = Carbon::parse('2026-07-13');
        $occ = $exp->expand([['iso_weekday' => 1, 'start_hm' => '16:00', 'end_hm' => '18:00']], $from, $from->copy()->addDays(28));
        $this->assertCount(5, $occ);
        $this->assertSame('2026-07-13', $occ[0]['date']);
        $this->assertSame('2026-08-10', $occ[4]['date']);
        $this->assertSame('16:00', $occ[0]['start_hm']);
    }

    public function test_respects_contract_end_date(): void
    {
        $exp = new CommitmentOccurrenceExpander();
        $from = Carbon::parse('2026-07-13');
        $occ = $exp->expand([['iso_weekday' => 1, 'start_hm' => '16:00']], $from, $from->copy()->addDays(28), null, Carbon::parse('2026-07-20'));
        $this->assertCount(2, $occ);
    }
}
