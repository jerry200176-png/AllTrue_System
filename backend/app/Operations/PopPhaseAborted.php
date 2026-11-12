<?php

namespace App\Operations;

/** #5236 P1: carries the failed-receipt writer out of run()'s transaction, which is rolled back whole before it is called. The fixed message names no database error. */
final class PopPhaseAborted extends \RuntimeException
{
    public function __construct(public readonly \Closure $recordFailure)
    {
        parent::__construct('pop_phase_aborted');
    }
}
