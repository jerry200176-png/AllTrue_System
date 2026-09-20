# APP_SHELL_DECOMPOSITION_001 — Bounded App.vue extraction plan

**Status:** `PLAN_READY / IMPLEMENTATION_DEFERRED`  
**Date:** 2026-09-18  
**Authority:** Product signals drive priority; this plan is pulled only when shell coupling materially slows or endangers delivery.  
**Measured shell (approx., at plan time):** `frontend/src/App.vue` ~4,686 lines (~792 template / ~2,258 script / ~1,635 style); 100+ named functions; 60+ refs; 40+ computed; 15+ watchers; ~34 lazy-loaded page surfaces.

---

## Problem

`App.vue` has accumulated independent shell responsibilities in one file. Size alone is not the failure mode — **coupling** is:

| Responsibility cluster | Examples today |
|------------------------|----------------|
| Mount / routing shell | page mounting, deep links |
| Auth / session | profile lifecycle, PIN lock |
| Campus context | branch selection / authority surface |
| Navigation | desktop nav, mobile nav, More menus |
| Discovery | global search |
| Attention | badge / unread aggregation + polling |
| Guidance | onboarding, help FAB, feature map, guides |
| Chrome | update banner, release-note nudge, theme, brand overlays |

This is a **shell God Component**: architectural debt, **not** the current highest-priority product task.

**Do not** start a broad App shell refactor merely because this debt exists.

---

## Approved future extractions (only three)

Implementation of these slices is **deferred** until a trigger below fires. Each slice must preserve existing APIs, navigation contracts, and product semantics.

### Slice A — Badge / unread orchestration

**Candidate boundary:** `useAppBadges()` (composable)

Move orchestration out of `App.vue` while preserving badge contracts:

- polling lifecycle + visibility pause/resume
- notification unread
- action inbox counts
- bugs / chat / parent feedback
- teacher learning pending / teacher attendance
- schedule discrepancy / director pending

**Constraints:** no backend change; no notification semantic redesign; no new badge authority.

### Slice B — Desktop / Mobile More + Global Search

Desktop More and Mobile More are the **same product capability** with different presentation.

**Share (logic):** query state, search execution, result groups, selection behavior, feature registry, badge contract.

**Do not** force identical markup.

**Candidate boundary:** `AppMorePanel` with desktop/mobile presentation modes if useful.

**Constraints:** do not invent a generic command framework; do not rewrite desktop/mobile navigation architecture.

### Slice C — Onboarding / Help experience

**Candidate boundary:** `AppHelpExperience` (or a thin component + existing composables)

Extract tightly related:

- onboarding launch / completion
- guided tour
- help FAB / feature map
- related overlay state

**Constraints:** do not redesign onboarding semantics in the extraction.

---

## Explicit non-goals

Do **not**:

- rewrite router / navigation architecture
- introduce Pinia/Vuex merely for cleanup
- build a generic app-shell framework
- migrate all `App.vue` state into composables
- redesign auth, branch authority, badge backend APIs, notification semantics, or onboarding
- rewrite desktop/mobile navigation
- chase a tiny `App.vue` vanity target

**Stop metric:** reduced coupling after the three slices — **not** “under 500 lines.”  
A reasonable stop point is ~**2,500–3,000 lines** *if* Slices A–C are cleanly extracted.

---

## Trigger to implement (pull, do not push)

Do **not** auto-start this plan after the docs PR merges.

Pull implementation when **one or more** are true:

1. Repeated product changes keep touching the same `App.vue` responsibility.
2. Shell changes repeatedly cause regressions.
3. New in-app work is materially slowed by `App.vue` coupling.
4. Badge / navigation / onboarding ownership becomes ambiguous again.
5. Founder explicitly prioritizes a named slice (A, B, and/or C).

Until then: **`PLAN_READY / IMPLEMENTATION_DEFERRED`**.

---

## Director Dashboard guardrail (document only)

Current IA direction — **`今天` + `完整營運`** — is sound.

**Do not** add dashboard cards merely because a feature exists.

A signal belongs on the Dashboard only if:

1. the director must act on it **today**, or  
2. it materially changes **today’s** operational judgment.

Otherwise route to the domain page.

Mental model:

| Surface | Job |
|---------|-----|
| Dashboard / Today | what requires action **now** |
| Summary | is today healthy? |
| Domain page | inspect / manage an entity or workflow |
| Search | find something when location is unknown |
| Inbox | system tells staff something needs attention |

Avoid inventing a fifth competing discovery mechanism.

---

## Implementation notes (when pulled)

- Prefer smallest extraction that removes a responsibility cluster from `App.vue`.
- Preserve behavior with Vitest (and existing smoke where applicable).
- One slice per PR when practical; no “shell rewrite” mega-PR.
- Coordinate with `docs/plans/INAPP_PRODUCT_LOOP_EXECUTION_POLICY_V1.md` — architecture replacement is **PLAN_REQUIRED**, not auto-fix.
