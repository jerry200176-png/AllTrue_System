<?php

namespace App\Operations\Strategies;

use RuntimeException;

/**
 * Thrown inside the write transaction of CourseContractChainRebalanceStrategy so that every move made so far is undone;
 * carries the refusal (`ok=false`, codes, ids) that execute / rollback then return. Never leaves the strategy.
 */
final class ChainRebalanceRefused extends RuntimeException
{
    /** @param array<string,mixed> $result */
    public function __construct(public readonly array $result)
    {
        parent::__construct('chain_rebalance_refused');
    }
}
