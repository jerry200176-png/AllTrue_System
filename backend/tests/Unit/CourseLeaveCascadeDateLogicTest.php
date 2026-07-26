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


    public function test_count_based_leave_keeps_existing_future_session_dates(): void
    {
        $sessions = [
            ['id' => 1, 'date' => '2026-06-16', 'status' => 'attended'],
            ['id' => 2, 'date' => '2026-07-14', 'status' => 'attended'],
            ['id' => 3, 'date' => '2026-07-21', 'status' => 'scheduled'],
            ['id' => 4, 'date' => '2026-07-28', 'status' => 'scheduled'],
            ['id' => 5, 'date' => '2026-08-04', 'status' => 'scheduled'],
            ['id' => 6, 'date' => '2026-08-11', 'status' => 'scheduled'],
            ['id' => 7, 'date' => '2026-08-18', 'status' => 'scheduled'],
            ['id' => 8, 'date' => '2026-08-25', 'status' => 'scheduled'],
        ];
        $plan = CourseLeaveCascadeService::computeAppendOnlyPlan($sessions, '2026-07-21', [2], 3, 8);
        $this->assertSame([], $plan['vacated']);
        $this->assertSame([], $plan['moves']);
        $this->assertSame('2026-09-01', $plan['append']);
        $this->assertSame(1, $plan['append_count']);
    }

    public function test_explicit_course_pause_shifts_future_sessions_and_vacates_next_recurrence(): void
    {
        // Explicit SHIFT/pause policy only — not ordinary leave (Founder Decision 2026-07-26 / §R82).
        $sessions = [
            ['id' => 1, 'date' => '2026-05-30', 'status' => 'attended'],
            ['id' => 2, 'date' => '2026-06-06', 'status' => 'attended'],
            ['id' => 3, 'date' => '2026-06-13', 'status' => 'attended'],
            ['id' => 4, 'date' => '2026-06-20', 'status' => 'attended'],
            ['id' => 5, 'date' => '2026-06-27', 'status' => 'scheduled'],
            ['id' => 6, 'date' => '2026-07-04', 'status' => 'scheduled'],
            ['id' => 7, 'date' => '2026-07-11', 'status' => 'scheduled'],
            ['id' => 8, 'date' => '2026-07-18', 'status' => 'scheduled'],
        ];
        $plan = CourseLeaveCascadeService::computeShiftPlan($sessions, '2026-06-27', [6], 5);

        $this->assertSame(['2026-07-04'], $plan['vacated']);
        $this->assertSame(
            [
                ['from' => '2026-07-04', 'to' => '2026-07-11', 'id' => 6],
                ['from' => '2026-07-11', 'to' => '2026-07-18', 'id' => 7],
                ['from' => '2026-07-18', 'to' => '2026-07-25', 'id' => 8],
            ],
            $plan['moves']
        );
        $this->assertSame('2026-08-01', $plan['append']);
        $this->assertSame('2026-08-01', $plan['extended_end_date']);
    }

    public function test_explicit_pause_second_shift_vacates_following_saturday(): void
    {
        // After first leave on 06/27, layout is leave + shifted Saturdays; second leave on 07/11 vacates 07/18.
        $sessions = [
            ['id' => 1, 'date' => '2026-05-30', 'status' => 'attended'],
            ['id' => 2, 'date' => '2026-06-06', 'status' => 'attended'],
            ['id' => 3, 'date' => '2026-06-13', 'status' => 'attended'],
            ['id' => 4, 'date' => '2026-06-20', 'status' => 'attended'],
            ['id' => 5, 'date' => '2026-06-27', 'status' => 'leave'],
            ['id' => 6, 'date' => '2026-07-11', 'status' => 'scheduled'],
            ['id' => 7, 'date' => '2026-07-18', 'status' => 'scheduled'],
            ['id' => 8, 'date' => '2026-07-25', 'status' => 'scheduled'],
            ['id' => 9, 'date' => '2026-08-01', 'status' => 'scheduled'],
        ];
        $plan = CourseLeaveCascadeService::computeShiftPlan($sessions, '2026-07-11', [6], 6);

        $this->assertSame(['2026-07-18'], $plan['vacated']);
        $this->assertSame('2026-08-15', $plan['append']);
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
