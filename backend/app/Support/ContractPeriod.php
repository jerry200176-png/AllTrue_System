<?php

namespace App\Support;

/**
 * "This lesson is dated before its contract's StartDate" (in-app #536, #5198 option A).
 *
 * The one definition: the single-lesson contract reassign allows it, StartDate is not moved, and the screens show a
 * plain 「合約期間外」 label. It is DERIVED at read time and never stored: `ClassSession.IsContractException` means
 * "off the contract's weekly pattern" (ContractScheduleMatcher), a different thing; do not overload it.
 */
final class ContractPeriod
{
    /** Same rule as {@see lessonBeforeStart()} for SQL, for `cs` = ClassSession and `sc` = StudentClass (aliases are a contract of the caller). */
    public const SQL_BEFORE_START = "cs.SessionDate IS NOT NULL AND sc.StartDate IS NOT NULL AND DATE(cs.SessionDate) < DATE(sc.StartDate)";

    public static function lessonBeforeStart(mixed $sessionDate, mixed $contractStartDate): bool
    {
        $lesson = substr(trim((string) ($sessionDate ?? '')), 0, 10);
        $start = substr(trim((string) ($contractStartDate ?? '')), 0, 10);

        return $lesson !== '' && $start !== '' && $lesson < $start;
    }
}
