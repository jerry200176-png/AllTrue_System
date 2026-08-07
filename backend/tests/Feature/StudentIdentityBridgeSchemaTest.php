<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StudentIdentityBridgeSchemaTest extends TestCase
{
    public function test_identity_bridge_tables_and_parent_session_context_exist(): void
    {
        foreach ([
            'student_identity_groups',
            'student_identity_members',
            'parent_cross_campus_access',
            'student_identity_audit_logs',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected {$table} to exist.");
        }

        $this->assertTrue(Schema::hasColumn('ParentSession', 'identity_group_id'));
        $this->assertTrue(Schema::hasColumn('student_identity_members', 'student_id'));
        $this->assertTrue(Schema::hasColumn('student_identity_members', 'campus_id'));
        $this->assertTrue(Schema::hasColumn('student_identity_audit_logs', 'actor_user_id'));
    }
}
