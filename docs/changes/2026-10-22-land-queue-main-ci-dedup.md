## 2026-10-22 — fix(ci): avoid duplicate main CI after queue merges
<!-- release-notes: silent_ship=silent-2026-10-22-land-queue-main-ci-dedup -->
<!-- silent-reason: internal queue fallback repair; no staff-facing product change -->

Owner-token queue merges already trigger push CI, but its run can be absent from
the first API lookup. Main `12acf0a1` had both push run `39711073195` and fallback
dispatch `39711074732`; main `60507093` repeated this with `39713759818` and
`39713761971`. The fallback now checks at 0, 5 and 15 seconds and reuses an active
or successful `ci.yml` push/dispatch run on the exact main SHA. Failed/cancelled
history, other workflows, other SHAs and PR runs do not suppress needed CI.
After three complete negative reads, the GITHUB_TOKEN path still dispatches once,
provided main has not moved. API errors, incomplete data or unknown status block
dispatch with an explicit error. This bounded visibility grace is not an atomic
GitHub idempotency guarantee; a push indexed after the final read can still race.

Merge eligibility, review/authorization, required checks and token/protection
configuration are unchanged. T3 merge authorization remains required. Rollback:
revert this fallback repair through normal review. Verification uses stubbed API,
timer and dispatch functions; no real workflow dispatch/cancellation is needed.
