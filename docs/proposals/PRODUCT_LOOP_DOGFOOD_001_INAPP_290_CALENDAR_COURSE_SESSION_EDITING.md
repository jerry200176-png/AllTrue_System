# PRODUCT_LOOP_DOGFOOD_001 — Proposal / PRD

**WorkItem:** `PRODUCT_LOOP_DOGFOOD_001`  
**SourceRef:** `alltrue:bug_report:290`  
**GitHub Issue:** [#2800](https://github.com/jerry200176-png/AllTrue_System/issues/2800)  
**In-app signal:** BugReport `#290` (campus `16`, `page_key=course-mgmt`, created `2026-09-13T13:16:30+08:00`)  
**Intake evidence:** GitHub Actions bug-detail dump run [`34739959709`](https://github.com/jerry200176-png/AllTrue_System/actions/runs/34739959709) (read-only, PII-redacted artifact)  
**Status:** Plan reconciled — **Phase 0 + Phase 1a GO**; Phase 1b / 2 / 3 **not authorized**; implementation **not started** in the decision PR  
**Product Loop phase:** Discovery → Proposal → CubeLV challenge review → **Founder Plan Decision recorded** → GoalContract ready → (next) implementation  
**Proposal SHA:** `6b70ca51f2110f3a7020bbf852e742727d8f50cf`  
**CubeLV review SHA:** `5d7d05c05114950a0eebe85e3c0098a33be12274`  
**Founder decision:** `docs/proposals/PRODUCT_LOOP_DOGFOOD_001_FOUNDER_PLAN_DECISION.md`  
**GoalContract:** `docs/proposals/PRODUCT_LOOP_DOGFOOD_001_GOAL_CONTRACT_PHASE_0_1A.json`  
**Prior Founder product-direction record (issue #2800):** PRODUCT DIRECTION GO; no second calendar schema

---

## Problem

Directors editing an existing course cannot see or adjust “which days this contract should meet” in a calendar-shaped surface the way they can when **creating** a course. Today they must infer the session plan from course-management chips/lists while the calendar only supports **single-occurrence exception ops** (leave / reschedule / substitute / cancel). That split makes routine plan edits feel incomplete and forces context-switching, which the reporter describes as operationally complex.

User outcome desired (plain language): when editing a course’s scheduled meeting dates, use a calendar-like editor so the whole contract’s planned dates are visible and editable without bouncing between disconnected tools.

---

## Source

| Field | Value |
|-------|--------|
| Stable SourceRef | `alltrue:bug_report:290` |
| In-app BugReport id | `290` |
| Reporter context | Staff on `course-mgmt` (campus 16); severity `medium`; status at dump time `new` |
| Verbatim problem (zh, from dump) | 「我編輯課程的上課日期為什麼不要像建立課程一樣有一個行事曆可編輯…如果整合變像 google 日曆…也不會讓主任覺得操作複雜」 |
| GitHub collaboration surface | Issue [#2800](https://github.com/jerry200176-png/AllTrue_System/issues/2800) |
| Attachments | Present on original report; **do not copy screenshots or personal data** into this proposal (CHAT_BUG_SYSTEM §3.6) |

---

## Evidence

1. **Original signal:** BugReport `#290` description + `page_key=course-mgmt` (dump `34739959709`).
2. **Product IA gap:** Course create already exposes calendar/session-plan UX (`UniversalClassScheduler` / batch create); course **edit** does not reuse that shape for the full plan.
3. **Current calendar scope:** `CalendarSessionEditModal` is explicitly single-session; copy directs whole-course settings to Course Management (`docs/GUIDE_SMARTCALENDAR_REFACTOR.md`, `docs/MODULE_CALENDAR_SCHEDULE_UX.md`).
4. **Founder direction:** Issue #2800 — calendar-oriented course session UX desirable; must reuse existing scheduling semantics.
5. **No delivered fix (F2):** At proposal anchor `6b70ca51`, `docs/CHANGELOG.md` has **no** delivery claim for in-app `#290` / GH `#2800`. Adjacent calendar PRs do not close this ask. **Do not infer delivery from issue open/closed state.**
6. **Dedup (F1, independently verified 2026-09-17):** GH `#2808`, `#2905`, `#2906`, `#2908`, `#2179` are **OPEN** and are **not** duplicates of `#290`.
7. **CubeLV challenge review:** `5d7d05c0` — CONDITIONAL_AGREE; F1–F4 addressed by Founder Plan Decision.
8. **Cancel semantic (new finding):** `PATCH` ClassSession cancel may call `tryExtendOnLeave()` → `CourseLeaveCascadeService::appendTailAfterLeave()` (count-mode tail append). Treat as product semantic requiring Phase 1b Plan amendment — **not** a bug to “fix” for calendar UX.

### Deduplication

| Candidate | Relation | Decision |
|-----------|----------|----------|
| GH [#2800](https://github.com/jerry200176-png/AllTrue_System/issues/2800) | Same SourceRef mapping | **Canonical WorkItem surface** |
| In-app `#292` / GH `#2808` ambient volume | Unrelated UX; issue OPEN | Not a duplicate |
| In-app `#296` / GH `#2905` schools | Different problem; issue OPEN (delivery ≠ issue state) | Not a duplicate |
| In-app `#297` / GH `#2906` grade promotion | Different; issue OPEN | Not a duplicate |
| In-app `#299` / GH `#2908` director+teacher account | Identity/auth; issue OPEN | Not a duplicate |
| In-app `#247` / GH `#2179` capacity “full” display | Different; issue OPEN | Not a duplicate |
| GH `#1922` course/contract IA consolidation | Calendar as read-only lens → host stays Course Management | Constraint |
| Manual occurrence `#211` / PR `#1610` | One-at-a-time booking precedent | Writer reuse |
| PR `#1927` (reverted calendar reassign-contract UI) | Warns against calendar contract writes | Precedent |

---

## Current behavior

1. **Course create:** Directors can plan sessions with scheduler UI; backend writes via `POST /api/v1/class-sessions/batch` → `EnrollmentService` (authoritative initial plan).
2. **Course edit (Course Management):** Session chips → `SessionEditModal` / `useSessionEditFlow`; `add-session` / `manual-sessions` for incremental adds — **not** a full-plan calendar editor.
3. **Smart Calendar:** Single-occurrence exception ops only (`CalendarSessionEditModal`).
4. **Sources of truth (must not fork):** `StudentClass` / `ClassSession` / `schedules` + ADR-004 `RescheduleSessionService`.
5. **Billing (F3 correction):** `syncSessionChargeForTimeChange` runs only when PATCH includes actual `start_time`/`end_time` changes. Create-only clients that never send time edits do not hit this path; still require **no Charge/Paid/Invoice mutation** regression tests.
6. **Cancel cascade:** Cancel/leave paths may auto-append a count-mode tail via `appendTailAfterLeave` — out of scope for Phase 1a.

---

## Desired outcome

A director editing a course can **see the contract’s planned meeting dates on a calendar** (Phase 0) and **add future sessions** through the same authoritative create paths already used in Course Management (Phase 1a), without a second scheduling engine and without silent billing or history rewrite.

Cancel-from-calendar and recurrence edits remain separate Plan units.

---

## Proposed change (reconciled with Founder Plan Decision)

### Phase 0 — GO — Calendar read model (Course Management host)

- Permanent host for this slice: **Course Management** (unless future Founder decision changes `#1922` / SmartCalendar authority).
- Calendar/list of **that course’s** materialized + projected occurrences via existing GETs / read helpers.
- No new write API; no schema/RRULE; feature flag; rollback = hide entry point.

### Phase 1a — GO — Future CREATE only

- Thin calendar client calling **only**:
  - `POST /api/v1/student-classes/{id}/add-session` (+ `/check`)
  - `POST /api/v1/student-classes/{id}/manual-sessions` (+ `/check`)
- No parallel calendar writer.
- No time/teacher edits from this client.
- No entitlement/billing/identity/permission/attendance/settlement semantic changes.

### Phase 1b — AMEND / NOT AUTHORIZED

- Future CANCEL from this surface **deferred**.
- Requires explicit Plan covering count-mode vs date/monthly cancel, tail-append preview UX, locked/attended/settled refusals, and no `start_time`/`end_time` on cancel payloads.
- Do not bypass or modify existing cancel / `appendTailAfterLeave` semantics to simplify UI.

### Phase 2 / Phase 3 — NOT AUTHORIZED

- No time edit; no teacher edit; no “this and future”; no recurrence rewrite.

---

## Alternatives considered

| Alternative | Disposition |
|-------------|-------------|
| **A. SmartCalendar-hosted plan editor** | **Rejected for this slice.** Course Management is permanent host unless `#1922` is re-decided. |
| **B. New RRULE / calendar schema** | Rejected — SoT fork. |
| **C. Docs/training only** | Rejected — does not close the edit-path gap. |
| **D. Phase 3 first** | Rejected — semantic risk. |
| **E. Phase 1 with cancel in first unit** | **Amended out** — cancel cascade / tail-append requires Phase 1b Plan. |

---

## Scope (authorized after decision commit)

- Phase 0 read model + Phase 1a create-only client under Course Management.
- Feature flag + tests (projection, create refusal/conflict, no billing mutation).
- GoalContract-bound implementation handoff (separate PR from this decision).

## Non-scope

- Phase 1b cancel; Phase 2; Phase 3
- Parallel write API; schema/RRULE; SmartCalendar as plan-editor host
- Billing Charge/Paid/Invoice mutation; migrations; identity/auth/permission changes
- Production activation without separate GO
- Restate Gate-1 / Founder Console / second scheduler / new governance frameworks
- Closing in-app `#290` before Closure Gate
- Unrelated issue cleanup

---

## Product risk

| Risk | Notes |
|------|--------|
| User | Projected vs materialized confusion on read model; create conflicts need plain-language errors. |
| Operational | Directors may expect cancel-from-calendar; Phase 1a must not imply cancel is available. |
| Data | Phase 0 read-only. Phase 1a uses existing create writers (existing conflict/entitlement risks). |
| Cancel / count-mode | Deferred — tail append is intentional product behavior needing Plan UX. |
| Billing | Create-only avoids `syncSessionChargeForTimeChange`; keep regression asserts. |

Risk shape: Phase 0 ≈ R1 display; Phase 1a create ≈ R2 reversible scheduling client; Phase 1b/3 would need separate grading.

---

## Engineering impact

- Thin UX over existing named commands (ADR-005); no SoT fork; G-007 merge unchanged for week views that still merge.
- **Tests (impl):** read projection; create refusal/conflict; **no Charge/Paid/Invoice mutation** on Phase 0/1a paths; flag-off rollback.
- **Deploy:** feature flag default off; exact-SHA; production enablement separate GO.
- **Rollback:** feature flag / entry-point removal.

---

## Success criteria (Phase 0 + 1a release)

1. From course edit, director sees that course’s planned dates in a calendar-shaped surface hosted in Course Management.
2. Future create succeeds only via existing add-session / manual-sessions APIs.
3. No cancel control in this unit.
4. No `Charge` / `Paid` / Invoice mutation attributable to Phase 0/1a paths.
5. No new tables/RRULE/write API.
6. In-app `#290` Closure Gate only after deploy + runtime verify + Phase-C public reply — not because decision or code merely merged.

---

## Rollback / reversibility

Feature flag off / remove Course Management entry point; no schema in this unit.

---

## Unknowns (preserved)

1. Campus 16 mix of count / date / manual_occurrence demand for create UX defaults.
2. How often projected-only chips need create-adjacent actions without unwanted `ensure-projected` side effects.
3. Entitlement-cap UX copy on add-session failures (codes exist; copy TBD at impl).
4. Phase 1b cancel Plan details (count vs date/monthly, tail preview) — **explicitly unresolved until Phase 1b amendment**.
5. Reporter screenshot IA preference — superseded for host choice by Founder decision (Course Management permanent for this slice).

---

## Founder decision (recorded)

See `docs/proposals/PRODUCT_LOOP_DOGFOOD_001_FOUNDER_PLAN_DECISION.md`.

| Unit | Verdict |
|------|---------|
| Phase 0 | **GO** |
| Phase 1a create-only | **GO** |
| Phase 1b cancel | **AMEND / NOT AUTHORIZED** |
| Phase 2 / 3 | **NOT AUTHORIZED** |

**Implementation must not start in the decision docs PR.** Next: implement only against the Phase 0+1a GoalContract after decision is GitHub-visible.
