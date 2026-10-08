<?php

namespace App\Services;

/** Maps an in-app report to a family name using config/bug_families.php (see that file for the rules). */
class BugFamily
{
    /** @return string|null family key (lowercase letters and hyphens) or null when nothing matches */
    public static function classify(?string $pageKey, ?string $text, ?array $families = null): ?string
    {
        $families ??= (array) config('bug_families.families', []);
        $page = mb_strtolower((string) $pageKey);
        $text = mb_strtolower((string) $text);
        $best = null;
        $bestScore = 0;
        foreach ($families as $name => $rule) {
            $score = 0;
            foreach ((array) ($rule['pages'] ?? []) as $p) {
                $score += $page !== '' && str_contains($page, mb_strtolower($p)) ? 3 : 0;
            }
            foreach ((array) ($rule['keywords'] ?? []) as $k) {
                $score += $text !== '' && str_contains($text, mb_strtolower($k)) ? 1 : 0;
            }
            if ($score > $bestScore) {
                [$best, $bestScore] = [(string) $name, $score];
            }
        }

        return $best;
    }
}
