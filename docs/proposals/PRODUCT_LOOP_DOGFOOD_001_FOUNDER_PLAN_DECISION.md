# PRODUCT_LOOP_DOGFOOD_001 — Founder Plan Decision

**WorkItem:** `PRODUCT_LOOP_DOGFOOD_001`  
**SourceRef:** `alltrue:bug_report:290`  
**GitHub Issue:** [#2800](https://github.com/jerry200176-png/AllTrue_System/issues/2800)  
**Decision date:** 2026-09-17 (Asia/Taipei)  
**Decision class:** Plan GO / AMEND (implementation unit narrowed)

---

## Evidence anchors (exact SHAs)

| Artifact | SHA |
|----------|-----|
| Proposal (merged PR #3040) | `6b70ca51f2110f3a7020bbf852e742727d8f50cf` |
| CubeLV challenge review (authoritative review commit) | `5d7d05c05114950a0eebe85e3c0098a33be12274` |
| This decision + reconciled Plan (this PR head) | *filled after commit; see PR / `git rev-parse HEAD`* |

Related paths:

- Proposal: `docs/proposals/PRODUCT_LOOP_DOGFOOD_001_INAPP_290_CALENDAR_COURSE_SESSION_EDITING.md`
- CubeLV review: `docs/proposals/PRODUCT_LOOP_DOGFOOD_001_CHALLENGE_REVIEW.md`
- GoalContract: `docs/proposals/PRODUCT_LOOP_DOGFOOD_001_GOAL_CONTRACT_PHASE_0_1A.json`
- Impl handoff: `docs/proposals/PRODUCT_LOOP_DOGFOOD_001_IMPL_HANDOFF_PHASE_0_1A.md`

---

## Independent verification recorded at decision time

### F1 — Dedup (OBSERVED via GitHub Issues API)

| Issue | State | Duplicate of #290? |
|-------|-------|--------------------|
| [#2808](https://github.com/jerry200176-png/AllTrue_System/issues/2808) | OPEN | No (ambient volume / tracks) |
| [#2905](https://github.com/jerry200176-png/AllTrue_System/issues/2905) | OPEN | No (school suggestions; code may be merged separately) |
| [#2906](https://github.com/jerry200176-png/AllTrue_System/issues/2906) | OPEN | No (grade promotion) |
| [#2908](https://github.com/jerry200176-png/AllTrue_System/issues/2908) | OPEN | No (director+teacher account / identity) |
| [#2179](https://github.com/jerry200176-png/AllTrue_System/issues/2179) | OPEN | No (capacity display / cannot reproduce) |

**Rule:** Do **not** infer delivery status from issue open/closed state. `#2905` remaining OPEN does not contradict prior V1 merge evidence for schools; `#290` / `#2800` delivery is tracked by code + CHANGELOG + Closure Gate, not by sibling issue state.

### F2 — CHANGELOG at proposal anchor (OBSERVED)

At `6b70ca51`, `docs/CHANGELOG.md` contains **no** delivery claim for in-app `#290` / GH `#2800` (`git grep` / content search: no hit).

### F3 — Billing gate correction (OBSERVED)

`ClassSessionController` applies `syncSessionChargeForTimeChange` only when the PATCH payload includes actual `start_time` / `end_time` changes (`$hasTimeChange`). Therefore billing detection is **not** the primary blocker for a **create-only** calendar client that never sends time edits.

**Still required:** regression tests asserting Phase 0 / Phase 1a paths do **not** mutate `StudentClass.Charge` / `Paid` / Invoice.

### New finding — cancel → count-mode tail append (OBSERVED)

Existing `PATCH` ClassSession cancellation can invoke `tryExtendOnLeave()` → `CourseLeaveCascadeService::appendTailAfterLeave()`, which may append a replacement tail occurrence for count-mode courses. This is **product semantics**, not a bug to bypass for calendar UX convenience.

---

## Decisions

### 1. Phase 0 — GO

- Course Management–hosted calendar-shaped **read model** for one course’s planned occurrences.
- Course Management is the **permanent host** for this slice unless a future Founder decision explicitly changes `#1922` / SmartCalendar authority.
- No new write API.
- No new schema / RRULE / occurrence authority.
- Feature-flagged and reversible (flag off / remove entry point).

### 2. Phase 1a — GO (create-only)

- Future **CREATE** only.
- Reuse existing authoritative writers only:
  - `POST /api/v1/student-classes/{id}/add-session` (+ `/check`)
  - `POST /api/v1/student-classes/{id}/manual-sessions` (+ `/check`)
  - Underlying materialization remains `ClassSessionMaterializationService` (unchanged authority).
- Do **not** create a parallel calendar writer.
- Do **not** change total purchased entitlement semantics, billing, identity, permissions, historical attendance, or settlement semantics.
- Calendar client must **not** perform time/teacher edits (no `start_time`/`end_time` mutation path; no substitute/reschedule from this surface).

### 3. Phase 1b — AMEND / NOT AUTHORIZED YET

Future **CANCEL** from the new calendar surface is **not** authorized in this implementation unit.

Before cancel is authorized, a Plan amendment must explicitly specify:

1. count-mode cancel semantics (including any auto-appended tail via `appendTailAfterLeave`);
2. date / monthly-mode cancel semantics;
3. preview/result UX including any automatically appended tail occurrence;
4. locked / attended / settled refusal behavior;
5. payload invariant that calendar cancellation does **not** include `start_time` / `end_time`.

Do **not** bypass or modify existing cancellation semantics solely to simplify calendar UI.

### 4. Phase 2 and Phase 3 — NOT AUTHORIZED

- No time edit.
- No teacher edit.
- No “this and future”.
- No recurrence rewrite.

---

## Authorized implementation unit (summary)

**Phase 0 + Phase 1a only** — read model + future create via existing writers.  
Implementation remains **unstarted** until this decision artifact is committed. Production activation remains a separate GO.

---

## Explicitly excluded from this unit

- Phase 1b cancel; Phase 2; Phase 3
- Billing / Charge / Paid / Invoice mutation
- Migrations; identity/auth/permission changes
- Restate / Control Plane / Founder Console / second scheduler / new governance framework
- Unrelated issue cleanup
- Closing in-app `#290` without Closure Gate

---

## Next step

Independent workers may implement **only** against  
`docs/proposals/PRODUCT_LOOP_DOGFOOD_001_GOAL_CONTRACT_PHASE_0_1A.json`  
after this decision is GitHub-visible. Stop here for the decision session — **do not implement in the decision PR**.
