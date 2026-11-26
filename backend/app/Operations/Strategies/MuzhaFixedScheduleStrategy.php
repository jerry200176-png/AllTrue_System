<?php

namespace App\Operations\Strategies;

use RuntimeException;

/**
 * Retired. The exact four-course repair (RM-MUZHA-FIXED-SCHEDULE-20260930) ran on 2026-10-01 and its lessons have since been
 * attended, so its preconditions can never hold again. The class and its catalog entry stay so that past POP records still
 * resolve; the embedded case manifest (which carried student names, #3627) is gone. Nothing here reads or writes data.
 */
final class MuzhaFixedScheduleStrategy
{
    public function plan(array $parameters): array
    {
        return ['ok' => false, 'errors' => ['operation_retired'], 'phase' => 'retired'];
    }

    public function execute(array $plan, array $context): array
    {
        throw new RuntimeException('muzha_fixed_schedule_retired');
    }

    public function verify(array $plan, array $result): array
    {
        return ['ok' => false, 'errors' => ['operation_retired']];
    }

    public function rollback(array $snapshot, array $context): array
    {
        throw new RuntimeException('muzha_fixed_schedule_retired');
    }
}
