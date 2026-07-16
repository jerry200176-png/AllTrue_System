<?php

namespace App\Operations\Contracts;

/**
 * Domain-agnostic operation engine (POP).
 * Phase 1: interface only — no production execute.
 */
interface OperationEngine
{
    public function plan(OperationContext $context): Plan;

    public function dryRun(OperationContext $context, Plan $plan): DryRunResult;

    public function validate(OperationContext $context, Plan $plan): ValidationResult;

    public function execute(OperationContext $context, Plan $plan): ExecuteResult;

    public function verify(OperationContext $context, ExecuteResult $result): VerificationResult;

    public function rollback(OperationContext $context, string $snapshotId): RollbackResult;

    public function snapshot(OperationContext $context, Plan $plan): SnapshotRef;

    public function audit(OperationContext $context, string $phase, array $payload): AuditRef;
}
