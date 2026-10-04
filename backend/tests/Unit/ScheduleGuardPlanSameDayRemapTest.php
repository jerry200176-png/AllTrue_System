<?php

namespace Tests\Unit;

use App\Services\ScheduleGuardService;
use PHPUnit\Framework\TestCase;

class ScheduleGuardPlanSameDayRemapTest extends TestCase
{
    private static function row(int $id, string $start, string $end, bool $ex = false): array
    {
        return ['id' => $id, 'start' => $start, 'end' => $end, 'exception' => $ex];
    }

    private static function slot(string $start, string $end): array
    {
        return ['start' => $start, 'end' => $end];
    }

    /** @return array<int, string> id => target start */
    private static function moves(array $rows, array $slots, array $locked = []): array
    {
        return array_map(fn ($s) => $s['start'], ScheduleGuardService::planSameDayRemap($rows, $slots, $locked)['moves']);
    }

    public function test_unlocked_rows_pair_in_start_order_with_slots_and_excess_stays(): void
    {
        $rows = [self::row(2, '17:00', '18:00'), self::row(1, '15:00', '16:00')];
        self::assertSame([1 => '16:30'], self::moves($rows, [self::slot('16:30', '17:30')]));
        // Slots are sorted too; a row already on its target is not a move.
        self::assertSame([2 => '17:30'], self::moves($rows, [self::slot('17:30', '18:30'), self::slot('15:00', '16:00')]));
    }

    public function test_locked_row_stays_and_consumes_only_an_exactly_matching_slot(): void
    {
        $rows = [self::row(1, '15:00:00', '16:00:00'), self::row(2, '16:00:00', '17:00:00'), self::row(3, '18:00:00', '19:00:00')];
        $slots = [self::slot('15:00:00', '16:00:00'), self::slot('16:30:00', '17:30:00')];
        self::assertSame([2 => '16:30:00'], self::moves($rows, $slots, [1 => true]));
        // Locked far from every slot: consumes nothing, unlocked rows take both slots.
        $rows[0] = self::row(1, '09:00:00', '10:00:00');
        self::assertSame([2 => '15:00:00', 3 => '16:30:00'], self::moves($rows, $slots, [1 => true]));
    }

    public function test_exception_rows_are_adopted_only_on_exact_slot_match(): void
    {
        // Codex P1: locked exception 15:00 on a slot is adopted and consumes it; only 16:00 moves, 18:00 stays.
        $rows = [self::row(1, '15:00', '16:00', true), self::row(2, '16:00', '17:00'), self::row(3, '18:00', '19:00')];
        $slots = [self::slot('15:00', '16:00'), self::slot('17:30', '18:30')];
        $plan = ScheduleGuardService::planSameDayRemap($rows, $slots, [1 => true]);
        self::assertSame([1 => true], $plan['adopted']);
        self::assertSame([2 => '17:30'], array_map(fn ($s) => $s['start'], $plan['moves']));

        // Unlocked adopted exception joins the pairing as a regular row.
        $plan = ScheduleGuardService::planSameDayRemap($rows, $slots, []);
        self::assertSame([1 => true], $plan['adopted']);
        self::assertSame([2 => '17:30'], array_map(fn ($s) => $s['start'], $plan['moves']));

        // Exception off-slot (other duration): not adopted, never paired.
        $rows = [self::row(1, '15:00', '15:30', true), self::row(2, '16:00', '17:00')];
        $plan = ScheduleGuardService::planSameDayRemap($rows, [self::slot('15:00', '16:00')], []);
        self::assertSame([], $plan['adopted']);
        self::assertSame([2 => '15:00'], array_map(fn ($s) => $s['start'], $plan['moves']));
    }

    public function test_duplicate_target_start_is_claimed_once(): void
    {
        $rows = [self::row(1, '15:00', '16:00'), self::row(2, '16:00', '17:00')];
        self::assertSame([1 => '17:00'], self::moves($rows, [self::slot('17:00', '18:00'), self::slot('17:00', '18:00')]));
    }

    public function test_locked_row_consumes_a_same_start_slot_even_with_a_different_duration(): void
    {
        // Locked 15:00-16:00 holds the 15:00 start even for a longer slot: only 16:00 moves (to 18:00), 17:00 stays.
        $rows = [self::row(1, '15:00:00', '16:00:00'), self::row(2, '16:00:00', '17:00:00'), self::row(3, '17:00:00', '18:00:00')];
        self::assertSame([2 => '18:00'], self::moves($rows, [self::slot('15:00', '17:00'), self::slot('18:00', '19:00')], [1 => true]));
    }

    public function test_self_overlaps_report_a_moved_row_landing_on_a_row_that_stays(): void
    {
        // Codex P1: locked 15:30, unlocked 17:00 and 19:00; slots 15:00 and 17:00 → 17:00 moves to 15:00 (19:00 stays).
        $rows = [self::row(1, '15:30:00', '16:30:00'), self::row(2, '17:00:00', '18:00:00'), self::row(3, '19:00:00', '20:00:00')];
        $slots = [self::slot('15:00', '16:00'), self::slot('17:00', '18:00')];
        $moves = ScheduleGuardService::planSameDayRemap($rows, $slots, [1 => true])['moves'];
        self::assertSame([['15:30-16:30', '15:00-16:00']], ScheduleGuardService::planSelfOverlaps($rows, $moves, $slots));

        // Clean remap: unlocked rows shift onto non-overlapping slots.
        $rows = [self::row(1, '15:00', '16:00'), self::row(2, '17:00', '18:00')];
        $slots = [self::slot('15:30', '16:30'), self::slot('17:30', '18:30')];
        $moves = ScheduleGuardService::planSameDayRemap($rows, $slots, [])['moves'];
        self::assertSame([], ScheduleGuardService::planSelfOverlaps($rows, $moves, $slots));

        // A move onto a row that stays is skipped by the sync (unique start), so rows 15:00/17:00 with slot 17:00 are clean.
        $rows = [self::row(1, '15:00', '16:00'), self::row(2, '17:00', '18:00')];
        $moves = ScheduleGuardService::planSameDayRemap($rows, [self::slot('17:00', '18:00')], [])['moves'];
        self::assertSame([], ScheduleGuardService::planSelfOverlaps($rows, $moves, [self::slot('17:00', '18:00')]));

        // A slot no row reaches is still filled by the reflow, so it counts against a non-scheduled row left in place.
        self::assertSame([['17:00-18:00', '17:30-18:30']], ScheduleGuardService::planSelfOverlaps([self::row(9, '17:00', '18:00')], [], [self::slot('17:30', '18:30')]));
    }
}
