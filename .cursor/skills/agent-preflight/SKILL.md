# Agent Preflight (Cursor adapter)

Tool-neutral: `make agent-preflight`  
Policy: `docs/governance/WORKTREE_POLICY.md`  
Config: `scripts/agent-preflight.config.json`

Before any write:

1. Run preflight — must exit 0.
2. Refuse `/home/jerry/alltrue`, runner `_work`, backups, `/mnt/c`.
3. Require clean worktree, non-`main` branch with `type/slug`, base == `origin/main`.
4. Production mutation env must stay disabled.
5. Use `make production-identity` for prod truth (not CI green).
