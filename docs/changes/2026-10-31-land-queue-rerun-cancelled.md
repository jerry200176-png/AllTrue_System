## 2026-10-31 — ci(land-queue): rerun cancelled-only required checks once per head (#4700)
<!-- release-notes: silent_ship=silent-2026-10-31-land-queue-rerun-cancelled -->
<!-- silent-reason: merge-queue internals only; no product change -->
- When every non-green required check on a queued PR is `CANCELLED` (runner starvation or a concurrency cancel, with no failure or timeout), the queue reruns those workflow runs once (`rerun-failed-jobs`) and keeps the label. Before this it dropped the PR, as happened to #4690, #4696 and #4698. The rerun is recorded with a per-head marker, written only after GitHub accepts it. A second cancellation on the same head rejects as before. Any real failure still rejects at once.
