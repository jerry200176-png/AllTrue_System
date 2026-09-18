# INAPP_PRODUCT_LOOP_EXECUTION_POLICY_V1

**Status:** Active policy (docs)  
**Date:** 2026-09-18  
**Objective:** Use real in-app user feedback as the primary product-learning stream.  
**Not:** a FIFO engineering queue, a second scheduler, or a new approval framework.

Related: [`docs/CHAT_BUG_SYSTEM.md`](../CHAT_BUG_SYSTEM.md) §3.6–§3.7 · [`docs/plans/APP_SHELL_DECOMPOSITION_001.md`](APP_SHELL_DECOMPOSITION_001.md)

---

## Operating principle

> Product signals drive engineering priority.  
> Architecture work is pulled only when repeated product delivery is materially slowed or made unsafe by that architecture.

Cursor (or any implementing agent) must:

1. understand → reproduce → inspect current state → **classify**  
2. then either **auto-execute** (small envelope) or return a **Decision Packet** (`PLAN_REQUIRED`)

Do **not** invent the missing Founder decision. Do **not** code merely because the reporter proposed a solution — prefer the smallest existing-authority-compatible fix for the user problem.

---

## Signal classification

Assign **exactly one** primary class:

| Class | Meaning |
|-------|---------|
| `BUG_CLEAR` | Defect vs known expected behavior |
| `UX_FRICTION_CLEAR` | Usability friction; expected outcome already known |
| `SMALL_PRODUCT_IMPROVEMENT` | Tiny improvement; no new policy |
| `NEEDS_EVIDENCE` | Cannot classify safely without more read-only evidence |
| `DUPLICATE` | Same underlying signal as another SourceRef |
| `ALREADY_FIXED` | Behavior already matches desired outcome in current main/runtime |
| `PARTIALLY_FIXED` | Some of the user problem remains |
| `PLAN_REQUIRED` | Decision boundary; Decision Packet only — no implementation |
| `DEFER` | Valid but not worth acting now (low harm / low frequency) |

---

## Auto-execution envelope

Cursor may proceed **end-to-end** (reproduce → fix → tests → review → CI → merge → deploy → verify → reconcile evidence) when **ALL** are true:

1. Expected behavior is already clear from existing product semantics.  
2. Change is reversible.  
3. No new domain policy.  
4. No schema migration.  
5. No identity / auth / permission change.  
6. No billing / accounting semantics change.  
7. No production data repair.  
8. No destructive bulk mutation.  
9. No new source of truth.  
10. No architecture replacement.  
11. No product scope expansion.  
12. Existing API / service authority can be reused.  
13. A regression test can clearly prove expected behavior.

**Examples:** obvious rendering bug; duplicated button; misleading label where behavior is known; layout / overflow / a11y defect; stale UI after an already-defined state change; clear API/UI mismatch; duplicated entry point; low-risk loading/empty/error-state improvement; small performance bug with unchanged semantics.

Do **not** ask Founder merely because code changed.

### Production discipline (autonomous small fixes)

Distinguish: code written → tests passed → reviewed → merged → **deployed** → runtime verified → user path verified → issue / in-app evidence reconciled.

Never report **done** at PR merge alone.  
Do **not** auto-activate a materially new product capability without Founder approval.  
Normal deployment of an already-active, behavior-preserving bugfix is allowed when existing deploy policy permits.

---

## Plan-required envelope

**STOP before implementation** when **ANY** apply:

### Product semantics

unclear expected behavior · competing valid UX/product outcomes · new workflow · new lifecycle state · new automation · recurrence / cancellation semantics · change of who owns an operation

### Protected domains

billing · accounting · payment truth · contract semantics · identity · auth · permissions · cross-campus scope · migrations · production data repair · destructive / irreversible ops

### Architecture

new canonical data model · new system authority · new orchestration layer · replacement of an existing authority · major cross-domain abstraction · large App shell decomposition ([`APP_SHELL_DECOMPOSITION_001`](APP_SHELL_DECOMPOSITION_001.md))

### Product exposure

activation of an entirely new product capability · material rollout policy · new role / audience exposure

For these: **DO NOT IMPLEMENT.** The implementing Agent collects evidence, prepares a Decision Packet with options and a recommendation, and stops for **Founder** GO/AMEND. ChatGPT (or any external advisor) is **optional** — not a required planning gate. After GO, return a bounded Plan to the implementing Agent (Cursor/Codex). This rule does **not** expand production or protected-operation authority.

---

## Decision Packet format (`PLAN_REQUIRED`)

Return **exactly**:

### SIGNAL
- in-app id  
- reporter role / campus if legitimately available  
- page  
- original request / problem  

### CURRENT BEHAVIOR
What the product does now.

### USER PROBLEM
Underlying outcome (not just the requested implementation).

### EVIDENCE
reproduction · relevant current code · existing API/service authority · related issues/PRs · production evidence if relevant

### WHY THIS IS NOT AN AUTO-FIX
Exact decision boundary (product semantics / billing / permissions / architecture / destructive mutation / migration / …).

### OPTIONS
Only if there is a real trade-off. For each: user outcome · engineering cost · operational cost · risk · reversibility · authority reused/changed.  
Do not pick an option if Founder judgment is required.

### RECOMMENDED DECISION QUESTION
One precise question for Founder.

### PROPOSED BOUNDED SCOPE
What could ship after GO.

### NON-SCOPE
Adjacent work that stays out.

### STOPPED_STATE
`PLAN_REQUIRED — NO IMPLEMENTATION STARTED`

---

## Prioritization (not FIFO)

Rank unresolved signals by:

1. active production harm  
2. workflow frequency  
3. users / campuses affected  
4. time / error reduction  
5. evidence strength  
6. implementation risk / cost  
7. strategic product value  

A five-minute high-frequency UX defect may outrank a large feature ask.  
A severe billing discrepancy may outrank both — and still require a decision boundary before mutation.

---

## Collision awareness

Before touching files: inspect current `main`, observable open PRs/branches, and avoid overlapping another worker’s active ownership (e.g. Course Manager polish). If the top signal collides, pick the next high-value non-conflicting signal or report the dependency.

---

## No governance theater

Do **not** create: extra generic SOP frameworks · another scheduler · another state machine · new approval framework · giant triage taxonomy · unnecessary architecture docs beyond this policy + deferred shell plan.

Real product work should now generate evidence.
