<?php

namespace Tests\Unit;

use App\Services\Billing\BillingPeriod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** in-app #514: the month label is the first lesson's month, whatever day the cycle starts on. */
class BillingPeriodTest extends TestCase
{
    public static function cycles(): array
    {
        return [
            'starts on the 1st' => [['2026-05-05', '2026-05-12'], '2026-05-01', '2026-05'],
            'starts on the 15th' => [['2026-05-19', '2026-06-02'], '2026-05-15', '2026-05'],
            'starts on the 28th, lessons next month' => [['2026-05-05', '2026-05-12', '2026-05-19', '2026-05-26'], '2026-04-28', '2026-05'],
            'starts on the 29th, lessons next month' => [['2026-05-26', '2026-05-05'], '2026-04-29', '2026-05'],
            'starts on the 30th' => [['2026-06-03'], '2026-04-30', '2026-06'],
            'starts on the 31st' => [['2026-09-02'], '2026-08-31', '2026-09'],
            'lesson on the start day' => [['2026-04-29', '2026-05-06'], '2026-04-29', '2026-04'],
            'lessons span two months: first wins' => [['2026-06-30', '2026-07-07', '2026-07-14'], '2026-06-24', '2026-06'],
            'no lessons falls back to the start' => [[], '2026-04-29', '2026-04'],
            'year boundary' => [['2027-01-05'], '2026-12-29', '2027-01'],
        ];
    }

    #[DataProvider('cycles')]
    public function test_pick(array $dates, string $start, string $expected): void
    {
        $this->assertSame($expected, BillingPeriod::pick($dates, $start));
    }
}
