## 2026-10-17 — ci: Phase-A triage optional max-5 batch (Draft)

Extend `bug-phase-a-triage.yml` with optional `batch_json` (1–5 rows): one `production-activation` Founder gate, validate-all-then-write, fail-stop, idempotent per-row write via existing REMOTE script, `close_as_logged` forced false (triaged only; supports `suggestion`). Single-item dispatch remains the default path. No live permission / kill-switch / deploy changes in this PR.

staff_update: silent_ship
