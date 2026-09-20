# ASSIGNMENT — Worker A — H4 PLAN ONLY

**goal_id:** alltrue-supervisor-dispatch  
**task:** H4-PLAN  
**status:** DISPATCHED  
**phase:** PLANNING — Supervisor Plan Review before any implementation  
**dispatch:** `/home/jerry/workspace/state/alltrue/DISPATCH_H4_PLAN.json`  
**unlock:** H3 ACCEPTED — `/home/jerry/workspace/state/alltrue/H3_ACCEPTANCE.json` (#3014 @ `51b4d30de`)

## Outcome
Produce an H4 dispatch **Plan** for Supervisor review (CAS acquire/renew/reclaim,
PlanResult revalidation, deny-and-continue, evidence, tests). **NO implementation.**

## Scope (allowed)
- Docs only under `docs/harness/**` on branch `chore/task-harness-h4-plan`
- Draft docs-only PR for Plan Review evidence
- Progress JSON: `/home/jerry/workspace/state/alltrue/_a_h4_plan_progress.json`

## Forbidden
- H4 implementation (CAS acquire wiring in dispatch module, launcher, worktree-create agent runtime)
- Production approve / deploy
- Second autonomy classifier
- Weakening H3 A1–A6
- Combining H4 impl in same PR as this plan without separate Plan Review gate

## Completion criteria — Plan must include
1. Objectives  
2. Module boundaries (leases CAS; PlanResult revalidation; deny-and-continue)  
3. State transitions  
4. Evidence schema  
5. Failure modes  
6. Founder boundaries  
7. File list + size ≤1300 for later impl  
8. Test plan  
9. Handoff from H3 PlanResult world-bind fields  

**STOP** for Supervisor Plan Review. Do not implement.

## Evidence required
- Plan path: `docs/harness/H4_DISPATCH_PLAN.md`
- Draft PR URL + number
