## 2026-10-19 — fix(ci): land-queue merges UNSTABLE when required checks are green

**silent_ship**

Land-queue previously required GitHub `mergeStateStatus=CLEAN`. A cancelled or
failing *non-required* check (e.g. Missing Tests Warning concurrency cancel)
leaves the PR `UNSTABLE` even when every ruleset-required context is green, so
the queue waited forever. Treat `UNSTABLE` like `CLEAN` when required checks
pass and review threads are clear; keep waiting on `BLOCKED` / pending required.
