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
