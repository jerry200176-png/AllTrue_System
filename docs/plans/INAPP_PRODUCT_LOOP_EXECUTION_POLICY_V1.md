# INAPP_PRODUCT_LOOP_EXECUTION_POLICY_V1

**Status:** Active policy (docs)  
**Date:** 2026-09-20

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

### What “closed loop” means

The product loop has three separate completion claims. Never collapse them into one:

1. **Inventory closure**: a fresh, bounded snapshot was reconciled to unique SourceRefs and GitHub issues. This proves coverage of that snapshot only; it does not prove historical completion.
2. **Delivery closure**: the selected item has implementation, tests, review/CI, merge, deploy, runtime identity, and verification evidence appropriate to its risk. A missing reporter reply is not an engineering blocker.
3. **Learning closure**: the root cause and recurrence family were assessed, a proportionate prevention artifact was added or explicitly deferred, the in-app reporter received the lawful public update, and reporter verification or the documented timeout path determined final closure.

An item is not product-loop complete merely because intake exists, a Plan exists, a PR merged, or GitHub was closed. A queue sweep is not complete while an executable, non-conflicting item is silently abandoned after the first PR.

### Evidence chain per SourceRef

Maintain one traceable row per SourceRef. Reuse existing issues, Plans, PRs, release packets, and ownership rather than creating parallel artifacts.

| Field | Minimum evidence |
|------|------------------|
| Source | SourceRef/in-app ID, snapshot time/run, detail run, reporter context when lawful, attachment/comment/status-log coverage |
| Intake | GitHub issue, disposition, expected vs actual or unresolved question, ownership/lease |
| Plan | `not required` with auto-fix criteria, or canonical Plan path + revision/hash + source baseline + approval boundary |
| Build | implementation worker/run where applicable, actual diff/head SHA, focused tests |
| Integrate | review and required CI, PR, merge SHA |
| Release | deploy run, deployed SHA, environment, rollback boundary |
| Verify | R0/R1: exact production SHA, deterministic affected-path regression, and production health/version; record direct production user-path observation separately if available. Higher risk: direct affected production-path evidence remains required. Layout evidence must test readability/usability, not only “no overflow”. |
| Accept | in-app public comment/status and asynchronous reporter verification or documented timeout; do not infer reporter acceptance from engineering delivery |
| Learn | root-cause depth, recurrence search, prevention artifact or explicit debt/defer reason |

Unknown evidence stays `UNKNOWN`, `UNVERIFIED`, or `BLOCKED`; it must not be inferred from a nearby stage.

### Difficulty vs authorization (model routing)

Difficulty (needs Sol/Astra planning) is **not** the same axis as authorization (Founder gate).

- Clear low-risk work inside the auto-fix envelope → approved light implementation profile.
- Complex engineering that is already inside existing authority (expected behavior known, or a visible Founder GO) → strong model produces a bounded Plan revision → light worker implements that revision. A strong Plan does **not** grant new production, identity, billing, migration, or data-repair authority.
- `PLAN_REQUIRED` remains the Founder Decision Packet class. Do not rename or reinterpret it to cancel the Founder gate.
- If the required strong model is unavailable or actual-model evidence cannot exclude a disallowed fallback: mark that item `CAPACITY_BLOCKED`, do not silently downgrade, and continue other authorized work.

Portable contract (do not fork here): portfolio-ops `docs/model-routed-product-delivery.md` and `docs/templates/strong-plan-handoff.md`.

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

Distinguish: code verified → merged → **deployed** → production version verified → production user-path verified → reporter accepted. Record each separately; `NO` and `PENDING` are not synonyms.

For R0/R1 only, when the change has no schema, identity/permission, billing, migration, or production data mutation/repair, engineering delivery may finish with the exact production SHA containing the fix, a deterministic automated regression of the affected path, and production health/version evidence. Direct production user-path observation remains valuable but is not a universal prerequisite for this tier; mark it `NO` when absent. Use the existing `resolved` plus public retest request where applicable, and leave reporter confirmation to the existing asynchronous reporter-verify/timeout process. If the reporter says it still fails, reopen investigation.

