# ASSIGNMENT — Worker A — H3 PLAN ONLY

**goal_id:** alltrue-supervisor-dispatch  
**task:** H3-PLAN  
**status:** DISPATCHED  
**phase:** PLANNING — Supervisor review gate before any implementation

## Outcome
Produce an H3 planner **Plan** for Supervisor review (architecture + task selection interface + evidence requirements). **NO implementation PR yet.**

## Scope (allowed)
- Planning docs only under `docs/harness/**` OR plan artifact under  
  `/home/jerry/workspace/state/alltrue/goals/harness/H3-PLAN/`
- Prefer returning Plan as markdown/JSON in this worktree **without merging code** until Plan approved.
- Docs-only commits on branch `chore/task-harness-h3-plan` are OK as Draft evidence.

## Forbidden
- Implementing `planner.py` / loop complexity until Plan accepted by Supervisor
- H4–H9, production, TrueFit, in-app, RFID
- Opening an implementation PR for H3 code
- Touching paths outside exclusive lease: `scripts/harness/**` and `docs/harness/**` (docs-only for this phase; do not change harness runtime code yet)

## Exclusive lease
Worker A = `scripts/harness/**` (+ docs/harness for plan docs)

## Completion criteria — Plan must include
1. Objectives
2. Module boundaries
3. State transitions used
4. Evidence schema
5. Failure modes
6. Founder boundaries
7. Proposed file list + estimated size
8. Test plan

**STOP** for Supervisor Plan review. Do not implement.

## Evidence required
- Plan document path
- SHA if committed on branch (Draft OK)

## Dispatch packet
`/home/jerry/workspace/state/alltrue/DISPATCH_H3_PLAN.json`  
Goal session: `/home/jerry/workspace/state/alltrue/goals/harness/H3-PLAN/.agent-session/harness-goal.json`
