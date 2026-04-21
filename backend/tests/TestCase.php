<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        // Production database guard: abort immediately if tests are about to run
        // against the production database. This prevents RefreshDatabase from wiping live data.
        $dbName = config('database.connections.' . config('database.default') . '.database')
            ?? DB::connection()->getDatabaseName();
        $productionDatabases = ['AllTrue', 'alltrue'];
        if (in_array($dbName, $productionDatabases, true)) {
            throw new \RuntimeException(
                "[SAFETY ABORT] Tests are pointing at the PRODUCTION database '{$dbName}'.\n" .
                "Fix .env.testing or phpunit.xml to use DB_DATABASE=AllTrue_test before running tests.\n" .
                "Current DB_DATABASE env: " . (getenv('DB_DATABASE') ?: '(not set via env)')
            );
        }

        parent::setUp();
        $this->seedSubjectsIfNeeded();
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
        foreach ($subjects as $name) {
            $rows[] = ['School_id' => 1, 'Grade_no' => 0, 'Subject_Name' => $name];
        }
        DB::table('Subject')->insert($rows);
    }
}
