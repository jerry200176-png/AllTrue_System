<?php

namespace App\Services;

/**
 * 學習計畫的日期演算法 (#4970 step 4a). Pure and deterministic: no DB, no clock.
 *
 * Rules:
 *  1. Boundaries = plan_start, every exam whose start lies inside [plan_start, plan_end], plan_end. A segment
 *     ends the day before the exam that closes it; the last one ends on plan_end. An exam on plan_start or
 *     plan_end is still a boundary; a segment with no room (zero days) puts its units on its first day.
 *  2. Units walk the exams in date order with a cursor (the segment of the previous unit) that never moves
 *     back. A unit with sequence k (untagged units inherit the previous one's; the first segment if none):
 *     (a) takes the exam with sequence k in the cursor's term at or after the cursor;
 *     (b) else, if k is lower than the previous unit's sequence (a term break in the template) and a later
 *         term exists, moves to that term's exam k, or that term's first exam;
 *     (c) else stays in the cursor's segment, or goes to the final segment when no exam of the last term at or
 *         after the cursor has a sequence >= k.
 *     Same-sequence neighbours share a segment. A term is (academic_year, semester).
 *  3. Inside a segment the days are split evenly over its units in order (earlier units take the remainder,
 *     at least one day each); with more units than days the extra units share the segment's last day.
 *  4. Units with dates_manual keep their dates: they are skipped here (but still walk the cursor, so they may
 *     overlap automatic units; the caller shows that honestly).
 */
final class LearningPlanScheduler
{
    /**
     * @param  list<array{exam_sequence?: int|null, dates_manual?: bool}>  $units  in plan order
     * @param  list<array{sequence: int, start_date: string, academic_year?: int, semester?: int}>  $exams
     * @return array<int, array{start: string, end: string}>  by unit index; manual units are absent
     */
    public static function schedule(array $units, string $planStart, string $planEnd, array $exams): array
    {
        $first = self::dayNumber($planStart);
        $last = self::dayNumber($planEnd);
        if ($units === [] || $last < $first) {
            return [];
        }

        $bounds = []; // [day, sequence, term], date order
        foreach ($exams as $e) {
            $day = self::dayNumber((string) $e['start_date']);
            if ($day >= $first && $day <= $last) {
                $bounds[] = [$day, (int) $e['sequence'], (int) ($e['academic_year'] ?? 0) * 10 + (int) ($e['semester'] ?? 0)];
            }
        }
        usort($bounds, fn ($a, $b) => $a <=> $b);
        $starts = array_merge([$first], array_column($bounds, 0));
        $ends = array_merge(array_map(fn ($d) => $d - 1, array_column($bounds, 0)), [$last]);

        $bySegment = [];
        $prevSeq = null;
        $cursor = 0;
        foreach ($units as $i => $unit) {
            $seq = isset($unit['exam_sequence']) ? (int) $unit['exam_sequence'] : $prevSeq;
            if ($seq !== null && $seq !== $prevSeq) {
                $cursor = self::segmentFor($seq, $prevSeq, $cursor, $bounds);
            }
            $prevSeq = $seq;
            if (empty($unit['dates_manual'])) {
                $bySegment[$cursor][] = $i;
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
                    [$from, $to] = [$cursor, $cursor + $len - 1];
                    $cursor += $len;
                }
                $out[$i] = ['start' => gmdate('Y-m-d', $from * 86400), 'end' => gmdate('Y-m-d', $to * 86400)];
            }
        }
        ksort($out);

        return $out;
    }

    /** @param list<array{int, int, int}> $bounds [day, sequence, term] in date order */
    private static function segmentFor(int $seq, ?int $prev, int $cursor, array $bounds): int
    {
        $m = count($bounds);
        if ($cursor >= $m) {
            return $m;
        }
        $term = $bounds[$cursor][2];
        $next = null; // first exam of a later term
        for ($j = $cursor; $j < $m; $j++) {
            if ($bounds[$j][2] === $term && $bounds[$j][1] === $seq) {
                return $j;
            }
            $next ??= $bounds[$j][2] !== $term ? $j : null;
        }
        if ($next !== null && $prev !== null && $seq < $prev) {
            for ($j = $next; $j < $m && $bounds[$j][2] === $bounds[$next][2]; $j++) {
                if ($bounds[$j][1] === $seq) {
                    return $j;
                }
            }

            return $next;
        }
        if ($next === null && max(array_column(array_slice($bounds, $cursor), 1)) < $seq) {
            return $m;
        }

        return $cursor;
    }

    private static function dayNumber(string $date): int
    {
        return intdiv((int) strtotime(substr($date, 0, 10) . ' 00:00:00 UTC'), 86400);
    }
}
