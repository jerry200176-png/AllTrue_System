# AllTrue engineering north star（Claude Code / Codex / Cursor 必讀）

> **Status:** Accepted as the current engineering campaign (docs).  
> **Date:** 2026-08-15  
> **Audience:** every agent session on this repo  
> **Detail for the schedule campaign:** [`RFC_SCHEDULE_OCCURRENCE_IDENTITY.md`](RFC_SCHEDULE_OCCURRENCE_IDENTITY.md)

This file is the short shared intent. Do not invent a second strategy in chat.

## What this product is

AllTrue is a **live multi-campus tutoring OS**: scheduling, attendance, billing,
PII, LINE, RFID. It is **not** a small-tool harness project. Do not install a
seven-file `spec.md` / `roadmap.md` / `journal.md` tree here. Constitution,
INDEX, control-plane, and `AI_REGRESSION_LESSONS.md` stay authoritative.

## Shared intent (one paragraph)

Do **not** rewrite Vue or Laravel. Do **not** “clean up” 268 docs into a new
pack. Improve the product by (1) one architecture line that stops repeat
production bugs in reschedule, and (2) extracting code only when you already
have to touch a god-file for a real bug.

## Current campaign (only one architecture line)

**TD-076 / R102 / R103:** `schedules` occurrence identity.

Reschedule today appends an immutable chain (`rescheduled` + new `scheduled`
linked by `original_schedule_id`). Every reader must walk the chain correctly.
That shape caused production incidents (ghost boxes; calendar vs course
management). Frontend dedupe is a patch. The root fix is: **one stable
occurrence identity → at most one live row; UPDATE on reschedule; history in
append-only `schedule_change_log`.**

Keep [`ADR_004_atomic_reschedule_boundary.md`](../ADR_004_atomic_reschedule_boundary.md):
one transaction, `RescheduleSessionService::execute()`, idempotent replay.
The campaign changes **how the row is stored**, not the atomic write boundary.

Founder GO 2026-08-15: **Phase 1+2 only** (nullable identity columns + dual-write
behind `FEATURE_SCHEDULE_OCCURRENCE_V2`, default off). No unique index, no
backfill, no reader switch, no stop-chain until a later GO.

## Parallel work (allowed without waiting for TD-076)

| Do | Don't |
|---|---|
| Product bugs, in-app reports, billing/auth with existing SOP | Big-bang frontend rewrite |
| Touch-and-extract: if you must edit `StudentClassController`, `ClassSessionController`, `LearningRecordsPage.vue`, or `CourseManagement.vue`, extract a service/composable **in the same PR** with tests that prove behavior unchanged | Open a “split the 8000-line Vue file” epic with no user bug |
| Laravel 8 upgrade (TD-014) as its own Founder-gated track | Mix framework upgrade with schedule identity |
| Docs pointers, tests, inventory for this RFC | New authority docs that fight INDEX / control-plane |

## Multi-agent rules (Claude Code + Codex + Cursor)

1. One repository, one branch, one PR. Never mix Sunrise.
2. Before coding schedules / calendar / course-management merge: read this file
   + the RFC + R102/R103 + ADR-004.
3. Handoff with [`docs/sop/AGENT_HANDOFF.md`](../sop/AGENT_HANDOFF.md). Downstream
   reads artifacts, not your chain of thought.
4. `[INT]` on any multi-PR phase: the next agent must be able to run the
   commands listed in the RFC phase without guessing.
5. Stop-the-line: Pi tests, force-push, production edit, migrate on Pi, merge
   without Founder where policy requires it.

## Done looks like (campaign)

- Golden tests fail if a reschedule creates a second live row for the same
  occurrence identity (after cutover).
- Calendar and course management read the same occurrence set for the R102/R103
  fixtures.
- Chain-walk dedupe in the frontend becomes a belt-and-suspenders, then is
  removed only after a Founder-approved cleanup PR.
- TD-076 marked Done with migration + rollback evidence, not “we added another
  merge rule”.
