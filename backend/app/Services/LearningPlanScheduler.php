<?php

namespace App\Services;

/**
 * 學習計畫的日期演算法 (#4970 step 4a). Pure and deterministic: no DB, no clock.
 *
 * Rules:
 *  1. Boundaries = plan_start, every exam whose start lies inside [plan_start, plan_end], plan_end. A segment
 *     ends the day before the exam that closes it; the last one ends on plan_end (inclusive). An exam on
 *     plan_start or plan_end is still a boundary; a segment with no room left (zero days) puts its units on
 *     its first day instead of overflowing the exam date.
 *  2. A unit tagged with sequence k goes to the segment closed by the first not-yet-used exam with sequence k
 *     in date order (sequences repeat across terms); consecutive units with the same sequence share it. An
 *     untagged unit inherits the previous unit's sequence (the first segment when there is none). If no unused
 *     exam with sequence k exists it goes to the nearest later sequence's segment, else the last segment.
 *  3. Inside a segment the days are split evenly over its units in order (earlier units take the remainder,
 *     at least one day each); with more units than days the extra units share the segment's last day.
 *  4. Units with dates_manual keep their dates: they are skipped here (but still count for rules 1-2, so
 *     they may overlap the automatic units; the caller shows that honestly).
 */
final class LearningPlanScheduler
{
    /**
     * @param  list<array{exam_sequence?: int|null, dates_manual?: bool}>  $units  in plan order
     * @param  list<array{sequence: int, start_date: string}>  $exams  the student's school exam periods
     * @return array<int, array{start: string, end: string}>  by unit index; manual units are absent
     */
    public static function schedule(array $units, string $planStart, string $planEnd, array $exams): array
    {
        $first = self::dayNumber($planStart);
        $last = self::dayNumber($planEnd);
        if ($units === [] || $last < $first) {
            return [];
        }

        $bounds = []; // [day, sequence], date order, ties by sequence
        foreach ($exams as $e) {
            $day = self::dayNumber((string) $e['start_date']);
            if ($day >= $first && $day <= $last) {
                $bounds[] = [$day, (int) $e['sequence']];
            }
        }
        usort($bounds, fn ($a, $b) => $a <=> $b);
        $starts = array_merge([$first], array_column($bounds, 0));
        $ends = array_merge(array_map(fn ($d) => $d - 1, array_column($bounds, 0)), [$last]);
        $closing = array_column($bounds, 1); // sequence closing segment j (the last segment has none)

        $bySegment = [];
        $used = [];
        $prevSeq = null;
        $prevSegment = 0;
        foreach ($units as $i => $unit) {
            $seq = isset($unit['exam_sequence']) ? (int) $unit['exam_sequence'] : $prevSeq;
            if ($seq === null) {
                $segment = 0;
            } elseif ($seq === $prevSeq) {
                $segment = $prevSegment;
            } else {
                $segment = self::segmentFor($seq, $closing, $used);
            }
            $prevSeq = $seq;
            $prevSegment = $segment;
            if (empty($unit['dates_manual'])) {
                $bySegment[$segment][] = $i;
            }
        }

        $out = [];
        foreach ($bySegment as $seg => $indexes) {
            $days = $ends[$seg] - $starts[$seg] + 1;
            $count = count($indexes);
            $cursor = $starts[$seg];
            foreach ($indexes as $n => $i) {
                if ($days <= 0) { // no room before the exam: share the segment's first day
                    $from = $to = min($last, max($first, $starts[$seg]));
                } elseif ($count > $days) { // more units than days: one day each, the rest share the last day
                    $from = $to = $starts[$seg] + min($n, $days - 1);
                } else {
                    $len = intdiv($days, $count) + ($n < $days % $count ? 1 : 0);
                    $from = $cursor;
                    $to = $cursor + $len - 1;
                    $cursor += $len;
                }
                $out[$i] = ['start' => self::date($from), 'end' => self::date($to)];
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * @param  list<int>  $closing
     * @param  array<int, true>  $used  exam indexes already claimed (updated here)
     */
    private static function segmentFor(int $seq, array $closing, array &$used): int
    {
        foreach ($closing as $j => $exam) {
            if ($exam === $seq && !isset($used[$j])) {
                $used[$j] = true;

                return $j;
            }
        }
        $later = null;
        foreach ($closing as $j => $exam) {
            if ($exam > $seq && ($later === null || $exam < $closing[$later])) {
                $later = $j;
            }
        }

        return $later ?? count($closing);
    }

    private static function dayNumber(string $date): int
    {
        return intdiv((int) strtotime(substr($date, 0, 10) . ' 00:00:00 UTC'), 86400);
    }

    private static function date(int $day): string
    {
        return gmdate('Y-m-d', $day * 86400);
    }
}
