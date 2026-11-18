<?php

namespace App\Services\Billing;

use Brick\Math\BigRational;
use Brick\Math\Exception\MathException;
use InvalidArgumentException;

/** Pure allocation math; callers supply authorized, locked, fully paid source and unfunded target snapshots. */
final class ExistingCourseCreditAllocation
{
    /**
     * @param list<array<string,mixed>> $targets unfunded contract snapshots; terms are checked before arithmetic
     * @param list<mixed> $releasedIds explicitly confirmed future reservation releases
     * @param list<mixed> $eligibleIds verified unrecorded future reservations, a subset of the reserved units
     * @return array{source_sessions:int,source_used:int,source_remaining:int,source_amount:int,allocations:list<array{id:int,sessions:int,used:int,amount:int}>,released_session_ids:list<int>}
     */
    public static function plan(int $purchased, int $used, int $reserved, string $unitPrice, int $lessonMinutes,
        array $targets, array $releasedIds, array $eligibleIds): array
    {
        return self::calculate($purchased, $used, $reserved, $unitPrice, $lessonMinutes, $targets, $releasedIds, $eligibleIds);
    }

    /** Read-only suggestion: eligible IDs arrive in the verified future-tail order. Writes still require plan(). */
    public static function propose(int $purchased, int $used, int $reserved, string $unitPrice, int $lessonMinutes,
        array $targets, array $eligibleIds): array
    {
        return self::calculate($purchased, $used, $reserved, $unitPrice, $lessonMinutes, $targets, null, $eligibleIds);
    }

    private static function calculate(int $purchased, int $used, int $reserved, string $unitPrice, int $lessonMinutes,
        array $targets, ?array $releasedIds, array $eligibleIds): array
    {
        if ($purchased < 1 || $used < 0 || $used > $purchased || $reserved < 0 || $reserved > $purchased - $used
            || $lessonMinutes < 1 || $targets === []) self::refuse();
        $rate = self::rate($unitPrice);
        $allocations = []; $ids = []; $units = 0;
        foreach ($targets as $rawTarget) {
            $target = self::terms($rawTarget);
            if ($target['id'] < 1 || isset($ids[$target['id']]) || $target['sessions'] < 1
                || $target['used'] < 0 || $target['used'] > $target['sessions'] || $target['lesson_minutes'] !== $lessonMinutes
                || !$rate->isEqualTo(self::rate($target['unit_price']))) self::refuse();
            $amount = self::amount($rate, $target['sessions']);
            if ($target['charge'] !== $amount || $target['sessions'] > $purchased - $used - $units) self::refuse();
            $units += $target['sessions']; $ids[$target['id']] = true;
            $allocations[] = ['id' => $target['id'], 'sessions' => $target['sessions'], 'used' => $target['used'], 'amount' => $amount];
        }
        $needed = max(0, $units - ($purchased - $used - $reserved));
        $orderedEligibleIds = $eligibleIds; $eligibleIds = self::ids($eligibleIds);
        $releasedIds = self::ids($releasedIds ?? array_slice($orderedEligibleIds, 0, $needed));
        if (count($eligibleIds) > $reserved || count($releasedIds) !== $needed || array_diff($releasedIds, $eligibleIds) !== []) self::refuse();
        usort($allocations, fn (array $a, array $b) => $a['id'] <=> $b['id']);
        // Existing target quantities and taught history stay intact: funding is moved, no new target lessons are created.
        $kept = $purchased - $units;
        $keptAmount = self::amount($rate, $kept);
        if ($keptAmount + array_sum(array_column($allocations, 'amount')) !== self::amount($rate, $purchased)) self::refuse();
        return ['source_sessions' => $kept, 'source_used' => $used, 'source_remaining' => $kept - $used,
            'source_amount' => $keptAmount, 'allocations' => $allocations, 'released_session_ids' => $releasedIds];
    }

    private static function rate(string $value): BigRational
    {
        try {
            $rate = BigRational::of($value);
            if ($rate->isLessThanOrEqualTo(0)) self::refuse();
            return $rate;
        } catch (MathException) { self::refuse(); }
    }

    private static function amount(BigRational $rate, int $sessions): int
    {
        try { return $rate->multipliedBy($sessions)->toBigInteger()->toInt(); }
        catch (MathException) { self::refuse(); }
    }

    /** @param array<string,mixed> $target @return array{id:int,sessions:int,used:int,unit_price:string,lesson_minutes:int,charge:int} */
    private static function terms(array $target): array
    {
        $id = $target['id'] ?? null; $sessions = $target['sessions'] ?? null; $used = $target['used'] ?? null;
        $price = $target['unit_price'] ?? null; $minutes = $target['lesson_minutes'] ?? null; $charge = $target['charge'] ?? null;
        if (!is_int($id) || !is_int($sessions) || !is_int($used) || !is_string($price) || !is_int($minutes) || !is_int($charge)) self::refuse();
        return ['id' => $id, 'sessions' => $sessions, 'used' => $used, 'unit_price' => $price, 'lesson_minutes' => $minutes, 'charge' => $charge];
    }

    /** @param list<mixed> $ids @return list<int> */
    private static function ids(array $ids): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) self::refuse();
            $normalized[] = $id;
        }
        if (count($normalized) !== count(array_unique($normalized))) self::refuse();
        sort($normalized, SORT_NUMERIC);
        return $normalized;
    }

    private static function refuse(): never
    {
        throw new InvalidArgumentException('Existing-course credit allocation does not conserve its validated obligations or reservations.');
    }
}
