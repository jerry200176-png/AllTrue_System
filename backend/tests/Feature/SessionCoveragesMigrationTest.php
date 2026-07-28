<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ADR-006 Phase 3B — schema contract for session_coverages migration.
 * Does not activate coverage writers; asserts empty-table shape only.
 */
class SessionCoveragesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_coverages_table_shape_after_migrate(): void
    {
        $this->assertTrue(Schema::hasTable('session_coverages'));

        foreach ([
            'id', 'package_id', 'student_class_id', 'class_session_id', 'campus_id',
            'occurrence_date', 'start_hm', 'status', 'commitment_fingerprint',
            'created_at', 'updated_at',
        ] as $col) {
            $this->assertTrue(Schema::hasColumn('session_coverages', $col), "missing $col");
        }

        // No silent historical ClassSession marking — table starts empty.
        $this->assertSame(0, \DB::table('session_coverages')->count());
    }
}
