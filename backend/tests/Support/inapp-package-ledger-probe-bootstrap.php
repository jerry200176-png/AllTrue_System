<?php

/** Test-only APP_ROOT wrapper. The production probe itself is never rewritten. */

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

if (getenv('APP_ENV') !== 'testing'
    || ! preg_match('/^AllTrue_test(?:_[a-zA-Z0-9_]+)?$/D', (string) getenv('DB_DATABASE'))
    || ! in_array(getenv('DB_HOST'), ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('test database required');
}

$backend = (string) getenv('PROBE_TEST_BACKEND');
$root = dirname(__DIR__);
$app = require $backend . '/bootstrap/app.php';
$app->useEnvironmentPath($root);
$app->loadEnvironmentFrom('no-environment-file-for-this-fixture');
$app->useStoragePath($root . '/storage');

$app->booted(function () use ($app, $root): void {
    if (! $app->environment('testing')
        || ! preg_match('/^AllTrue_test(?:_[a-zA-Z0-9_]+)?$/D', (string) config('database.connections.mysql.database'))) {
        throw new RuntimeException('test application required');
    }
    $observed = ['probe_selects' => 0, 'rolled_back' => false];
    $save = function () use (&$observed, $root): void {
        file_put_contents($root . '/observed.json', json_encode($observed, JSON_THROW_ON_ERROR), LOCK_EX);
    };
    $save();
    $app['events']->listen(TransactionRolledBack::class, function () use (&$observed, $save): void {
        $observed['rolled_back'] = true;
        $save();
    });
    DB::listen(function (QueryExecuted $query) use (&$observed, $save): void {
        if (strpos($query->sql, 'SELECT p.id FROM course_packages p') !== 0) {
            return;
        }
        $observed['probe_selects']++;
        $save();
        if (getenv('PROBE_TEST_MODE') === 'write') {
            try {
                // An ordinary InnoDB table, not a temporary table (READ ONLY permits those).
                // The zero-row predicate also prevents business data changes in a broken mutant.
                DB::statement('UPDATE course_packages SET name = ? WHERE id = 0', [getenv('PROBE_TEST_SENTINEL')]);
            } catch (QueryException $e) {
                $observed['sqlstate'] = $e->errorInfo[0] ?? null;
                $observed['driver_code'] = (int) ($e->errorInfo[1] ?? 0);
                $save();
                throw $e;
            }
        } elseif (getenv('PROBE_TEST_MODE') === 'exception') {
            throw new RuntimeException((string) getenv('PROBE_TEST_SENTINEL'));
        }
    });
});

return $app;
