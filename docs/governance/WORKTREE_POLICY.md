# Worktree & repository path policy (canonical)

**Owner:** Founder / CTO Agent  
**Scope:** AllTrue System  
**Rule:** Adapters cite this file — do not redefine paths.

## Canonical model (Phase 0.5)

| Role | Path | Rule |
|------|------|------|
| **Bare object store** | `/home/jerry/workspace/repos/AllTrue_System.git` | fetch/ref/objects only — never write app code |
| **Task worktrees** | `/home/jerry/workspace/tasks/alltrue/<task-id>/` | Only official Agent write path |
| **Launch gateway** | `agent-start alltrue <task-id>` | Must succeed before Agent CLI |
| **Remote baseline** | `origin/main` | Sole code baseline |
| **Forbidden legacy** | `/home/jerry/alltrue`, `/home/jerry/workspace/AllTrue_System`, `/home/jerry/workspace/AllTrue_System-clean`, runner `_work`, backups, `/mnt/c` | Never Agent delivery — each tree may carry `AGENT_WRITES_FORBIDDEN` |


## Machine gates

```bash
agent-start alltrue <task-id> --dry-run
make agent-preflight
bash scripts/check-agent-provenance.sh
make production-identity
```

`.agent-session/manifest.json` is written locally by `agent-start` and is
git-ignored. Never force-add it: `scripts/check-agent-provenance.sh` rejects
any tracked copy, including one added by the current PR. The local manifest
and agent-control session record identify the task worktree; the PR's diff,
risk declaration, review, and required CI provide delivery evidence. Do not
rewrite an inherited singleton from `main` or update
`.agent-session/human-authored.json` to represent agent work. A genuine human
authorship claim in the PR diff is checked by CI, but self-authored JSON does
not replace git or platform evidence.

## WIP protection

Do not delete unrecovered WIP under forbidden trees. Preserve bundle:
`/home/jerry/workspace-backups/2026-07-19-phase0-wip-preserve/`.

## Proposed cloud runtime profile (requires reviewed activation)

WSL canonical paths and every existing forbidden path remain unchanged.
For a newly isolated managed cloud task only, the equivalent runtime profile is:

- Bare object store: `/workspace/repos/AllTrue_System.git` (objects/refs only).
- Task worktrees: `/workspace/tasks/alltrue/<task-id>/` (no writes in the initial checkout).
- Original versioned gateway, installed from a clean reviewed portfolio-ops commit.
- Invoke that gateway with `AGENT_CONTROL_ROOT=/workspace/cloud-dev/agent-control`,
  `REPOS_ROOT=/workspace/repos`, `TASKS_ROOT=/workspace/tasks`, and
  `ALLTRUE_PRODUCTION_MUTATION=0`. Keep the current `origin/main` baseline,
  expected AllTrue origin, non-main task branch, clean-tree, GitHub auth and
  production-mutation guards. Require a new gateway-created session/manifest.
- Both the gateway bootstrap allowlist and the product allowlist must contain
  the cloud task prefix through reviewed source changes. Runtime root variables
  alone grant no path permission. No CI mode or ad hoc config override qualifies.
- Run the unchanged in-tree `make agent-preflight` and provenance check in the
  gateway-created task worktree. Independently match its new manifest to the
  gateway session registry, actual Git root/HEAD/branch and origin.
- No production credentials, data or operations are part of this profile.
  Passing these local checks does not establish GitHub API/write/merge/deploy
  capabilities; verify each separately under the applicable authorization.

This profile is development-only and applies to AllTrue only. Cloud snapshots
must retain the bare repository, registered task, gateway runtime provenance
and session registry consistently; restoration in a new task remains unproven
until tested. A running agent process is not retained by a filesystem snapshot.
