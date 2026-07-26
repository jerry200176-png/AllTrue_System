<?php

namespace Tests\Unit;

use App\Services\CourseLeaveCascadeService;
use Carbon\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic coverage for leave date plans.
 *
 * Founder Decision 2026-07-26: ordinary leave = KEEP_FUTURE_DATES_APPEND_TAIL
 * (no vacated week). SHIFT plan remains for explicit pause only.
 */
class CourseLeaveCascadeDateLogicTest extends TestCase
{
    public function test_next_recurring_date_single_weekday(): void
    {
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
        $this->expectException(InvalidArgumentException::class);
        CourseLeaveCascadeService::prevRecurringDate(Carbon::parse('2026-07-31'), [5], [], '2026-07-24');
    }

    public function test_count_based_leave_keeps_existing_future_session_dates(): void
    {
        // User case: Tue leave 07/21 must NOT vacate 07/28.
        $sessions = [
            ['id' => 1, 'date' => '2026-06-16', 'status' => 'attended'],
            ['id' => 2, 'date' => '2026-06-23', 'status' => 'attended'],
            ['id' => 3, 'date' => '2026-07-07', 'status' => 'attended'],
            ['id' => 4, 'date' => '2026-07-14', 'status' => 'attended'],
            ['id' => 5, 'date' => '2026-07-21', 'status' => 'scheduled'],
            ['id' => 6, 'date' => '2026-07-28', 'status' => 'scheduled'],
            ['id' => 7, 'date' => '2026-08-04', 'status' => 'scheduled'],
            ['id' => 8, 'date' => '2026-08-11', 'status' => 'scheduled'],
        ];
        $plan = CourseLeaveCascadeService::computeAppendOnlyPlan($sessions, '2026-07-21', [2], 5, 8);

        $this->assertSame([], $plan['vacated']);
        $this->assertSame([], $plan['moves']);
        $this->assertSame('2026-08-18', $plan['append']);
        $this->assertSame(1, $plan['append_count']);
    }

    public function test_count_based_leave_does_not_create_vacated_week(): void
    {
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
        $plan = CourseLeaveCascadeService::computeAppendOnlyPlan($sessions, '2026-06-27', [6], 5, 8);
        $this->assertSame([], $plan['vacated']);
        $this->assertSame('2026-07-25', $plan['append']);
    }

    public function test_count_based_leave_appends_exactly_one_tail_session(): void
    {
        $sessions = [
            ['id' => 1, 'date' => '2026-07-01', 'status' => 'attended'],
            ['id' => 2, 'date' => '2026-07-08', 'status' => 'scheduled'],
            ['id' => 3, 'date' => '2026-07-15', 'status' => 'scheduled'],
            ['id' => 4, 'date' => '2026-07-22', 'status' => 'scheduled'],
        ];
        $plan = CourseLeaveCascadeService::computeAppendOnlyPlan($sessions, '2026-07-08', [3], 2, 4);
        $this->assertSame(1, $plan['append_count']);
        $this->assertSame('2026-07-29', $plan['append']);
    }

    public function test_count_based_leave_reassigns_billable_ordinals(): void
    {
        $sessions = [
            ['id' => 1, 'date' => '2026-07-07', 'status' => 'attended'],
            ['id' => 2, 'date' => '2026-07-14', 'status' => 'attended'],
            ['id' => 3, 'date' => '2026-07-21', 'status' => 'scheduled'],
            ['id' => 4, 'date' => '2026-07-28', 'status' => 'scheduled'],
            ['id' => 5, 'date' => '2026-08-04', 'status' => 'scheduled'],
        ];
        $next = CourseLeaveCascadeService::resolveNextBillableAfterLeave($sessions, '2026-07-21', false, []);
        $this->assertNotNull($next);
        $this->assertSame('2026-07-28', $next['date']);
        // attended×2 remain ordinals 1–2; 07/28 becomes ordinal 3
        $this->assertSame(3, $next['ordinal']);
    }

    public function test_explicit_course_pause_shifts_future_sessions_and_vacates_next_recurrence(): void
    {
        // Explicit SHIFT policy (pause) — NOT ordinary leave.
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
        $this->assertSame('foo; leave', CourseLeaveCascadeService::appendNote('foo; leave', 'leave'));
    }

    public function test_build_auto_extended_note_includes_leave_provenance(): void
    {
        $note = CourseLeaveCascadeService::buildAutoExtendedNote('2026-07-21', 42);
        $this->assertStringContainsString('auto-extended-after-leave', $note);
        $this->assertStringContainsString('ld=2026-07-21', $note);
        $this->assertStringContainsString('ls=42', $note);
    }
}
