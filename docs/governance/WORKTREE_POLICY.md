# Worktree & repository path policy (canonical)

**Owner:** Founder / CTO Agent  
**Scope:** AllTrue System  
**Rule:** Adapters cite this file — do not redefine paths.

## Default: cloud session (Founder 2026-10-14)

All work runs in Claude cloud sessions so it continues while the Founder's PC
is off. A cloud session starts from a fresh clone; it has no `/home/jerry`.

| Step | Rule |
|------|------|
| Baseline | `origin/main` |
| Branch | `<prefix>/<slug>` with an accepted prefix from `scripts/ci/branch-policy.mjs` (e.g. `feat`, `fix`, `chore`, `ci`, `docs`); one session per branch |
| Evidence | PR diff, risk declaration, review, exact-head required checks |
| Not used | `agent-start`, `make agent-preflight`, `.agent-session/manifest.json`, Exo (retired; ignore `.exo/`) |
| Test accounts | `.cursor/.local/test-credentials.md` is local-only. If the cloud environment has no test accounts, skip the browser test, say so in the PR, and open a `needs-founder` issue |

## Legacy local model (Phase 0.5, being retired)

Only for a task that genuinely needs the local machine.

| Role | Path | Rule |
|------|------|------|
| **Bare object store** | `/home/jerry/workspace/repos/AllTrue_System.git` | fetch/ref/objects only — never write app code |
| **Task worktrees** | `/home/jerry/workspace/tasks/alltrue/<task-id>/` | Only official Agent write path |
| **Launch gateway** | `agent-start alltrue <task-id>` | Must succeed before Agent CLI |
| **Remote baseline** | `origin/main` | Sole code baseline |
| **Forbidden legacy** | `/home/jerry/alltrue`, `/home/jerry/workspace/AllTrue_System`, `/home/jerry/workspace/AllTrue_System-clean`, runner `_work`, backups, `/mnt/c` | Never Agent delivery — each tree may carry `AGENT_WRITES_FORBIDDEN` |


## Machine gates

```bash
make agent-preflight        # any clone: clean tree, branch policy, base == origin/main
make production-identity
```

The local task-path allowlist, `scripts/check-agent-provenance.sh` and the
"Agent Session Provenance" required check were retired by Founder
2026-10-14（三）#6. `.agent-session/manifest.json` (still written by a legacy
local `agent-start`) stays git-ignored and is not delivery evidence; the PR's
diff, risk declaration, review, and required CI are.

## WIP protection

Do not delete unrecovered WIP under forbidden trees. Preserve bundle:
`/home/jerry/workspace-backups/2026-07-19-phase0-wip-preserve/`.
