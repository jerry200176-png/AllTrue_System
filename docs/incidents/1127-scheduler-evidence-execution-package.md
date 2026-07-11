# #1127 Scheduler Output Evidence — Release Execution Package

## Scope

- Incident: GitHub #1127; historical monitoring change: PR #1129 / `cae21d3`.
- In scope: preserve private, per-job overnight command output on the Pi; record PII-free completion events; expose only aggregate evidence through existing `pi-health.yml`.
- Out of scope: job business logic, task timing, schema, cron ownership, direct Pi access, and raw log export.

## Risk and security assessment

| Dimension | Assessment | Control |
|---|---|---|
| Data integrity | Medium | Existing commands retain their arguments and cadence; evidence writes only local log files. |
| Availability | Low | Missing/unreadable evidence marks Pi Health critical; it does not suppress a business job. |
| PII / RFID | Pass | GitHub output contains fixed job keys, timestamps, statuses, and aggregate counts only; raw output remains on Pi. |
| Tenant isolation | Pass | Evidence queries report aggregate global counts only and never return campus, student, teacher, RFID, or course identifiers. |
| Rollback | Low | Revert the single release commit through the normal PR path; no migration or data rollback is needed. |

## Verification matrix

| Job | Taiwan schedule | Required production evidence | Success criterion |
|---|---:|---|---|
| `teacher-signin:close-orphans` | 00:05 | one successful ledger entry, private output summary, remaining-orphan count | one run; remaining count 0 |
| `reconcile:nightly` | 02:00 | one successful ledger entry and report aggregate | one run; mismatch count recorded |
| `student-signin:close-orphans` | 02:30 | one successful ledger entry, private output summary, remaining-orphan count | one run; remaining count 0 |
| `rfid:prune-pending` | 03:00 | one successful ledger entry, private output summary, expired-pending count | one run; remaining count 0 |
| `learning-records:drift-check --fix` | 03:20 | one successful ledger entry and post-fix aggregate | one run; residual drift recorded |
| `sessions:audit-stranded --json` | 03:40 | one successful ledger entry and JSON aggregate | one run; stranded totals recorded |
| `learning-records:backfill-missing` | 03:50 | one successful ledger entry, created count, missing-LR count | one run; remaining count 0 |
| `bugs:verify-reproductions --json` | 04:00 | one successful ledger entry and condition states | one run; enforced conditions `FIXED-OK` |

`sessions:generate-forward` (03:45) and `ops:business-digest` (04:10) receive the same execution evidence because R68 applies to every current scheduled task.

## Validation and release

- Local: scheduler-evidence unit test, PHP syntax checks, Docs Integrity, Control Plane lint, workflow syntax review.
- CI: required checks must pass on the PR.
- Release: merge only through protected `main`; [deploy.yml](../../.github/workflows/deploy.yml) remains the sole deploy authority.
- Post-release: manually dispatch existing Pi Health after deployment to prove the monitor can read the command, then use the next full overnight cycle for closure evidence. Do not close #1127 from a heartbeat alone.

## Rollback

Revert the release commit through a normal PR. The existing cron driver and all business jobs remain unchanged; evidence files may be retained as operational history.
