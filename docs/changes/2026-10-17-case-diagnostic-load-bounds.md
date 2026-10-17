# Fail-closed amount diagnostic load bounds

`silent_ship`: operator-only diagnostic guard; no staff-facing behavior change.

The restricted amount-source case now admits complete financial/audit inputs only when each of its seven row sets has at most 200 rows (SQL LIMIT 201 detects overflow). The canonical resolver's ClassSession queries share a 2,000-row hydration budget, with a remaining-plus-one SQL limit; an overflow aborts the whole diagnosis rather than computing a partial amount. Existing target/course/month predicates and returned fields are unchanged.

The case pins reads to the transaction's write PDO, configures and verifies a SESSION-only statement timer (MariaDB max_statement_time=2 seconds; supported MySQL max_execution_time=2000 milliseconds), and fails before target reads when unsupported. Its remote PHP invocation requires GNU timeout, with TERM at 120 seconds and KILL five seconds later. Failure replaces all diagnostic output with fixed status fields; SQL/exception text and partial amounts are never serialized. All evidence remains in the existing encrypted path.

These are admission and cooperative cancellation safeguards, not a hard server-resource guarantee: row limits do not bound rows scanned/sorted, server timers check interruption at implementation-defined points, and killing PHP does not prove immediate server-side SQL cancellation. Production engine/index/deadline behavior remains unverified. No global settings or credentials change. Queue, merge and live execution remain held for an explicit decision on this revised scope and remaining runtime limitation.

Validation: synthetic oversized financial/session inputs, statement-timeout and unsupported/mismatched-setting failures, watchdog termination, existing canonical display projection, all generated PHP case lints, restricted-input/encryption/public-output contracts. No production queries or real student data used. Revert the diagnostic guard commit to restore the preceding probe behavior; do not run the reverted unbounded probe as a rollback action.

References: [MariaDB statement timer limitations](https://mariadb.com/docs/server/ha-and-performance/optimization-and-tuning/query-optimizations/aborting-statements), [MySQL session max_execution_time](https://dev.mysql.com/doc/refman/8.0/en/server-system-variables.html#sysvar_max_execution_time).
