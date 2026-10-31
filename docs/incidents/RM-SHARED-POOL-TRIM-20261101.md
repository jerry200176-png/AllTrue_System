# Repair Manifest — 共用堂數方案清掉多預排的未來課（2026-11-01）

Status: POP operation `shared-pool-trim-overplan-20261101` (catalog v16). Nothing runs until the Founder approves a specific dry-run digest.
Risk: R3/T3. Founder GO **A** (2026-11-01, GitHub #4734).

## Purpose

Before #4730, a shared count pool (one `CoursePackage`, `billing_mode=count`, more than one active member) pre-planned the whole total for
**every** subject. Read-only probe (`inapp-package-ledger-probe.yml`, `all_packages`, campus ids 1–20, 2026-11-01) found 52 pools with 1,814
planned lessons past what is left: campus 2 has 36 pools / 1,504 lessons, 3 has 12 / 133, 4 has 1 / 11, 17 has 3 / 166.

## Plan (computed at dry-run, ids only, no names)

Parameters:
- `campus_ids`: e.g. `[2,3,4,17]`. One digest covers all of them.
- `decision_reference = trim-shared-pool-overplan-20261101`.
- `expected_digest`: empty on the dry-run, then the dry-run's `digest` on the approved run.

Per pool in scope, the lessons come from `SharedPackagePlanningService::sharedPoolExcess`, the same rule a total cut applies:
- Planned lessons are counted as the plan summary counts them.
- A slot that can't go keeps its place first: today's, not `scheduled`, a contract exception, or anything pointing at it (`ClassSessionDeleteGuard::referencedSessionIds`: sign-ins, both ledgers, LearningRecord, coverages, corrections, payroll, TrueFit and more).
- The removable rest keep the earliest dates up to what is left. Everything after that is a manifest row: package, session, course, date and start.
- `totals.by_pool` gives `remaining`, `planned`, `remove` and `blocked` per pool.

## Execute

In one transaction:
1. Lock the pools and recompute. The digest must match, otherwise `shared_pool_trim_digest_drift` aborts.
2. Snapshot every row whole.
3. Delete through the model, so `ClassSessionObserver::deleted` writes `schedule_audit_logs` with the old row.
4. Write a strict `pop.shared_pool_trim` audit event.

No billing, ledger, `Charge`, `Paid`, `SessionCount` or counter is written. Future rows don't count as used.

Rows are **deleted, not cancelled**. A cancelled date stays taken on purpose, so a cancelled holiday is never refilled. A renewal after a cancel would therefore plan past the far-future cancelled dates and leave a gap. Deleted dates are re-filled by `fillSharedPool` when a pool is renewed.

A rerun removes nothing (`state=after`).

## Flow

1. Dry-run once for all listed campuses. Review the totals and the per-pool counts.
2. The Founder approves exactly that digest: `founder-exact-shared-pool-trim`, super_admin, bound to the deployed SHA.
3. Execute (Pi-local), then verify: the deleted ids are gone and no executed pool still has removable excess.
4. Closeout: re-run the probe (`overplanned` should be 0, apart from blocked slots), post on #4734, and publish a staff update card.

## Rollback

From the stored snapshot, re-insert each row with its id (computed columns `StartTimeHM` and `ActiveSlotFlag` are left to the DB). A row is skipped when its id exists again, or its course has a lesson at that date and time again (for example, a renewal re-planned it). Skips are reported as `ok=false, partial=true`. Rollback is a new Founder-approved POP request.

## Dispatch adapter

`.github/workflows/pop-shared-pool-trim.yml` is a copy of the unbilled-backlog adapter. It uses the `production-activation` environment, pinned Pi SSH, the deployed-SHA check and a short-lived Pi-local super_admin session.
- **Dry-run** prints state, digest, the per-pool counts and ids-only rows, then returns without polling.
- **Approve** needs the reviewed digest and `state=pinned`, then polls Pi-local execute and verify.
