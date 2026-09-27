<?php

namespace Tests\Unit\TrueFit;

use App\Services\TrueFit\TrueFitTeacherObservationContract;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TrueFitTeacherObservationContractTest extends TestCase
{
    public function test_accepts_valid_observation(): void
    {
        $contract = new TrueFitTeacherObservationContract();
        $out = $contract->assertValid($this->sample());
        $this->assertSame(TrueFitTeacherObservationContract::SCHEMA_VERSION, $out['schema_version']);
    }

    public function test_rejects_missing_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $sample = $this->sample();
        unset($sample['student_moves']);
        (new TrueFitTeacherObservationContract())->assertValid($sample);
    }

    public function test_rejects_bad_confidence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $sample = $this->sample();
        $sample['confidence'] = 'sure';
        (new TrueFitTeacherObservationContract())->assertValid($sample);
    }

    /** @return array<string, mixed> */
    private function sample(): array
    {
        return [
            'schema_version' => TrueFitTeacherObservationContract::SCHEMA_VERSION,
            'session_ref' => ['class_session_id' => 42],
            'observed_at' => '2026-09-16T10:30:00+08:00',
            'objectives_touched' => ['辨識分數加減'],
            'student_moves' => ['先通分再加減'],
            'struggle_signals' => [
                ['label' => '通分', 'signal' => '分母相加', 'linked_objective' => '辨識分數加減'],
            ],
            'strength_signals' => ['能口述題意'],
            'misconception_hypotheses' => [
                [
                    'label' => '分母規則混淆',
                    'what_student_seemed_to_believe' => '分母可以直接相加',
                    'what_to_check_next' => '給同分母對照題',
                ],
            ],
            'evidence_notes' => '課堂板書片段',
            'confidence' => 'medium',
        ];
    }
}
