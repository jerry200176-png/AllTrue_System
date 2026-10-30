<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Kept apart from the data-heavy snapshot tests: MySQL DDL implicitly commits, so this class must create no rows (they would leak into other tests). */
class AuthorSnapshotMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_is_create_only_and_rolls_back_by_dropping_the_table(): void
    {
        $this->assertTrue(Schema::hasTable('learning_record_author_snapshots'));
        $m = require database_path('migrations/2026_10_29_150000_create_learning_record_author_snapshots_table.php');
        $m->down();
        $this->assertFalse(Schema::hasTable('learning_record_author_snapshots'));
        $this->assertTrue(Schema::hasTable('LearningRecord'));
        $m->up();
        $this->assertTrue(Schema::hasTable('learning_record_author_snapshots'));
        DB::connection()->getPdo()->beginTransaction(); // reopen so teardown's rollback has a transaction
    }
}
