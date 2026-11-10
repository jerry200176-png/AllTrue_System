<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useParallelWorkerDatabase();
        \App\Models\ClassSession::resetSettlementLockCache();
        $this->seedSubjectsIfNeeded();
    }


    private static bool $workerDbReady = false;

    /**
     * #4945: Laravel only gives each paratest worker its own DB (AllTrue_test_test_N) to tests that use a
     * database trait. Tests without one (e.g. the multi-process billing race harnesses, which run
     * migrate:fresh) would all share AllTrue_test across workers, so move them to the worker DB too.
     * No-op outside parallel runs and for trait tests (Laravel already switched them).
     */
    private function useParallelWorkerDatabase(): void
    {
        $token = ParallelTesting::token();
        $connection = config('database.default');
        $base = config("database.connections.{$connection}.database");
        if (!$token || $base !== 'AllTrue_test') {
            return;
        }
        $worker = "{$base}_test_{$token}";
        $switch = function (string $db) use ($connection) {
            DB::purge();
            config(["database.connections.{$connection}.database" => $db]);
        };
        $switch($worker);
        if (self::$workerDbReady) {
            return;
        }
        try {
            $fresh = !Schema::hasTable('migrations');
        } catch (\Illuminate\Database\QueryException) {
            $switch($base);
            Schema::createDatabase($worker);
            $switch($worker);
            $fresh = true;
        }
        if ($fresh) {
            Artisan::call('migrate', ['--force' => true]);
        }
        self::$workerDbReady = true;
    }

    private function seedSubjectsIfNeeded(): void
    {
        if (!Schema::hasTable('Subject')) {
            return;
        }
        if (DB::table('Subject')->exists()) {
            return;
        }
        $subjects = ['Chinese', 'English', 'Math', 'Physics', 'Chemistry', 'Science', 'Biology', 'Social'];
        $rows = [];
        // Fixed high ids: auto-increment ids gave the first test in each worker DB Subject 1 = Chinese, so tests that
        // insertOrIgnore their own Subject 1/2 (or join SubjectID 1) passed or failed by test order (shards, #5013).
        foreach ($subjects as $i => $name) {
            $rows[] = ['id' => 970001 + $i, 'School_id' => 1, 'Grade_no' => 0, 'Subject_Name' => $name];
        }
        DB::table('Subject')->insert($rows);
    }
}
