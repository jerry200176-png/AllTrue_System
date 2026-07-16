<?php

namespace App\Operations\Contracts;

/**
 * Pluggable operation strategy (Open/Closed).
 * Phase 1: interface only.
 */
interface OperationStrategy
{
    public function id(): string;

    public function plan(OperationTarget $target): Plan;

    public function dryRun(Plan $plan): DryRunResult;

    public function execute(Plan $plan, ExecutionContext $context): ExecuteResult;

    public function verify(Plan $plan, ExecuteResult $result): VerificationResult;

    public function rollback(SnapshotRef $snapshot, ExecutionContext $context): RollbackResult;

    /** @return InvariantInterface[] */
    public function invariants(): array;

    public function snapshotScope(Plan $plan): SnapshotScope;
}
