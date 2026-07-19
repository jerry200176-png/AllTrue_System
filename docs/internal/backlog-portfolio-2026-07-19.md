# Backlog Portfolio — 2026-07-19

**Snapshot:** Actions run `29685054559` (Issues dump) · 62 open Issues · 0 open PRs  
**WIP caps:** incident≤1 · impl PR≤2 · investigation≤2 · data repair≤1 · Founder-wait separate

## Classification (re-scored; labels not trusted)

| Bucket | Count | Action |
|--------|------:|--------|
| Founder-wait / needs-decision | 35 | Do not start code |
| Blocked / external dependency | 16 | Owner / env |
| Tech debt / P2–P3 | ~8 | After P0/P1 |
| Autonomous-safe P1 | 2 | **#1342** leave CSV, **#1262** SignIn orphan |
| Evidence pending | 1 | **#1343** TD-059 |

Artifact (CI size gate): full JSON kept on Actions run `29685054559` (`open-issues-dump`), not committed.

## This-round WIP

| Slot | Work | Status |
|------|------|--------|
| Investigation | #1343 TD-059 Pi audit | SSH fix → re-run |
| Parallel | #1342 HC CSV + SOP | SOP on main; CSV pending Pi |
| Next | #1262 orphan classify | After TD-059 evidence |

## Explicitly deferred

Billing/repair Founder gates (#1130, #1096, #959, #1152), UI/architecture epics, security host/IAM owner items.

## Evidence update (run 29685472249)

- #1342 HC pack: **19** sessions redacted CSV committed under `operations/closeout/artifacts/`.
- #1343 TD-059: Pi reachable; metric query retry (DB FQCN).
- Next autonomous P1 after TD-059 numbers: **#1262**.

## TD-059 go/no-go

**NO-GO schema** (run `29685602058`): 46 multi-member packages, **0** partial-minute package ledger hits, **0** drift. Next autonomous P1: **#1262**.
