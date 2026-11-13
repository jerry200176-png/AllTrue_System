<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * "Today" and "now" for read-only SQL checks, taken from the APPLICATION clock (config app.timezone, Asia/Taipei).
 *
 * MySQL CURDATE()/NOW() follow the DATABASE session timezone. Lesson dates (ClassSession.SessionDate) are Taipei
 * calendar days, so between 16:00 and 24:00 UTC a UTC database is one day behind and every date condition shifts by a
 * day (the nightly invariant check runs in exactly that window). Write `:app_today` / `:app_now` in the SQL and pass the
 * statement through bind(); both values come from one instant, so conditions cannot drift apart.
 */
final class AppClockSql
{
    /**
     * @return array{0:string,1:list<string>} SQL with positional `?` and its bindings (same order as the placeholders)
     */
    public static function bind(string $sql, ?CarbonInterface $at = null): array
    {
        $at = ($at ?? now())->copy()->setTimezone(config('app.timezone'));
        $values = [':app_today' => $at->toDateString(), ':app_now' => $at->format('Y-m-d H:i:s')];
        $bindings = [];
        $out = preg_replace_callback('/:app_(?:today|now)\b/', static function (array $m) use ($values, &$bindings): string {
            $bindings[] = $values[$m[0]];

            return '?';
        }, $sql);

        return [$out, $bindings];
    }
}
