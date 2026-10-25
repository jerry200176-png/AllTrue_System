## 2026-10-25 — official Laravel 12 security/compatibility candidate (#977)
<!-- release-notes: silent_ship=silent-2026-10-25-laravel12-security-compatibility -->
<!-- silent-reason: unactivated framework dependency and compatibility candidate -->

Prepare from current main, separately from #4385 and the stale closed #2833.
Use official Laravel 12.70.1, compatible Sanctum/Carbon/Symfony/testing tools,
and remove the prior advisory-ignore entries. The locked dependency graph is
audited without ignores; this is an official package upgrade, not a version
alias or a vendor backport. No migration, feature activation or auth/campus
authorization change is included.

Preserve Carbon 2's complete-minute attendance totals and complete-second,
absolute RFID debounce/substitute-undo windows. Keep the reminder template
picker's existing absolute day difference; eligibility and senders are unchanged.
The retired public payment-report submit method is not revived or modified.
Rename private test/console helpers that collide with new parent methods, make
the existing data providers static, and migrate the coverage XML format while
preserving its app scope and uncovered files.

Add precise relationship PHPDocs without changing queries. Keep PHPStan level 5,
the existing app scope/exclusions and original matching baseline fields. Remove
only machine-proven obsolete suppression records. Larastan's newly introduced
optional env-style rule is disabled because existing live maintenance/security
gates must remain live; no existing runtime guard or test is disabled.

Local preparation only: publication, merge and production activation remain
separate decisions. Before activation verify the actual PHP 8.2+/extensions,
exact range, runtime/config/process state and direct billing/scheduling/auth
contracts. Rollback needs the prior approved locked dependency artifact and
process/cache verification; no production data repair is authorized here.
