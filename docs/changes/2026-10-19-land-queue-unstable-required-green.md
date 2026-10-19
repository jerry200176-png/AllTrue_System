## 2026-10-19 — fix(ci): land-queue merges UNSTABLE when required checks are green
<!-- release-notes: silent_ship=silent-2026-10-19-land-queue-unstable-required-green -->
<!-- silent-reason: land-queue CI merge policy only; no staff-facing product change -->

Land-queue previously required GitHub `mergeStateStatus=CLEAN`. A cancelled or
failing *non-required* check (e.g. Missing Tests Warning concurrency cancel)
leaves the PR `UNSTABLE` even when every ruleset-required context is green, so
the queue waited forever. Treat `UNSTABLE` like `CLEAN` when required checks
pass and review threads are clear; keep waiting on `BLOCKED` / pending required.
