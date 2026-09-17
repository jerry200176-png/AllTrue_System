# PRODUCT_LOOP_DOGFOOD_001 — Proposal / PRD

**WorkItem:** `PRODUCT_LOOP_DOGFOOD_001`  
**SourceRef:** `alltrue:bug_report:290`  
**GitHub Issue:** [#2800](https://github.com/jerry200176-png/AllTrue_System/issues/2800)  
**In-app signal:** BugReport `#290` (campus `16`, `page_key=course-mgmt`, created `2026-09-13T13:16:30+08:00`)  
**Intake evidence:** GitHub Actions bug-detail dump run [`34739959709`](https://github.com/jerry200176-png/AllTrue_System/actions/runs/34739959709) (read-only, PII-redacted artifact)  
**Status:** Proposal only — **implementation not authorized**  
**Product Loop phase:** Discovery → Proposal → Independent CubeLV review → Founder Plan GO  
**Prior Founder product-direction record (issue #2800):** PRODUCT DIRECTION GO; `PLAN_REQUIRED`; no implementation PRs; no second calendar schema

---

## Problem

Directors editing an existing course cannot see or adjust “which days this contract should meet” in a calendar-shaped surface the way they can when **creating** a course. Today they must infer the session plan from course-management chips/lists while the calendar only supports **single-occurrence exception ops** (leave / reschedule / substitute / cancel). That split makes routine plan edits feel incomplete and forces context-switching, which the reporter describes as operationally complex.

User outcome desired (plain language): when editing a course’s scheduled meeting days, use a calendar-like editor so the whole contract’s planned dates are visible and editable without bouncing between disconnected tools.

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
4. **Founder direction:** Issue #2800 — calendar-oriented course session UX desirable; must reuse existing scheduling semantics; Plan required before impl.
5. **No delivered fix:** No CHANGELOG / merged PR claims delivery of in-app `#290` / `#2800`. Adjacent calendar PRs (capacity, delete guard, manual occurrence, SmartCalendar split) do not close this ask.
6. **Dedup (see below):** Distinct from capacity-display bugs, IA consolidation epics, and ambient/school/auth items.

### Deduplication

| Candidate | Relation | Decision |
|-----------|----------|----------|
| GH [#2800](https://github.com/jerry200176-png/AllTrue_System/issues/2800) | Same SourceRef mapping | **Canonical WorkItem surface** |
| In-app `#292` / GH `#2808` ambient volume | Unrelated UX | Rejected for this dogfood |
| In-app `#296` / GH `#2905` schools | Different problem; V1 already merged `#3015` | Out of scope |
| In-app `#297` / GH `#2906` grade promotion | Different; Phase-B authorized separately | Out of scope |
| In-app `#299` / GH `#2908` director+teacher account | Identity/auth — excluded by dogfood rules | Out of scope |
| In-app `#302`/`#303` billing | Billing mutation risk — excluded | Out of scope |
| In-app `#247` / GH `#2179` capacity “full” display | Cannot reproduce; blocked | Not a plan-edit ask |
| GH `#1922` course/contract IA consolidation | Related IA; calendar as **read-only lens** target conflicts with full plan editor unless phased carefully | Cite as constraint, not duplicate close |
| GH `#2002` unmaterialized reschedule invisible | Occurrence materialization gap | Adjacent tech debt, not this UX ask |
| Manual occurrence `#211` / PR `#1610` | Adds one-at-a-time booking, not full plan calendar edit | Precedent only |
| PR `#1927` (reverted calendar reassign-contract UI) | Warns against overloading calendar with contract-level writes | Precedent: keep writes on existing services |

**Selection rationale among qualifying open signals:** Among recent user-visible, non-auth/non-billing candidates still needing a durable Plan artifact, `#290` has the clearest user outcome. Blast radius is controlled by **phasing** (Phase 0–1 only in this proposal’s recommended GO). Smaller items (`#292`) already have Founder NO-GO/deferral; `#296` already shipped.

---

## Current behavior

1. **Course create:** Directors can plan sessions with scheduler UI; backend writes via `POST /api/v1/class-sessions/batch` → `EnrollmentService` (authoritative initial plan).
2. **Course edit (Course Management):** Session chips → `SessionEditModal` / `useSessionEditFlow` for status, reschedule, substitute, same-day time/note; `add-session` / `manual-sessions` for incremental adds — **not** a full-plan calendar editor.
3. **Smart Calendar:** Week/day occurrence view via `calendarOccurrenceMerge.js` (G-007). Click → `CalendarSessionEditModal`: leave / reschedule / substitute / cancel / restore only; course fields read-only.
4. **Sources of truth (must not fork):**
   - Contract / recurrence commitment: `StudentClass`
   - Materialized occurrence: `ClassSession` (`ClassSessionMaterializationService`)
   - Exception / move chain: `schedules` + `original_schedule_id` (TD-076 / `RFC_SCHEDULE_OCCURRENCE_IDENTITY`)
   - Atomic reschedule: `RescheduleSessionService` (ADR-004)
5. **Protections already present:** settlement lock / closed_reason block mutation; LR/attendance reverse paths on attended→cancel; hour-mode time edits can adjust `StudentClass.Charge` via `syncSessionChargeForTimeChange` (billing red line for calendar UX).
6. **Product red line (calendar module):** `MODULE_CALENDAR_SCHEDULE_UX.md` §4 — no Charge/Invoice/Payment changes from calendar UX work.

---

## Desired outcome

A director editing a course can **see the contract’s planned meeting dates on a calendar** and perform **bounded future-plan edits** (add / cancel / adjust future occurrences) with clear “this occurrence” semantics, without learning a second mental model of “what a session is,” and without the system silently rewriting attendance or billing history.

Success is measured by director comprehension and safe completion of plan edits — not by shipping a second scheduling engine.

---

## Proposed change

**Minimum sufficient solution (recommended GO slice = Phase 0–1 only):**

### Phase 0 — Calendar read model for one course (no new write API)

- Entry from Course Management “edit sessions” (and optionally deep-link from calendar course context).
- Calendar/list showing **that course’s** materialized + projected occurrences using existing GETs / read helpers (`classSessionsApi`, existing merge rules — do not bypass G-007 for week views that still use merge).
- Session detail drawer: read-only facts + deep links to existing single-session actions already available today.
- Feature flag off by default; rollback = hide entry point.

### Phase 1 — Future create + cancel via **existing** writers only

- **Create future session:** call existing `add-session` / `manual-sessions` / materialization-safe paths already used by Course Management — thin calendar client, **no parallel write API**.
- **Cancel future session:** existing `PATCH /class-sessions/{id}` cancel path with audit; refuse attended / settlement-locked / LR-locked slots with plain-language errors.
- Preview panel before mutate: date/time, entitlement impact if any, explicit “billing unchanged” / block if hour-mode charge sync would fire unless routed through existing billing-safe course-mgmt flows.
- Permissions: campus-scoped director mutate; teachers unchanged unless a later Plan says otherwise.

### Explicitly deferred (not in recommended first GO)

- Phase 2: future edit time/teacher with capacity checks (reuse substitute/reschedule services).
- Phase 3: “this and future” recurrence edits mapped to existing tools — only after Phase 1–2 are stable in production.
- Any new RRULE store, Google Calendar sync, or second occurrence identity model.

---

## Alternatives considered

| Alternative | Why not (for V1 dogfood slice) |
|-------------|--------------------------------|
| **A. Expand SmartCalendar into full course plan editor** | Collides with `#1922` IA (calendar as exception lens); higher accidental billing/attendance blast radius; prior revert `#1927`. |
| **B. Build new calendar schema / RRULE engine** | Violates Founder constraint; forks SoT from `StudentClass`/`ClassSession`/`schedules`. |
| **C. Docs/training only (“use create flow / chips”)** | Does not address the reported edit-path gap; leaves product loop without an implementable Plan. |
| **D. Jump to Phase 3 recurrence edits first** | Highest semantic risk; skips learnings from Phase 0–1. |

Material trade-off retained for Founder/CubeLV: **Course-mgmt-hosted calendar editor (recommended)** vs **SmartCalendar-hosted plan mode (Alternative A)** — product IA choice, not a schema choice.

---

## Scope

- Durable Proposal artifact for Product Loop dogfood (`PRODUCT_LOOP_DOGFOOD_001`).
- Plan review decision on Phase 0–1 as first implementation authorization unit.
- Reuse: ADR-004/005, RFC occurrence identity, MODULE calendar UX red lines, existing Course Management + session APIs.
- GitHub Issue `#2800` remains the collaboration/evidence surface; orchestration state stays provider-neutral (not GitHub-as-state-machine).

---

## Non-scope

- Implementation PRs, schema migrations, production schedule mutation.
- Second calendar / occurrence data model; Google Calendar sync.
- Auth / RFID / identity (`#293`, `#299`).
- Billing Charge/Paid/Invoice mutation; monthly settlement rewrite.
- Historical attendance repair; bulk rebuild; destructive data migration.
- Auto-rewrite of past sessions; hard-delete of attended history.
- Restate Gate-1, Founder Console, second scheduler, new governance frameworks (Founder freeze).
- Closing in-app `#290` before Closure Gate (deploy + runtime verify + public reply).

---

## Product risk

| Risk | Notes |
|------|--------|
| User | Mis-edit of future plan could cancel the wrong occurrence or confuse projected vs materialized chips. |
| Operational | Directors may assume calendar edit equals billing change; messaging must stay explicit. |
| Data | Low for Phase 0 (read-only). Phase 1 uses existing writers — residual double-book / entitlement errors already possible on those paths. |
| Security | No new privilege model in Phase 0–1; keep campus scoping. |
| Billing | Hour-mode time edits can adjust `Charge` today — **out of Phase 0–1 calendar path**; block or redirect. |

Overall: **R2-shaped product surface** if Phase 1 writes; Phase 0 alone is closer to **R1 display**. Full Phase 3 recurrence would trend **R3** without separate GO.

---

## Engineering impact

- **Architecture:** Thin UX over existing commands (ADR-005); `RescheduleSessionService` remains sole atomic reschedule boundary (ADR-004); no bypass of `calendarOccurrenceMerge.js` for week merge (G-007).
- **Tests (when impl authorized):** calendar Vitest + Course Management session flows; characterization for projected vs materialized; cancel/create refusal cases; assert no Charge/Paid change in Phase 0–1 paths.
- **Deployment:** Feature flag; exact-SHA deploy only after Plan GO + impl PR CI; production verification against one campus pilot before broad enablement.
- **TD-076:** Do not depend on occurrence-v2 cutover; consume current merge semantics.

---

## Success criteria

After a future authorized release of Phase 0–1:

1. From course edit, director can open a calendar view of **that course’s** planned dates without using a separate mental model of “calendar exceptions only.”
2. Future cancel + create succeed only through existing APIs; attended/locked sessions refuse with clear reasons.
3. No `StudentClass.Charge` / Paid / Invoice change attributable to the new calendar path in Phase 0–1.
4. No new tables/RRULE store shipped.
5. In-app `#290` may move toward Closure Gate only after deploy + runtime verify + Phase-C public reply (GUIDE_BUG_CLOSURE_GATE) — **not** merely because this proposal merges.

---

## Rollback / reversibility

- Phase 0–1: feature flag / remove entry point; no schema to roll back.
- If a bad write ships later: revert PR; compensating cancel preferred over silent rewrite; existing audit/ScheduleAudit paths.

---

## Unknowns

1. Exact reporter screenshots show preferred IA (course-mgmt host vs calendar host) — attachments not reproduced here; CubeLV should review dump artifact under access controls.
2. Whether directors primarily need **count-mode** plan edits, **date-mode**, or **manual_occurrence** first — production mix at campus 16 not quantified in this proposal.
3. How often projected-only (not yet materialized) chips must be editable from the new surface without `ensure-projected` side effects.
4. Interaction with unpaid / entitlement caps when adding sessions (existing error codes exist; UX copy not finalized).
5. `#1922` IA end-state vs this Plan — long-term “calendar read-only lens” may require the editor to remain **course-mgmt-hosted** permanently.

---

## Founder decision

**Decision required (Plan GO / NO-GO / AMEND):**

1. Approve **Phase 0–1** (course-mgmt-hosted calendar read model + future create/cancel via existing writers only) as the first implementation authorization unit for `alltrue:bug_report:290` / `#2800`, **or** amend host surface (Alternative A), **or** NO-GO / defer.
2. Confirm **billing remains non-mutating** from this calendar path in Phase 0–1 (redirect hour-mode charge-affecting edits to existing course-mgmt flows).
3. Confirm **Phase 2–3 remain unauthorized** until separate Plan review after Phase 0–1 evidence.

**Not requested now:** implementation start, production activation, schema changes, Restate/CP expansion.

**Next step after this PR is GitHub-visible:** Independent **CubeLV** review of this proposal. Stop. Do not implement until Founder Plan GO.
