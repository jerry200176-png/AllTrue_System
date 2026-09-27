# TF-S6-01 Plan — Workspace session progress strip

**Status:** PLAN ONLY — awaiting Plan Review. **Do not implement** until review GO.  
**Depends on:** TF-S6-00 ACCEPTED (Supervisor) · main contains #3010 + #3012.  
**Out of scope for this Plan:** TF-S6-02 next-lesson carry-forward; flag/DNS activation; scheduling authority; auth redesign.

---

## Goal

On the TrueFit today-sessions workspace, show **which stages of the current session already have saved artifacts**, using **existing read APIs / client state** where possible — so a teacher can see loop progress without opening every stage.

Target user outcome:

```text
Today session card → visible stage progress
  Prep / Observe / Diagnose / Remediate / Mastery
  (saved vs empty)
```

---

## Verified current behavior (post S6-00)

- Workspace lists sessions and offers five parallel entry CTAs.
- Stage pages can load/save via existing GETs/POSTs.
- Same-session continuum CTAs exist on stage pages (#3012).
- **Gap:** workspace cards do not show which stages already have rows.
- Flags: `TRUEFIT_V1` / `VITE_TRUEFIT_V1` remain OFF.

---

## Recommended approach (smallest coherent slice)

### Option A — Client fan-out of existing GETs (recommended)

For each visible today-session (or for the expanded/primary card only if N is large):

1. Call existing endpoints already used by stage pages:
   - `GET lesson-preps`
   - `GET observations`
   - `GET diagnoses`
   - `GET remediations`
   - `GET mastery-evidence`
2. Derive a compact progress model: `{ prep, observe, diagnose, remediate, mastery }` → `empty | saved`.
3. Render a non-card progress strip / text row on the session list item (one job: show progress).

**Upside:** no new backend contract; stays inside TrueFit; reversible UI.  
**Cost:** up to 5 reads × session count — mitigate with:

- progress only for sessions in viewport / first K sessions, or
- lazy load when card expands / on “顯示進度”, or
- `Promise.all` per session with concurrency cap.

### Option B — New aggregate read endpoint

`GET /api/v1/truefit/session-progress?…` returning stage presence for one or many sessions.

**Upside:** one round-trip.  
**Cost:** new API surface + tests; larger review; still flag-gated.  
**Recommend:** only if Option A proves too chatty in flag-on pilot.

---

## Decision for Founder / Plan Review

| Field | Content |
|-------|---------|
| Decision required | Option A (client fan-out) vs Option B (aggregate API) for v1 progress strip |
| User outcome | Teacher sees which loop stages are already saved on today’s list |
| Verified current behavior | Parallel CTAs; no progress indicators |
| Recommended choice | **Option A** |
| Meaningful alternative | Option B if fan-out latency fails acceptance |
| Expected upside | Completes “where am I in the loop?” without leaving workspace |
| UX/data implications | Read-only; no new tables; still teacher-scoped; no PII→LLM |
| Reversibility | High (UI + optional cache); Option B needs API deprecate path |

Engineering (not Founder): concurrency/lazy details, exact badge copy, empty-state wording.

---

## Explicit non-goals

- Production flag enablement  
- DNS / subdomain activation  
- Next-lesson prep seeding (S6-02)  
- Billing / RFID / auth / scheduling changes  
- Overwriting stage content from progress reads  

---

## Acceptance sketch (after implementation GO)

1. With `TRUEFIT_V1` on in **non-prod** only: session with saved observation shows Observe=saved without opening the page.
2. Empty stages remain empty; saved stages do not flip incorrectly after refresh.
3. No writes from progress load.
4. Existing continuum + source_* suites stay green.
5. Flags default OFF in committed config.

---

## Sequencing

1. Supervisor marks S6-00 ACCEPTED (or returns gaps).  
2. Plan Review chooses A or B.  
3. Only then open TF-S6-01 implementation task.
