<?php

namespace App\Services;

/**
 * 學習計畫的日期演算法 (#4970 step 4a). Pure and deterministic: no DB, no clock.
 *
 * Rules:
 *  1. Boundaries = plan_start, every exam start strictly inside (plan_start, plan_end), plan_end.
 *     A segment ends the day before the exam that closes it; the last one ends on plan_end (inclusive).
 *  2. A unit tagged with exam k goes to the segment closed by exam k. An untagged unit inherits the previous
 *     unit's sequence (the first segment when there is none). If exam k has no usable date it goes to the
 *     nearest later exam's segment, else the last segment.
 *  3. Inside a segment the days are split evenly over its units in order (earlier units take the remainder,
 *     at least one day each); with more units than days the extra units share the segment's last day.
 *  4. Units with dates_manual keep their dates: they are skipped here (but still count for inheritance).
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

        $bounds = []; // day number => exam sequence closing the segment before it
        foreach ($exams as $e) {
            $day = self::dayNumber((string) $e['start_date']);
            $seq = (int) $e['sequence'];
            if ($day > $first && $day < $last && (!isset($bounds[$day]) || $seq < $bounds[$day])) {
                $bounds[$day] = $seq;
            }
        }
        ksort($bounds);
        $starts = array_merge([$first], array_keys($bounds));
        $closing = array_merge(array_values($bounds), [null]); // exam sequence closing each segment
        $ends = array_merge(array_map(fn ($d) => $d - 1, array_keys($bounds)), [$last]);

        $bySegment = [];
        $inherited = null;
        foreach (array_values($units) as $i => $unit) {
            $seq = isset($unit['exam_sequence']) ? (int) $unit['exam_sequence'] : $inherited;
            $inherited = $seq;
            if (!empty($unit['dates_manual'])) {
                continue;
            }
            $bySegment[self::segmentFor($seq, $closing)][] = $i;
        }

        $out = [];
        foreach ($bySegment as $seg => $indexes) {
            $days = $ends[$seg] - $starts[$seg] + 1;
            $count = count($indexes);
            $cursor = $starts[$seg];
            foreach ($indexes as $n => $i) {
                if ($count > $days) { // more units than days: one day each, the rest share the last day
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

    /** @param list<int|null> $closing */
    private static function segmentFor(?int $seq, array $closing): int
    {
        $lastSegment = count($closing) - 1;
        if ($seq === null) {
            return 0;
        }
        $later = null;
        foreach ($closing as $j => $exam) {
            if ($exam === null) {
                continue;
            }
            if ($exam === $seq) {
                return $j;
            }
            if ($exam > $seq && ($later === null || $exam < $closing[$later])) {
                $later = $j;
            }
        }

        return $later ?? $lastSegment;
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
