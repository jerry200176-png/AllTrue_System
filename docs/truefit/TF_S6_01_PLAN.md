# TF-S6-01 Plan — Continuum hardening (workspace progress + CTA edge cases)

**Status:** PLAN ONLY — awaiting Supervisor Plan Review. **Do not implement** until review GO.  
**Task:** TF-S6-01  
**Date:** 2026-09-17T00:15:00Z  
**Author lane:** Worker C (`DISPATCH_C_TF_S6_01_PLAN`)  
**Branch (docs draft):** `chore/task-truefit-s6-01-plan`  
**Base:** `f69b14ea98c9800985732787a927a5058578dbbe` (main after #3012)  
**Depends on:** TF-S6-00 **ACCEPTED** (Supervisor) · #3010 + #3012 on main  
**Line gate (impl later):** ≤700 additions — do not weaken without Founder  

**Prior draft absorbed:** `TF_S6_01_WORKSPACE_PROGRESS_PLAN.md` (workspace progress strip) + dispatch themes (continuum UX / CTA edges on `truefitLoop.js`).

---

## 1. Objectives

After S6-00, the same-session loop **exists** (Prep→Observation→Diagnosis→Remediation→Mastery→Workspace) with `source_*` auto-link and continuum CTAs. S6-01 hardens **discoverability and edge correctness** without opening flags or starting S6-02.

| Outcome | Non-outcome |
|---------|-------------|
| Teacher sees which stages of **today’s session** already have saved artifacts on the workspace | No next-lesson prep carry-forward (S6-02) |
| Continuum CTA / seed helpers fail closed on empty, partial, and already-saved edges | No production / staging flag ON |
| Contracts stay in `truefitLoop.js` + existing stage pages | No RFID / billing / auth / scheduling |
| Silent-ship docs / changelogDraft only as integrity requires | No aggregate API unless Plan Review chooses Option B |
| Impl PR (later) ≤700 frontend-focused | No weakening of #3006/#3012 line gate |

**Success criterion (post-impl, for later acceptance):** With flags ON in **non-prod only**, a teacher can answer “where am I in the loop?” from the workspace without opening every stage, and continuum CTAs never seed-overwrite or route incorrectly on edge states.

---

## 2. Product thesis for this slice

```text
Workspace (today session)
  → progress strip: Prep / Observe / Diagnose / Remediate / Mastery  (empty | saved)
  → open a stage via existing CTA
  → stage page continuum CTA (S6-00) with hardened empty/partial/saved edges
  → return to workspace (mastery CTA) with progress reflecting saves
```

S6-00 delivered **navigation + seeding**. S6-01 delivers **progress visibility + edge polish**.

---

## 3. Module boundaries

| Module | Owns (S6-01) | Must not own |
|--------|--------------|--------------|
| `frontend/src/lib/truefitLoop.js` | Progress model helpers; CTA enablement predicates; edge-safe exports | Backend contracts; flag flips |
| `frontend/src/pages/TrueFitWorkspacePage.vue` (and list row child if any) | Progress strip / text row UI; read-only fan-out orchestration | Stage form editors; writes |
| Stage pages (`TrueFit*Page.vue`) | Minimal CTA visibility / disabled-state wiring if gaps found | New stage semantics; S6-02 carry-forward |
| `frontend/src/TrueFitApp.vue` | Only if routing/props needed for progress refresh | New routes outside TrueFit shell |
| Tests under `frontend/src/components/__tests__/` | Progress model + CTA edge cases + shell regression | Flag-on E2E staging ownership |
| `docs/truefit/**` + silent-ship changelogDraft | Plan + PROGRAM_STATUS next-pointer | Claiming ops acceptance |

**Reuse:** existing GETs already used by stage pages (`lesson-preps`, `observations`, `diagnoses`, `remediations`, `mastery-evidence`). Prefer **no new backend** in v1.

---

## 4. Recommended approach

### 4a. Workspace progress strip — Option A (recommended)

Client fan-out of existing GETs per visible today-session (or first K / expanded card):

1. Derive `{ prep, observe, diagnose, remediate, mastery }` → `empty | saved`.
2. Render a **non-card** progress strip / text row on the session list item (one job: show progress).
3. Mitigate chatty reads: viewport / first-K / lazy-on-expand / concurrency cap + `Promise.all`.

**Upside:** no new API; reversible; stays TrueFit-scoped.  
**Cost:** N×5 reads — acceptable for flag-off pilot scale if capped.

### 4b. Aggregate API — Option B (fallback)

`GET /api/v1/truefit/session-progress?…` — only if Plan Review rejects A for latency, or A fails non-prod acceptance later.

### 4c. Continuum CTA / seed edge cases (in-scope polish)

Documented gaps to close in the **same** ≤700 impl (or explicitly defer with Supervisor note):

| Edge | Expected behavior |
|------|-------------------|
| Stage unsaved / no prior artifact | CTA disabled or hidden; no seed invent |
| Prior saved, current empty | Seed once; CTA enabled after save rules match S6-00 |
| Current already saved (`savedRecordId`) | No seed overwrite; CTA to next still works |
| Partial prior payload (missing label / empty arrays) | Seed helpers return null; UI does not crash |
| Mastery → Workspace | Progress strip refreshes on return |
| Prep without brief | Keep S6-00 gate (CTA only when brief present) |

Prefer pure helpers in `truefitLoop.js` (e.g. `stagePresenceFromList`, `canContinueFromStage`) tested in Vitest without mounting full pages where possible.

---

## 5. Non-goals

- TF-S6-01 **implementation** in this Plan dispatch  
- TF-S6-02 next-lesson prep carry-forward  
- `TRUEFIT_V1` / `VITE_TRUEFIT_V1` ON (any env via this task)  
- Prod approve `35111700889`  
- RFID / billing / auth / scheduling  
- DNS / subdomain activation  
- Overwriting stage content from progress reads  
- Assessment vendor adapter  
- Weakening ≤700 line gate  

---

## 6. Founder / Supervisor boundaries

| Role | Decides | Does not need to decide |
|------|---------|-------------------------|
| **Supervisor** | Plan Review GO / NO-GO; Option A vs B; whether CTA edges share the impl PR or split | Exact badge copy / concurrency constants |
| **Founder** | Any flag ON; DNS; real-student PII→LLM; weaken ≤700 gate; prod deploy approve | Client fan-out vs aggregate (unless B expands risk) |
| **Worker C (later impl)** | File-level wiring, tests, silent-ship docs | Self-declare operational acceptance |

**Privacy:** still fixture / teacher-entered only until Founder GO. Progress reads are teacher-scoped existing APIs — no new PII egress.

---

## 7. Decision required at Plan Review

| Field | Content |
|-------|---------|
| Decision | **Option A** (client fan-out) vs **Option B** (aggregate API) |
| Include CTA edge polish in same impl PR? | **Recommend yes** if still ≤700; else Supervisor splits 01a/01b |
| User outcome | Workspace shows saved vs empty stages; continuum edges fail closed |
| Recommended | **Option A + CTA edge helpers** in one ≤700 frontend PR |
| Meaningful alternative | Option B if fan-out fails acceptance; or defer CTA polish to tiny follow-up |
| Reversibility | High for A; B needs API deprecate path |
| Flags | Remain OFF in committed defaults |

---

## 8. Proposed file list + size estimate (impl — after GO)

| Path | Change type | Est. lines |
|------|-------------|------------|
| `frontend/src/lib/truefitLoop.js` | progress + CTA predicates | ~80–120 |
| `frontend/src/pages/TrueFitWorkspacePage.vue` | strip + fan-out | ~120–200 |
| Stage pages (only if CTA wiring gaps) | small guards | ~40–80 total |
| `frontend/src/components/__tests__/TrueFitLoopContinuum.test.js` | edges | ~60–100 |
| New or extended workspace/progress test | presence model | ~80–120 |
| `docs/truefit/PROGRAM_STATUS.md` | next pointer | ~20 |
| silent-ship changelogDraft (if required) | companion | ~20–40 |
| **Total estimate** | | **~420–680** (fit ≤700) |

Out of estimate unless Option B chosen: backend controller/route/feature test (~150–250 extra → likely forces split or Founder gate discussion).

---

## 9. Test plan (impl — after GO)

### Unit / contract (required)

1. `truefitLoop` progress derivation: empty lists → all `empty`; one observation row → observe `saved`.  
2. Seed helpers: null/partial prior → `null`; full prior → seed; never called when saved id present (page/contract test).  
3. CTA predicates: unsaved current → cannot continue; saved → next stage / workspace.  
4. Existing suites stay green:  
   - `TrueFitLoopContinuum.test.js`  
   - `TrueFitShellContract.test.js`  
   - Backend TrueFit feature filters **unchanged** unless Option B.

### Manual / non-prod (Supervisor ops later — not this Plan)

- Flag-on local or staging pilot: save observation → workspace shows Observe=saved after refresh/return.  
- No writes in network tab from progress load.  
- Flags default OFF on main.

### Explicitly out of test scope here

- Production runtime verification  
- RFID / billing / auth  

---

## 10. Acceptance sketch (post-impl; Supervisor)

1. Progress strip reflects saved vs empty for today session(s) under test.  
2. No writes from progress load.  
3. Continuum edges: no seed overwrite; null-safe seeds; mastery returns to workspace.  
4. Flags remain OFF in committed config.  
5. Diff ≤700 (or Founder-approved exception).  
6. Scope audit: TrueFit frontend (+ docs/silent-ship only); no RFID/billing/auth/scheduling.  

---

## 11. Sequencing

```text
[DONE] S6-00 ACCEPTED
   ↓
[NOW]  TF-S6-01 PLAN ONLY → Supervisor Plan Review
   ↓
[GATE] Plan Review GO (Option A/B + CTA bundling)
   ↓
[LATER] TF-S6-01 implementation PR (separate dispatch)
   ↓
[FORBIDDEN NOW] TF-S6-02 · flag ON · prod approve
```

---

## 12. Stop condition

This document + `ASSIGNMENT.md` are the deliverables. **STOP for Plan Review.**  
Do not open an implementation PR from this dispatch.
