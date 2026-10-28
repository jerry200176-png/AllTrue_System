## 2026-10-29 — chore(probe): package ledger probe matches a lesson not taught to live lessons on the same day
<!-- release-notes: silent_ship=package-ledger-probe-same-day -->
<!-- silent-reason: Operator-only read-only diagnostic; no screen or API behavior change. -->
- #4455: `unchargeable_session_rows` now joins each live chargeable member lesson on the same date at any start and prints its start (`twin_start_time`). A package creation seed sits at a default start, so its re-created lesson can be at another. READ ONLY session, rolled back, ids/dates/statuses only.
