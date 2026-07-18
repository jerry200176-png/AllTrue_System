# Agent Capability Registry

**Rule:** Capabilities are Proven / Partial / Unsafe / Missing. Never assume PR merge, deploy, or in-app write without evidence.

| Capability | Status | Evidence (2026-07-18) | Notes |
|------------|--------|----------------------|-------|
| Read git worktrees | Proven | Multiple worktrees listed | Never use `/home/jerry/alltrue` dirty diverged main |
| `gh` PR/issue (repo,workflow) | Proven | PR #1298/#1299 merges | |
| Push feature branch | Proven | | |
| Merge PR via gh | Proven | After CI green | Needs approval for high-risk |
| AllTrue Deploy to Pi | Proven | workflow after main CI | |
| Prod read `daan.lifenet.com.tw/version.json` | Proven | | `alltrue.tw` DNS may fail on WSL |
| In-app bug GET | Proven | `/api/v1/bugs/{id}` | Token: `~/.alltrue-admin-token` (local, not in git) |
| In-app bug POST comment/status | Proven | #201/#202 resolved | Requires `super_admin` |
| Local PHPUnit isolated | Proven | `scripts/phpunit-isolated.sh` | Worktree must have own `composer install` (no vendor symlink) |
| Frontend vitest | Proven | after npm ci | |
| sunrise verify-production | Proven | SHA poll | |
| sunrise autonomous-loop dispatch | **Unsafe** | Self-dispatch storm; workflow **disabled** | Do not re-enable blindly |
| MCP tools | Missing/Partial | Catalog often empty | |
| MemPalace | Partial | Machine-local `~/.mempalace` | Not portable to fresh Codex host |

Update this table when a capability is newly proven or broken.
