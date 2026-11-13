<?php

namespace Tests\Unit;

use App\Support\AppClockSql;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Nightly read-only SQL must take "today"/"now" from the app clock (config app.timezone), never MySQL
 * CURDATE()/NOW() (database session timezone; UTC in CI, which disagrees with Taipei 16:00-24:00 UTC).
 */
class AppClockSqlTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_binds_one_instant_in_placeholder_order(): void
    {
        $at = Carbon::parse('2026-11-14 00:30:00', 'Asia/Taipei');
        [$sql, $bindings] = AppClockSql::bind('a >= :app_today AND b <= :app_now AND c < :app_today', $at);

        $this->assertSame('a >= ? AND b <= ? AND c < ?', $sql);
        $local = $at->copy()->setTimezone(config('app.timezone'));
        $this->assertSame([$local->toDateString(), $local->format('Y-m-d H:i:s'), $local->toDateString()], $bindings);
    }

    public function test_follows_the_configured_timezone_not_a_hardcoded_one(): void
    {
        $original = config('app.timezone');
        try {
            $at = Carbon::parse('2026-11-14 00:30:00', 'Asia/Taipei'); // 2026-11-13 08:30 in Los Angeles
            config(['app.timezone' => 'America/Los_Angeles']);
            [, $la] = AppClockSql::bind(':app_today :app_now', $at);
            config(['app.timezone' => 'Asia/Taipei']);
            [, $tpe] = AppClockSql::bind(':app_today :app_now', $at);

            $this->assertSame(['2026-11-13', '2026-11-13 08:30:00'], $la);
            $this->assertSame(['2026-11-14', '2026-11-14 00:30:00'], $tpe);
        } finally {
            config(['app.timezone' => $original]);
        }
    }

    public function test_nightly_command_and_support_sql_never_use_the_database_clock(): void
    {
        // Allowlist: transactional code, tracked as a follow-up (not a read-only invariant).
        $allow = ['Console/Commands/GenerateForwardSessions.php'];
        $root = dirname(__DIR__, 2) . '/app';
        $hits = [];
        foreach (['Console/Commands', 'Support'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $rel = ltrim(substr($file->getPathname(), strlen($root)), '/');
                if (in_array($rel, $allow, true) || $rel === 'Support/AppClockSql.php') {
                    continue;
                }
                // SQL `NOW()` is matched in upper case only: PHP's own now() / Carbon::now() is the app clock and is fine.
                $src = (string) file_get_contents($file->getPathname());
                if (preg_match('/\bNOW\s*\(/', $src) || preg_match('/\b(CURDATE|CURTIME)\s*\(|\bCURRENT_(DATE|TIME|TIMESTAMP)\b/i', $src)) {
                    $hits[] = $rel;
                }
            }
        }
        $this->assertSame([], $hits, 'Use AppClockSql (:app_today / :app_now) instead of the DB clock; see AI_REGRESSION_LESSONS.');
    }
}
