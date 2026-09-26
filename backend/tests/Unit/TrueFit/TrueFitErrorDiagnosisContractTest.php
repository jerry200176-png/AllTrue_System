<?php

namespace Tests\Unit\TrueFit;

use App\Services\TrueFit\TrueFitErrorDiagnosisContract;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TrueFitErrorDiagnosisContractTest extends TestCase
{
    public function test_accepts_valid_diagnosis(): void
    {
        $out = (new TrueFitErrorDiagnosisContract())->assertValid($this->sample());
        $this->assertSame(TrueFitErrorDiagnosisContract::SCHEMA_VERSION, $out['schema_version']);
    }

    public function test_rejects_empty_recommended_checks(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $sample = $this->sample();
        $sample['recommended_checks'] = [];
        (new TrueFitErrorDiagnosisContract())->assertValid($sample);
    }

    public function test_rejects_bad_decision(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $sample = $this->sample();
        $sample['teacher_decision'] = 'maybe';
        (new TrueFitErrorDiagnosisContract())->assertValid($sample);
    }

    /** @return array<string, mixed> */
    private function sample(): array
    {
        return [
            'schema_version' => TrueFitErrorDiagnosisContract::SCHEMA_VERSION,
            'session_ref' => ['class_session_id' => 42],
            'source_observation_id' => null,
            'proposed_at' => '2026-09-16T11:00:00+08:00',
            'primary_misconception' => [
                'label' => '分母相加',
                'statement' => '分數加減時把分母直接相加',
                'why_it_fits_observation' => '學生在通分步驟把分母相加',
                'linked_brief_objective' => '辨識分數加減',
            ],
            'supporting_signals' => ['通分錯誤'],
            'ruled_out' => [],
            'recommended_checks' => ['給同分母對照題'],
            'confidence' => 'medium',
            'teacher_decision' => 'pending',
            'teacher_notes' => '',
        ];
    }
}
