<?php

namespace App\Operations\Contracts;

/**
 * Replaceable execution plane adapter.
 * Phase 1: interface only.
 */
interface ExecutionPlane
{
    public function id(): string;

    public function canRun(OperationRequest $request): bool;

    public function run(OperationRequest $request, string $phase): ExecutionHandle;

    public function fetchLogs(ExecutionHandle $handle): ExecutionLogs;

    public function fetchArtifact(ExecutionHandle $handle, string $name): ?Artifact;
}
