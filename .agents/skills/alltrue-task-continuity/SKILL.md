---
name: alltrue-task-continuity
description: Resume or hand off long AllTrue tasks, reconcile stale progress, or recover after repeated failures. Use on task continuation and milestones, not on every edit.
---

# Continue the existing task

This is a Codex skill, not a Claude SessionStart hook or an authorization source.
Preserve the accepted goal, scope and constraints. New messages normally steer
the existing work; do not replace the goal with the latest investigation.

1. Read `docs/RUNBOOK_HANDOFF.md` for pointers, then the relevant source only.
   Use `node scripts/ci/docs-section.mjs --outline <tracked-doc>` followed by
   `--section <tracked-doc> '<exact heading>' [source-sha256]`. The selected
   section is literal source, not a summary. Required policies and applicable
   exceptions still need to be read in full. Old hashes require a fresh read.
2. Keep private goals, source refs, evidence paths, failed attempts and effects
   already performed in `.local/agent-checkpoints/<task>.json` under `context`.
   Never store credentials or raw business rows there. At a milestone run:

   ```bash
   node scripts/ci/task-reconcile.mjs --checkpoint <task> --prs 5107,5120 --save
   ```

   This saves a private observation only after all reads succeed. Update the
   existing public handoff with deidentified pointers, not a second narrative.
3. On continuation, run the same command without `--save`. Read `context` only
   as needed. Compare local changes, live PR heads/state and production manifest.
   A snapshot, green aggregate or old PASS is not permission, current review,
   affected-path verification, or reporter acceptance. Re-read the actual
   execution receipt before repeating an action with side effects.
   For an already approved scope, follow the canonical
   [same-scope approval continuation decision](../../../docs/governance/FOUNDER_STANDING_DECISIONS.md#same-scope-approval-continuation)
   before treating a routine SHA/current-production pin correction as a new decision.
4. Before declaring a blocker, check the approved goal, objects, action, risk
   and any binding or expiry against the original decision. Reuse a valid
   approval. Diagnose tool availability, authentication, role scope and
   missing business evidence separately. A 401 calls for an authentication
   check. Inspect documented, authorized entry points and probe the most
   promising one with a minimal read. When the desktop is occupied, prefer
   an allowed independent session. Change evidence or strategy before retrying
   a known failure. For recurring capability gaps, consult official docs and
   maintained tools before adding a helper.
   Continue independent work from a dated snapshot and mark its limits. Before
   cleanup, check active dependencies and save outstanding work, exclusions and
   next action in the existing checkpoint; follow session disposal policy.
   If personal login is essential, verify the actual handoff surface first.
   Report completed work, the evidenced gap, the recommended action, the one
   step the user must take and how the original task resumes. Keep passwords
   and tokens out of chat. For authorized writes, compare before/after and read
   back the result. Missing historical payment links require reconciliation
   against evidence before creating any financial entry.
5. Use current tool/model availability, not a bundled model list, as the routing
   limit. Keep deterministic work in scripts. For ambiguous logic use a stronger
   supported effort or an authorized independent reviewer; do not assume Opus or
   Sonnet. There is no automatic model router in this skill.
6. For authorized delegation, use a fresh context when the brief is sufficient;
   include goal, exact head, owned paths, evidence and exclusions. Fork history
   only when necessary. Return verdict, source/evidence locations, limitations and
   next step, not raw logs. Separate writing worktrees; reviewers do not edit.

`task-reconcile` is read-only toward GitHub and production. It does not merge,
deploy, approve, repair data, send replies or replay saved commands. Missing
workspace/history requires fresh discovery from the handoff and external state.
Native resume/fork and automatic compaction remain client-owned; do not force a
restart or claim this skill was injected into an already running hosted chat.