For R2/R3 or any data, permission, billing, or migration change, retain the stronger direct affected production-path evidence, existing approvals, rollback, and acceptance gates. This R0/R1 distinction does not downgrade those changes or authorize a synthetic production fixture.

Never report **done** at PR merge alone.  
Do **not** auto-activate a materially new product capability without Founder approval.  
Normal deployment of an already-active, behavior-preserving bugfix is allowed when existing deploy policy permits.

### Root cause and recurrence prevention

Before implementation, record the deepest level supported by evidence:

1. **Observed failure**: reproducible user-path symptom and affected scope.
2. **Direct cause**: specific code/data/contract behavior that produces it.
3. **Recurrence family**: parallel readers/writers, copied logic, missing invariant, earlier incidents, or adjacent flows that can fail the same way.
4. **Prevention**: the smallest durable guard justified by the evidence — normally a regression test; when appropriate, a shared authority/helper, invariant at the write boundary, telemetry, runbook, `AI_REGRESSION_LESSONS`, or a named tech-debt item.

Do not claim “root cause fixed” when only the symptom was hidden. Also do not expand every local defect into architecture work: if recurrence search finds no broader evidence, document that result and ship the bounded fix. If the architectural cause is known but outside the approved scope, keep the local protection, link the canonical debt/RFC, and mark the root fix as outstanding.

For regressions, the test must fail on the pre-fix behavior and cover the target role/campus/data shape when those dimensions caused the failure. A generic build, Super Admin-only smoke test, or unrelated happy path is not sufficient user-path evidence.

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

## Plan and handoff quality

A large Plan is not required for a clear auto-fix. The issue or task artifact must still identify the evidence, expected behavior, bounded files/authority, focused regression, release verification, and rollback boundary.

Complex work already inside existing authority requires a canonical Plan revision before implementation. It must contain:

- SourceRef/task and verified source baseline;
- authoring planner run/session plus requested/effective model;
- Plan path, revision/hash, revision reason, assumptions, and unresolved facts;
- product intent, invariants, architecture/data/permission boundaries, non-scope, and ownership collision check;
- implementation slices, acceptance tests, review/CI, release/runtime verification, rollback, and writeback steps.

The independent worker must receive that exact revision. Record worker run/session, requested/effective model/profile and effective permissions, dispatch evidence, resulting diff/tests/WorkerResult, and canonical result ingest. `ROUTING_RESOLVED`, `WORKER_STARTED`, and `HANDOFF_COMPLETED` are separate claims. Handoff completes only when the effective model is allowed, the worker actually used the Plan, and its result was ingested. Missing metadata is `UNVERIFIED`; a different implementation model may deliver allowed work but does not count as the named-model handoff test.

Reusing a valid Plan is preferred. Revise only for a real source, scope, decision, or baseline change; preserve original authorship and record the reason. Never relabel an older Plan as if a different planner authored it.

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

## Sweep closeout and reporting

At the end of a run, take a fresh queue snapshot using the same coverage checks as intake. Separate:

- items processed from the opening snapshot;
- new arrivals during the run;
- open, resolved, and closed coverage;
- executable remainder, ownership conflicts, evidence waits, capacity blocks, and true Founder decisions.

For every unfinished item, record the current evidence stage, why it stopped, who/what owns the next action, and the exact next step. `NEEDS_EVIDENCE`, `DEFER`, `CAPACITY_BLOCKED`, `WAITING_FOUNDER`, merged, or deployed are not synonyms for fixed or accepted.

The final report must expose the per-SourceRef evidence chain and separately report route resolution, worker start, and completed handoff. If interrupted, update the existing canonical checkpoint; do not promise that an unverified runner will wake itself.

---

## No governance theater

Do **not** create: extra generic SOP frameworks · another scheduler · another state machine · new approval framework · giant triage taxonomy · unnecessary architecture docs beyond this policy + deferred shell plan.

Real product work should now generate evidence.
