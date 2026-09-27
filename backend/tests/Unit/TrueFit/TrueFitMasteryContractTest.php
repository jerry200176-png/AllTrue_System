<?php

namespace Tests\Unit\TrueFit;

use App\Services\TrueFit\TrueFitMasteryContract;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TrueFitMasteryContractTest extends TestCase
{
    public function test_accepts_valid_evidence(): void
    {
        $out = (new TrueFitMasteryContract())->assertValid($this->sample());
        $this->assertSame(TrueFitMasteryContract::SCHEMA_VERSION, $out['schema_version']);
    }

    public function test_rejects_empty_retrieval_prompt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $s = $this->sample();
        $s['retrieval_prompt'] = '  ';
        (new TrueFitMasteryContract())->assertValid($s);
    }

    public function test_rejects_invalid_outcome(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $s = $this->sample();
        $s['outcome'] = 'perfect';
        (new TrueFitMasteryContract())->assertValid($s);
    }

    /** @return array<string, mixed> */
    private function sample(): array
    {
        return [
            'schema_version' => TrueFitMasteryContract::SCHEMA_VERSION,
            'session_ref' => ['class_session_id' => 1],
            'source_remediation_id' => null,
            'checked_at' => '2026-09-16T12:00:00+08:00',
            'target_misconception_label' => '分母相加',
            'retrieval_prompt' => '請再解一題同分母加減',
            'student_response_summary' => '正確通分後作答',
            'outcome' => 'mastered',
            'evidence_notes' => '',
            'next_review_window' => 'within_30_days',
            'teacher_decision' => 'pending',
        ];
    }
}
