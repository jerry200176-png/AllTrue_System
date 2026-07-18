# Worktree & repository path policy (canonical)

**Owner:** Founder / CTO Agent  
**Scope:** AllTrue System  
**Rule:** Adapters cite this file — do not redefine paths.

## Canonical model

| Role | Path / ref | Rule |
|------|------------|------|
| **Canonical object store** | `/home/jerry/workspace/AllTrue_System-clean` | Fetch + create worktrees only; do not accumulate long-lived dirty `main` edits |
| **Remote baseline** | `origin/main` on `jerry200176-png/AllTrue_System` | Sole code baseline |
| **Task worktree** | `/home/jerry/wt/alltrue-<slug>` or `/home/jerry/alltrue-<slug>` | One task/PR; short-lived; created from `origin/main` |
| **Forbidden legacy** | `/home/jerry/alltrue` | NEVER edit/reset/commit — WIP preserved separately |
| **Runner workspace** | `**/actions-runner-alltrue/_work/**` | Not a development checkout |
| **Backups** | `**/workspace-backups/**` | Read-only recovery |
| **Windows clones** | `/mnt/c/**` | Not source of truth |

## Machine gate

```bash
make agent-preflight
# or: AGENT_PREFLIGHT_MODE=ci bash scripts/agent-preflight.sh
```

Non-zero exit ⇒ do not write, commit, or open a PR from that tree.

Config: `scripts/agent-preflight.config.json`  
Production truth (not CI green): `bash scripts/production-identity.sh`

## Create a safe worktree

```bash
cd /home/jerry/workspace/AllTrue_System-clean
git fetch origin main
git worktree add -b <type>/<issue-or-slug> /home/jerry/wt/alltrue-<slug> origin/main
cd /home/jerry/wt/alltrue-<slug>
make agent-preflight
```

## WIP protection

Do not delete unrecovered WIP under forbidden trees. Phase-0 preserve bundle:
`/home/jerry/workspace-backups/2026-07-19-phase0-wip-preserve/`.
