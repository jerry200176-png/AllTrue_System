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
| **Forbidden legacy** | `/home/jerry/alltrue`, `AllTrue_System-clean`, runner `_work`, backups, `/mnt/c` | Never Agent delivery |

## Machine gates

```bash
agent-start alltrue <task-id> --dry-run
make agent-preflight
bash scripts/check-agent-provenance.sh
make production-identity
```

PR must include `.agent-session/manifest.json` (Agent) or `.agent-session/human-authored.json` (human).

## WIP protection

Do not delete unrecovered WIP under forbidden trees. Preserve bundle:
`/home/jerry/workspace-backups/2026-07-19-phase0-wip-preserve/`.
# provenance block test 20260718T233248Z
