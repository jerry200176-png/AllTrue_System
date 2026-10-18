<?php

namespace App\Operations\Strategies;

use App\Services\Billing\PaymentReversalRestatement;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Normal POP adapter; absent from the production catalog until the protected package is approved. */
final class PaymentReversalRestatementStrategy
{
    public function __construct(private PaymentReversalRestatement $service) {}

    private function actor(array $p): array
    {
        return $this->service->actorContext($p['plan']['actor_user_id'], $p['plan']['scope']['student_id']);
    }

    public function plan(array $parameters, array $context = []): array
    {
        try {
            if (!isset($parameters['plan']['state_hash'])) {
                $scope = $parameters['plan']['scope']; $user = request()->attributes->get('auth_user');
                if (!$user || ($parameters['campus_id'] ?? null) !== ($scope['campus_id'] ?? null)) throw new RuntimeException('invalid_preview');
                $actor = $this->service->actorContext($user->id, $scope['student_id']);
                return ['ok' => false, 'errors' => ['preview_confirmation_required'], 'preview_parameters' =>
                    $this->service->requestParameters($this->service->plan($scope, $actor), $parameters['decision_reference'])];
            }
            $creator = isset($context['operation_id']) ? DB::table('pop_operation_requests')->where('id', $context['operation_id'])->value('actor') : null;
            if (isset($context['operation_id']) && $creator !== 'user:'.$parameters['plan']['actor_user_id']) throw new RuntimeException('creator_changed');
            $this->service->prepared($parameters, $this->actor($parameters), $context['operation_id'] ?? null);
            return ['ok' => true, 'parameters' => $parameters];
        } catch (Throwable) {
            return ['ok' => false, 'errors' => ['reversal_restatement_precondition_failed']];
        }
    }

    public function execute(array $plan, array $context): array
    {
        if (($plan['ok'] ?? false) !== true) throw new RuntimeException('reversal_plan_not_ready');
        $p = $plan['parameters'];
        $result = $this->service->execute($p['plan'], $this->actor($p), $p['decision_reference'], $context['operation_id']);
        return ['ok' => true, 'already_applied' => $result['state'] === 'already_applied',
            'snapshot' => ['proof_id' => $result['restatement_id'], 'parameters' => $p, 'operation_id' => $context['operation_id']]];
    }

    public function verify(array $plan, array $result): array
    {
        $s = $result['snapshot'];
        return $this->service->verifyProof($s['proof_id'], $this->actor($s['parameters']));
    }

    public function rollback(array $snapshot, array $context): array
    {
        if ($snapshot['operation_id'] !== $context['operation_id']
            || !DB::table('pop_execution_records')->where('id', $snapshot['proof_id'])
                ->where('operation_id', $context['operation_id'])->where('phase', 'reversal-proof')->exists()) {
            throw new RuntimeException('reversal_rollback_request_mismatch');
        }
        $result = $this->service->rollback($snapshot['proof_id'], $this->actor($snapshot['parameters']));
        return ['ok' => true, 'state' => $result['state']];
    }
}
