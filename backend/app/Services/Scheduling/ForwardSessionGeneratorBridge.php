<?php

namespace App\Services\Scheduling;

use App\Services\ForwardSessionGenerator;
use Carbon\Carbon;

/**
 * Read-only adapter over ForwardSessionGenerator::planCourse for Phase 0 evidence.
 */
final class ForwardSessionGeneratorBridge
{
    public function __construct(private ?ForwardSessionGenerator $generator = null)
    {
    }

    /**
     * @return array{status:string,reason:string,slots:list<mixed>}
     */
    public function planReadOnly(int $studentClassId, Carbon $today): array
    {
        $gen = $this->generator ?? app(ForwardSessionGenerator::class);
        $plan = $gen->planCourse($studentClassId, 4, $today);

        return [
            'status' => (string) ($plan['status'] ?? 'skip'),
            'reason' => (string) ($plan['reason'] ?? ''),
            'slots' => $plan['slots'] ?? [],
        ];
    }
}
