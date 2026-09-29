<?php

namespace App\Operations\Strategies;

use App\Models\SessionCorrection;
use App\Models\StudentClass;
use App\Services\MonthlyContractCorrectionService;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Catalog-bound adapter; immutable parameters and Founder approval remain POP's authority. */
final class MonthlyContractSplitStrategy
{
    public function __construct(private MonthlyContractCorrectionService $service) {}

    public function plan(array $parameters): array
    {
        $source = StudentClass::query()->where('ID', (int) ($parameters['source_course_id'] ?? 0))->first();
        if (!$source instanceof StudentClass || (int) $source->student?->CampusID !== (int) ($parameters['campus_id'] ?? 0)) {
            return ['ok' => false, 'errors' => ['source_campus_mismatch']];
        }
        $existing = SessionCorrection::query()->where('decision_reference', $parameters['decision_reference'])->whereNull('rolled_back_at')->orderBy('id')->first();
        if ($existing) {
            $result = $existing->snapshot_before;
            if (($result['source_course_id'] ?? null) !== (int) $source->getAttribute('ID') || ($result['confirmation_token'] ?? null) !== $parameters['confirmation_token']) {
                return ['ok' => false, 'errors' => ['repair_reference_mismatch']];
            }
            return ['ok' => true, 'parameters' => $parameters, 'applied' => true, 'snapshot' => $result['snapshot']];
        }
        try {
            $plan = $this->service->preview($source, $parameters['input']);
            if (!hash_equals($plan['confirmation_token'], $parameters['confirmation_token'])) return ['ok' => false, 'errors' => ['preview_stale']];
            return ['ok' => true, 'parameters' => $parameters, 'snapshot' => $plan['snapshot'], 'session_ids' => $plan['session_ids']];
        } catch (ValidationException) {
            return ['ok' => false, 'errors' => ['monthly_contract_precondition_failed']];
        }
    }

    public function execute(array $plan, array $context): array
    {
        if (!$plan['ok']) throw new RuntimeException('Monthly repair preconditions failed');
        $p = $plan['parameters'];
        $source = StudentClass::query()->where('ID', $p['source_course_id'])->first();
        if (!$source instanceof StudentClass) throw new RuntimeException('Source contract no longer exists');
        $result = $this->service->execute($source, $p['input'], $p['confirmation_token'], $p['decision_reference']);
        return ['ok' => true, 'snapshot' => $result, 'target_course_id' => $result['target_course_id']];
    }

    public function verify(array $plan, array $result): array
    {
        return $this->service->verify($result['snapshot']);
    }

    public function rollback(array $snapshot, array $context): array
    {
        return $this->service->rollback($snapshot);
    }
}
