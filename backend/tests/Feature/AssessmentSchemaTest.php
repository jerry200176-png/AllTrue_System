<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AssessmentSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_assessment_schema_is_additive_and_has_attempt_audit_contract(): void
    {
        $this->assertTrue(Schema::hasTable('assessments'));
        $this->assertTrue(Schema::hasTable('assessment_results'));
        $this->assertTrue(Schema::hasTable('assessment_audit_logs'));
        $this->assertTrue(Schema::hasColumn('assessment_results', 'max_score_snapshot'));
        $this->assertTrue(Schema::hasColumn('assessment_results', 'attempt_no'));
        $this->assertTrue(Schema::hasColumn('assessment_audit_logs', 'before'));
        $this->assertTrue(Schema::hasColumn('assessment_audit_logs', 'after'));
    }
}
