<?php

namespace App\Operations\Strategies;

use App\Models\SessionCorrection;
use App\Models\StudentClass;
use App\Services\MonthlyAccountingCorrectionService;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Separate planned operation; the original split never changes cash history. */
final class MonthlyAccountingCorrectionStrategy
{
    public function __construct(private MonthlyAccountingCorrectionService $service) {}

    public function plan(array $parameters): array
    {
        $source = StudentClass::query()->find((int) ($parameters['source_course_id'] ?? 0));
        if (!$source instanceof StudentClass || (int) $source->student?->CampusID !== (int) ($parameters['campus_id'] ?? 0)
            || (int) ($parameters['input']['campus_id'] ?? 0) !== (int) $parameters['campus_id']) {
            return ['ok' => false, 'errors' => ['source_campus_mismatch']];
        }
        $existing = SessionCorrection::query()->where('decision_reference', $parameters['decision_reference'])->orderBy('id')->first();
        if ($existing) {
            $result = $existing->snapshot_before;
            if ($existing->rolled_back_at || ($result['accounting_token'] ?? null) !== $parameters['confirmation_token']
                || ($result['source_course_id'] ?? null) !== (int) $source->getAttribute('ID') || ($result['accounting_input'] ?? null) !== $parameters['input']) {
                return ['ok' => false, 'errors' => ['repair_reference_mismatch']];
            }
            return ['ok' => true, 'parameters' => $parameters, 'applied' => true];
        }
        try {
            $plan = $this->service->preview($source, $parameters['input']);
            if (!hash_equals($plan['confirmation_token'], $parameters['confirmation_token'])) return ['ok' => false, 'errors' => ['preview_stale']];
            return ['ok' => true, 'parameters' => $parameters, 'snapshot' => $plan['snapshot'], 'session_ids' => $plan['session_ids']];
        } catch (ValidationException) {
            return ['ok' => false, 'errors' => ['accounting_precondition_failed']];
        }
    }

    public function execute(array $plan, array $context): array
    {
        if (!$plan['ok']) throw new RuntimeException('Accounting repair preconditions failed');
        $p = $plan['parameters'];
        $source = StudentClass::query()->findOrFail($p['source_course_id']);
        if (!$source instanceof StudentClass) throw new RuntimeException('Course model unavailable');
        $result = $this->service->execute($source, $p['input'], $p['confirmation_token'], $p['decision_reference'], (string) $context['actor']);
        return ['ok' => true, 'snapshot' => $result, 'target_course_id' => $result['target_course_id'], 'target_invoice_id' => $result['target_invoice_id']];
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
