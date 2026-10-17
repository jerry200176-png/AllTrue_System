<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExamRoundSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_exam_round_schema_is_additive_with_audit_contract(): void
    {
        $this->assertTrue(Schema::hasTable('exam_rounds'));
        $this->assertTrue(Schema::hasTable('exam_round_subjects'));
        $this->assertTrue(Schema::hasTable('exam_scores'));
        $this->assertTrue(Schema::hasTable('exam_score_audit_logs'));
        $this->assertTrue(Schema::hasColumn('exam_rounds', 'grade_ids'));
        $this->assertTrue(Schema::hasColumn('exam_rounds', 'entry_deadline'));
        $this->assertTrue(Schema::hasColumn('exam_scores', 'is_absent'));
        $this->assertTrue(Schema::hasColumn('exam_score_audit_logs', 'before'));
        $this->assertTrue(Schema::hasColumn('exam_score_audit_logs', 'after'));
    }
}
