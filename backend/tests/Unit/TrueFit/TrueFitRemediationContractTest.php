<?php

namespace Tests\Unit\TrueFit;

use App\Services\TrueFit\TrueFitRemediationContract;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TrueFitRemediationContractTest extends TestCase
{
    public function test_accepts_valid_plan(): void
    {
        $out = (new TrueFitRemediationContract())->assertValid($this->sample());
        $this->assertSame(TrueFitRemediationContract::SCHEMA_VERSION, $out['schema_version']);
    }

    public function test_rejects_empty_practice_moves(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $s = $this->sample();
        $s['practice_moves'] = [];
        (new TrueFitRemediationContract())->assertValid($s);
    }

    /** @return array<string, mixed> */
    private function sample(): array
    {
        return [
            'schema_version' => TrueFitRemediationContract::SCHEMA_VERSION,
            'session_ref' => ['class_session_id' => 1],
            'source_diagnosis_id' => null,
            'planned_at' => '2026-09-16T12:00:00+08:00',
            'target_misconception_label' => '分母相加',
            'practice_moves' => ['同分母對照練習'],
            'material_anchors' => ['syn.math.fractions.v1'],
            'success_criteria' => ['能正確通分後加減'],
            'follow_up_window' => 'next_session',
            'teacher_decision' => 'pending',
            'teacher_notes' => '',
        ];
    }
}
