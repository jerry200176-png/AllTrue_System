## 2026-10-21 — ci(ops): weekly Actions usage report
<!-- release-notes: silent_ship=silent-2026-10-21-actions-cost-report -->
<!-- silent-reason: ops workflow change; no product UI change -->

A new weekly workflow comments on a single active tracking issue with completed attempts and approximate wall-clock minutes per workflow, flags workflows above 48 completed attempts/day in the creation cohort, and reminds the Founder to set a GitHub spending limit once. Schedule/manual executions serialize with a non-cancelling concurrency group. It reads every open-issue page before selecting the marker, including unlabelled trackers; lookup/schema errors or duplicate active trackers abort before writes. When creating a tracker, it reads every label page and provisions `ops-hygiene` first if missing. Existing open trackers are reused; closed trackers stay closed, and the next report may create a new active tracker.

The bounded cohort contains runs originally created in the preceding seven days; historical rerun attempts are enumerated, and non-completed or cutoff-exceeding attempts are separately omitted. Reruns this week of older runs are outside coverage, so the report explicitly disclaims complete seven-day repository usage and billing/token cost. Capped creation-time searches split into disjoint second-resolution windows before pagination; irreducible caps, partial/changed pages, duplicate IDs, or missing attempt data abort before any issue write. Minutes use each attempt's `updated_at - run_started_at`, including internal waiting/metadata delay and counting parallel jobs as one elapsed span, rather than billed compute time.

Rollback: revert this workflow/script change. The existing T3 workflow/control-plane merge authorization remains required.
