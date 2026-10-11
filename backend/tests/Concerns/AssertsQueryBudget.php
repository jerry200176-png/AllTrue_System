<?php

namespace Tests\Concerns;

use Closure;
use Illuminate\Support\Facades\DB;

/** Query-count budget for N+1 regression tests (see DirectorEndpointQueryBudgetTest). */
trait AssertsQueryBudget
{
    /** Runs $fn and fails if it issued more than $max SQL queries; returns the actual count. */
    protected function assertQueryCountAtMost(int $max, Closure $fn): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $fn();
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertLessThanOrEqual(
            $max,
            count($queries),
            'Query budget exceeded ('.count($queries)." > {$max}). Likely N+1; queries:\n".implode("\n", array_map(fn ($q) => $q['query'], $queries))
        );

        return count($queries);
    }
}
