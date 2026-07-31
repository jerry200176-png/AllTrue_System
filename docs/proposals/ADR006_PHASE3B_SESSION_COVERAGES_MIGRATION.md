# ADR-006 Phase 3B — `session_coverages` migration proposal

> **Status:** Proposal only — **awaiting Founder GO** before merge / `migrate --force`.  
> Companion dry-run code: Phase 3A (`AllocateSessionCoveragePlanner`, `sessions:plan-coverage`).

## Why existing schema is insufficient

- `ClassSession` has no coverage status (held / consumed / released).
- `course_packages.remaining_sessions` is entitlement balance, not per-occurrence hold lifecycle.
- `package_session_ledger` / deduction paths are **consumption** — ADR forbids conflating materialization/coverage with deduction.
- Without a coverage row, pool-level concurrent Ensure cannot lock holds independently of attendance.

## Changed tables / columns / indexes

| Object | Change |
|---|---|
| `session_coverages` (new) | `id`, `package_id?`, `student_class_id`, `class_session_id?`, `campus_id?`, `occurrence_date`, `start_hm`, `status`, `commitment_fingerprint?`, timestamps |
| Unique | `(student_class_id, occurrence_date, start_hm)` |
| Indexes | `(package_id, status)`, `(campus_id, occurrence_date)`, `class_session_id` |

**No DEFAULT on `status`** — avoids M2 silent backfill of historical meaning.

## Backfill

- **Not required** for empty table create.
- Historical ClassSession are **not** auto-marked held; allocation writes are a separate GO.

## Lock / downtime risk

- `CREATE TABLE` — low; online DDL on MySQL/MariaDB typically metadata-only for empty table.
- No rewrite of `ClassSession` / `course_packages`.

## Rollback limits

- `down()` drops `session_coverages` only.
- If later rows exist, drop loses coverage marks (entitlement ledger untouched).
- Rollback safe only before coverage writers are activated.

## Production verification plan (after GO)

1. `php artisan migrate:status` shows pending then ran.
2. `DESCRIBE session_coverages` matches proposal.
3. Confirm zero rows initially; no change to `RemainingSessions` / ledger counts.
4. Smoke: `sessions:plan-coverage` still dry-run-only until write path GO.

## Explicit non-goals (this migration)

- No Kernel job
- No coverage writer activation
- No attendance / deduction integration
- No data repair
