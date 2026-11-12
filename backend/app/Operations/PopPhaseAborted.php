<?php

namespace App\Operations;

use Closure;
use RuntimeException;

/**
 * Thrown by PopOperationService::runPhase out of the transaction `run()` opened, for a strategy whose data work must not
 * outlive a failed phase (#5236 review P1). Its message is fixed and names no database error, so Laravel never takes the
 * "concurrency error in a nested transaction" branch for it: the transaction `run()` opened is rolled back as a whole
 * (ROLLBACK, or ROLLBACK TO SAVEPOINT when a caller already holds a transaction). `run()` then writes the failed receipt
 * through `$recordFailure` in a transaction of its own. Never leaves PopOperationService.
 */
final class PopPhaseAborted extends RuntimeException
{
    /** @param Closure(): array<string,mixed> $recordFailure */
    public function __construct(public readonly Closure $recordFailure)
    {
        parent::__construct('pop_phase_aborted');
    }
}
