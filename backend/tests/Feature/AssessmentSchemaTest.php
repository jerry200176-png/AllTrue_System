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
        $this->assertTrue(Schema::hasColumn('assessment_results', 'max_score_snapshot'));
        $this->assertTrue(Schema::hasColumn('assessment_results', 'attempt_no'));
        $this->assertTrue(Schema::hasTable('assessment_remediation_actions'));

        // TD-090 / TD-091: the unused question-bank and attempt tables are dropped.
        foreach (['assessment_answers', 'assessment_attempts', 'assessment_question_snapshots', 'assessment_audit_logs',
            'question_bank_audit_logs', 'question_bank_items', 'question_banks'] as $dropped) {
            $this->assertFalse(Schema::hasTable($dropped), $dropped);
        }
    }
}
