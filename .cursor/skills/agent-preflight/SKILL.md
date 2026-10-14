# Agent Preflight (Cursor adapter)

Tool-neutral: `make agent-preflight`  
Policy: `docs/governance/WORKTREE_POLICY.md`  
Config: `scripts/agent-preflight.config.json`

**Cloud session (default since 2026-10-14):** skip this skill; follow the cloud section of `docs/governance/WORKTREE_POLICY.md`.

Before any write in a local WSL worktree:

1. Run preflight — must exit 0.
2. Refuse `/home/jerry/alltrue`, runner `_work`, backups, `/mnt/c`.
3. Require clean worktree, non-`main` branch with `type/slug`, base == `origin/main`.
4. Production mutation env must stay disabled.
5. Use `make production-identity` for prod truth (not CI green).
