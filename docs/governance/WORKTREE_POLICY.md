# Worktree & repository path policy (canonical)

**Owner:** Founder / CTO Agent  
**Scope:** AllTrue System (+ agents working on sunrise-cafe must not use AllTrue forbidden paths)  
**Rule:** Adapters (`AGENTS.md`, `CLAUDE.md`, `.cursorrules`, Skills) **cite this file** — do not redefine paths.

## Path classes

| Class | Meaning | Examples |
|-------|---------|----------|
| **Forbidden legacy / dirty** | NEVER edit, reset, commit, or treat as canonical checkout | `/home/jerry/alltrue` (also `~/alltrue` when it resolves to that directory) |
| **Canonical repository** | Source of truth for code & governance on GitHub | Remote `jerry200176-png/AllTrue_System` branch `main` |
| **Temporary task worktree** | Safe local checkout for one task/PR | Any new path **outside** the forbidden list, created with `git worktree add` from `origin/main` |

## Forbidden paths (exact)

Agents and humans must refuse writes when `pwd` / git common-dir resolves to:

1. `/home/jerry/alltrue`
2. Any symlink target that resolves to `/home/jerry/alltrue`

Do **not** `git reset --hard`, force-push, or “clean up” WIP inside a forbidden path.

## How to create a safe worktree

From any healthy clone that already tracks `origin` (not the forbidden tree):

```bash
git fetch origin main
git worktree add -b <type>/<slug> /home/jerry/alltrue-<slug> origin/main
cd /home/jerry/alltrue-<slug>
# optional: bash scripts/agent-preflight.sh
```

Prefer paths under `/home/jerry/alltrue-<task>` or `/tmp/alltrue-<task>` — **never** reuse `/home/jerry/alltrue`.

## Product isolation

- Do not mix AllTrue System and sunrise-cafe changes in one worktree or one PR.
- sunrise-cafe: use that repo’s own clone/worktree; portfolio policy is pinned via its `docs/governance/OVERLAY.md`.

## WIP protection

- Forbidden trees may hold unrecovered WIP — leave them untouched.
- Do not use `git update-index --assume-unchanged` / `skip-worktree` as a substitute for worktrees (see `AI_REGRESSION_LESSONS` §Y6 / R58).

## Machine check

```bash
bash scripts/agent-preflight.sh
# or: make agent-preflight
```

Non-zero exit ⇒ do not write.
