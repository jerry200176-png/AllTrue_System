<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ParentBindingAttemptMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_binding_attempts_table_exists_with_indexes(): void
    {
        $this->assertTrue(Schema::hasTable('parent_binding_attempts'));
        foreach ([
            'id',
            'correlation_id',
            'occurred_at',
            'channel',
            'method',
            'outcome',
            'reason_code',
            'campus_id',
            'student_id',
            'phone_fingerprint',
            'candidate_count',
            'phone_match_count',
        ] as $col) {
            $this->assertTrue(Schema::hasColumn('parent_binding_attempts', $col), "missing column {$col}");
        }
    }
}
