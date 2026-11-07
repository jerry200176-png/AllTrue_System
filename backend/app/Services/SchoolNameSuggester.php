<?php

namespace App\Services;

/**
 * Suggests which free-text school spellings may be the same school (#517).
 * Suggestions only: nothing here writes. Input is names + counts, never student data.
 * Idea from OpenRefine fingerprint / near-match clustering (Apache-2.0), reimplemented in ~40 lines.
 */
class SchoolNameSuggester
{
    public function __construct(private SchoolDirectory $directory)
    {
    }

    /** Normalized key: width/space/台臺, and a trailing grade (高一, 國二, 國中三年級) collapses to 高中/國中. */
    public function key(string $raw): string
    {
        $k = $this->directory->normalize($raw);
        $k = (string) preg_replace('/(高|國)(?:中)?[一二三123](?:年級)?$/u', '$1中', $k);

        return str_replace(['高級中學', '國民中學'], ['高中', '國中'], $k);
    }

    /**
     * @param  array<string,int>  $counts  raw name => student count
     * @return list<array{names: list<array{name:string,count:int}>, student_count:int, reason:string}>
     */
    public function groups(array $counts): array
    {
        $names = array_map('strval', array_keys($counts)); // numeric names ("123") come back as int keys
        $parent = array_combine($names, $names);
        $find = function (string $n) use (&$parent, &$find): string {
            return $parent[$n] === $n ? $n : ($parent[$n] = $find($parent[$n]));
        };
        $reason = [];
        $keys = [];
        foreach ($names as $n) {
            $keys[$n] = $this->key($n);
        }
        $count = count($names);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $keys[$names[$i]];
                $b = $keys[$names[$j]];
                $why = $a === $b ? 'same_key' : ($this->oneCharApart($a, $b) ? 'similar' : null);
                if ($why === null) {
                    continue;
                }
                $parent[$find($names[$i])] = $find($names[$j]);
                $reason[$find($names[$j])] = ($reason[$find($names[$j])] ?? 'same_key') === 'similar' || $why === 'similar' ? 'similar' : 'same_key';
            }
        }
        $out = [];
        foreach ($names as $n) {
            $out[$find($n)][] = $n;
        }
        $groups = [];
        foreach ($out as $root => $members) {
            if (count($members) < 2) {
                continue;
            }
            usort($members, fn ($x, $y) => $counts[$y] <=> $counts[$x] ?: strcmp($x, $y));
            $groups[] = [
                'names' => array_map(fn ($m) => ['name' => $m, 'count' => $counts[$m]], $members),
                'student_count' => array_sum(array_map(fn ($m) => $counts[$m], $members)),
                'reason' => $reason[$root] ?? 'same_key',
            ];
        }
        usort($groups, fn ($x, $y) => $y['student_count'] <=> $x['student_count']);

        return $groups;
    }

    /** Same length (>=4 chars), exactly one character differs: 再興國中 / 在興國中. */
    private function oneCharApart(string $a, string $b): bool
    {
        $x = mb_str_split($a);
        $y = mb_str_split($b);
        if (count($x) !== count($y) || count($x) < 4) {
            return false;
        }
        $diff = 0;
        foreach ($x as $i => $c) {
            $diff += $c !== $y[$i] ? 1 : 0;
        }

        return $diff === 1;
    }
}
