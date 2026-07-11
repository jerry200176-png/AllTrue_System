<?php

namespace Tests\Unit;

use App\Services\CourseLeaveCascadeService;
use Carbon\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * #991: pure-logic unit coverage for the recurrence-date helpers that underpin
 * the leave-cascade (#1160) and contract-realign (#1163) reflows. No DB / app
 * bootstrap — just Carbon + arrays — so these run in the Unit layer and pin the
 * exact "next/prev free recurring slot" semantics the vacate-ahead moves rely on.
 */
class CourseLeaveCascadeDateLogicTest extends TestCase
{
    public function test_next_recurring_date_single_weekday(): void
    {
        // 2026-07-17 is a Friday (ISO 5).
        $this->assertSame(
            '2026-07-24',
            CourseLeaveCascadeService::nextRecurringDate(Carbon::parse('2026-07-17'), [5], [])
        );
    }

    public function test_next_recurring_date_skips_occupied(): void
    {
        $this->assertSame(
            '2026-07-31',
            CourseLeaveCascadeService::nextRecurringDate(
                Carbon::parse('2026-07-17'),
                [5],
                ['2026-07-24' => true]
            )
        );
    }

    public function test_next_recurring_date_multiple_weekdays_picks_earliest(): void
    {
        // After Mon 2026-07-13, weekdays Tue(2)+Thu(4) -> next is Tue 07-14, then Thu 07-16.
        $this->assertSame(
            '2026-07-14',
            CourseLeaveCascadeService::nextRecurringDate(Carbon::parse('2026-07-13'), [2, 4], [])
        );
        $this->assertSame(
            '2026-07-16',
            CourseLeaveCascadeService::nextRecurringDate(Carbon::parse('2026-07-14'), [2, 4], [])
        );
    }

    public function test_prev_recurring_date_goes_backward_and_skips_occupied(): void
    {
        $this->assertSame(
            '2026-07-24',
            CourseLeaveCascadeService::prevRecurringDate(Carbon::parse('2026-07-31'), [5], [], '2026-07-01')
        );
        $this->assertSame(
            '2026-07-17',
            CourseLeaveCascadeService::prevRecurringDate(
                Carbon::parse('2026-07-31'),
                [5],
                ['2026-07-24' => true],
                '2026-07-01'
            )
        );
    }

    public function test_prev_recurring_date_throws_when_blocked_by_min_exclusive(): void
    {
        // Only candidate (07-24) is at/under the exclusive floor -> no valid earlier slot.
        $this->expectException(InvalidArgumentException::class);
        CourseLeaveCascadeService::prevRecurringDate(Carbon::parse('2026-07-31'), [5], [], '2026-07-24');
    }

    public function test_max_date_key(): void
    {
        $this->assertNull(CourseLeaveCascadeService::maxDateKey([]));
        $this->assertSame(
            '2026-07-30',
            CourseLeaveCascadeService::maxDateKey([
                '2026-07-01' => true,
                '2026-07-30' => true,
                '2026-07-15' => true,
            ])
        );
    }

    public function test_append_note(): void
    {
        $this->assertSame('leave', CourseLeaveCascadeService::appendNote('', 'leave'));
        $this->assertSame('leave', CourseLeaveCascadeService::appendNote(null, 'leave'));
        $this->assertSame('foo; leave', CourseLeaveCascadeService::appendNote('foo', 'leave'));
        // Idempotent: a suffix already present is not appended again.
        $this->assertSame('foo; leave', CourseLeaveCascadeService::appendNote('foo; leave', 'leave'));
    }
}
