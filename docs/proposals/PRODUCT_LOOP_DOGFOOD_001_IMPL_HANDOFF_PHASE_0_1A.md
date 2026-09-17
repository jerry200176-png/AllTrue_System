# Implementation handoff — PRODUCT_LOOP_DOGFOOD_001 Phase 0 + 1a

**Status:** Ready for implementation **after** Founder Plan decision commit is on the collaboration branch / `main`.  
**Do not start implementation in the same change set as the decision docs.**

## Bindings

| Item | Value |
|------|--------|
| SourceRef | `alltrue:bug_report:290` |
| GitHub | [#2800](https://github.com/jerry200176-png/AllTrue_System/issues/2800) |
| GoalContract | `docs/proposals/PRODUCT_LOOP_DOGFOOD_001_GOAL_CONTRACT_PHASE_0_1A.json` |
| Founder decision | `docs/proposals/PRODUCT_LOOP_DOGFOOD_001_FOUNDER_PLAN_DECISION.md` |
| Reconciled proposal | `docs/proposals/PRODUCT_LOOP_DOGFOOD_001_INAPP_290_CALENDAR_COURSE_SESSION_EDITING.md` |
| CubeLV review SHA | `5d7d05c05114950a0eebe85e3c0098a33be12274` |
| Proposal SHA | `6b70ca51f2110f3a7020bbf852e742727d8f50cf` |

## Authorized work

1. **Phase 0:** Course Management–hosted calendar-shaped **read** of one course’s planned occurrences (materialized + projected via existing reads). Feature flag; reversible.
2. **Phase 1a:** Future **CREATE** only through existing:
   - `POST .../student-classes/{id}/add-session` (+ `/check`)
   - `POST .../student-classes/{id}/manual-sessions` (+ `/check`)
3. Thin client only — **no** parallel calendar writer; **no** schema/RRULE; **no** SoT fork.

## Forbidden in this unit

- Cancel (Phase 1b) — existing cancel may auto-append count-mode tail via `tryExtendOnLeave` → `appendTailAfterLeave`
- Time/teacher edit; substitute/reschedule from this surface
- “This and future” / recurrence rewrite
- Billing / Charge / Paid / Invoice mutation
- Migrations; auth/identity/permission changes
- Production flag activation without separate GO
- Restate / CP expansion

## Test expectations (must land with impl)

- Read projection correctness
- Create refusal/conflict cases (existing API errors)
- **No** Charge / Paid / Invoice mutation on Phase 0 / 1a paths
- Flag-off rollback

## Rollback

Feature flag / entry-point removal.

## Worker note

CubeLV, Cursor, Codex, and CLI agents are replaceable clients. Bind to GoalContract scope + fingerprints; GitHub is evidence/discussion only — not the orchestration state machine.
