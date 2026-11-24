<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AssessmentSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_learning_assessment_and_question_bank_table_is_dropped(): void
    {
        // TD-090 / TD-091: the feature, its readers and its models are gone; every table was empty in production.
        foreach (['assessments', 'assessment_results', 'assessment_remediation_actions', 'assessment_answers',
            'assessment_attempts', 'assessment_question_snapshots', 'assessment_audit_logs',
            'question_bank_audit_logs', 'question_bank_items', 'question_banks'] as $dropped) {
            $this->assertFalse(Schema::hasTable($dropped), $dropped);
        }
    }
}
