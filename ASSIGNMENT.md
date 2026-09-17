# ASSIGNMENT — Worker C — TF-S6-01 PLAN ONLY

**goal_id:** alltrue-supervisor-dispatch  
**task:** TF-S6-01-PLAN  
**status:** PLAN_REVIEW  
**phase:** PLANNING — Supervisor Plan Review gate before any implementation  
**activated_at:** 2026-09-17T00:13:30Z  
**worker_id:** C  
**base_sha:** `f69b14ea98c9800985732787a927a5058578dbbe` (main tip after #3012)  
**branch:** `chore/task-truefit-s6-01-plan`  
**worktree:** `/home/jerry/workspace/tasks/alltrue/truefit-s6-01-plan`  
**line_gate (impl later):** **≤700** — DO NOT WEAKEN without Founder  

## Prerequisite (satisfied)

| Item | Evidence |
|------|----------|
| TF-S6-00 ACCEPTED | `/home/jerry/workspace/state/alltrue/TF_S6_00_ACCEPTANCE.json` |
| 00a MERGED | #3010 → `f0907cbca33d0eda6a7dd95826edb979799f100a` |
| 00b MERGED | #3012 → `f69b14ea98c9800985732787a927a5058578dbbe` |
| Flags | `TRUEFIT_V1` / `VITE_TRUEFIT_V1` **OFF** |

## Outcome (this dispatch)

Produce a **Plan** for Supervisor review covering next TrueFit continuum hardening after S6-00. **NO implementation code PR.** Docs-only draft PR is OK.

Plan path: `docs/truefit/TF_S6_01_PLAN.md`

## Scope (allowed)

- Planning docs under `docs/truefit/**`
- `ASSIGNMENT.md` / `PLAN.md` companion in this worktree
- Docs-only commits on `chore/task-truefit-s6-01-plan`
- Draft PR titled: `docs(truefit): TF-S6-01 Plan (review only — no impl)`

## Forbidden

- TF-S6-01 **implementation** before Plan Review GO
- TF-S6-02 any work
- Turning `TRUEFIT_V1` / `VITE_TRUEFIT_V1` **ON**
- RFID / billing / auth / scheduling scope creep
- Prod approve run `35111700889`
- Weakening ≤700 line gate without Founder
- Claiming operational acceptance or flag-on pilot complete

## Exclusive lease

Worker C = `truefit/**` (frontend TrueFit continuum + docs/truefit plan) — **docs only** this phase.

## Completion criteria — STOP at Plan Review

Hand Supervisor:

1. Objectives  
2. Module boundaries  
3. Non-goals  
4. Founder / Supervisor boundaries  
5. Proposed file list + size estimate  
6. Test plan  
7. Progress JSON at `/home/jerry/workspace/state/alltrue/_c_s6_01_plan_progress.json`

**STOP.** Do not implement.

## Dispatch / authority packets

- `/home/jerry/workspace/state/alltrue/DISPATCH_C_TF_S6_01_PLAN.json`
- `/home/jerry/workspace/state/alltrue/TF_S6_00_ACCEPTANCE.json`
