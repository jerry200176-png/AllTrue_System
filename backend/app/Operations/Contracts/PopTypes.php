<?php

namespace App\Operations\Contracts;

/** Phase 1 placeholder — expanded in Phase 2. */
class OperationContext
{
    public function __construct(
        public string $operationId,
        public string $strategyId,
        public array $target = [],
    ) {
    }
}

class OperationTarget
{
    public function __construct(public array $payload = [])
    {
    }
}

class ExecutionContext
{
    public function __construct(public string $actor = 'system')
    {
    }
}

class OperationRequest
{
    public function __construct(public string $operationId, public string $status = 'draft')
    {
    }
}

class Plan
{
    public function __construct(public array $actions = [])
    {
    }
}

class DryRunResult
{
    public function __construct(public bool $ok = true, public array $diff = [])
    {
    }
}

class ValidationResult
{
    public function __construct(public bool $ok = true, public array $errors = [])
    {
    }
}

class ExecuteResult
{
    public function __construct(public bool $ok = true, public array $meta = [])
    {
    }
}

class VerificationResult
{
    public function __construct(public bool $passed = true, public array $checks = [])
    {
    }
}

class RollbackResult
{
    public function __construct(public bool $ok = true)
    {
    }
}

class SnapshotRef
{
    public function __construct(public string $path)
    {
    }
}

class SnapshotScope
{
    public function __construct(public array $tables = [])
    {
    }
}

class AuditRef
{
    public function __construct(public string $eventId)
    {
    }
}

class ExecutionHandle
{
    public function __construct(public string $executionId)
    {
    }
}

class ExecutionLogs
{
    public function __construct(public string $content = '')
    {
    }
}

class Artifact
{
    public function __construct(public string $path, public string $name)
    {
    }
}

interface InvariantInterface
{
    public function id(): string;

    public function check(Plan $plan, ?ExecuteResult $result = null): bool;
}
