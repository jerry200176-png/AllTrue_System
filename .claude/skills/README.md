# Claude Code skills

Claude Code loads project skills from `.claude/skills/<name>/SKILL.md`.

## Linked skills (single source of truth)

Each entry is a relative symlink. Edit the canonical file, not the link.

| Skill | Canonical location |
|---|---|
| `alltrue-inapp-product-loop` | `.agents/skills/alltrue-inapp-product-loop/` |
| `alltrue-code-review`, `alltrue-debugging`, `alltrue-release`, `alltrue-security`, `alltrue-testing` | `.cursor/skills/<name>/` |
| `security-audit`, `design-taste-frontend`, `redesign-existing-projects` | `.cursor/skills/<name>/` |

`grill-me` and `grilling` are vendored here directly (see `VENDORED.md`).
`agent-preflight` and `knowledge-graph-link` are not linked; they serve the retiring local WSL worktree flow.
`scripts/ci/inapp-product-loop-entry.test.mjs` fails if a link is missing or points somewhere else.

## Cursor-only references in these skills

| Skill text says | In Claude Code |
|---|---|
| `bug-fix-plan.mdc`, `module-test.mdc`, `module-security.mdc`, `user-facing-communication.mdc` (and other `*.mdc`) | Plain files: read `.cursor/rules/<file>.mdc`. Cursor auto-attaches them; Claude does not, so open the one the skill names. |
| `.cursorrules` | Read the file; `CLAUDE.md` points to it for the full workflow. |
| `agent models` (check the Sol slug) | Not applicable; the session model is fixed. Don't substitute a weaker model for a step that requires strong planning, and say so in the PR. |
| `agent-start alltrue <task-id>` / WSL worktree | Cloud session: branch from `origin/main` (`docs/governance/WORKTREE_POLICY.md`). |
| Local `vendor/bin/phpunit` / `/tmp/backend-test-*` (WSL) | GitHub Actions only (R2). The SessionStart hook installs `vendor/` for PHPStan and lint. |
| `.cursor/.local/test-credentials.md` | Local only, never committed; not available in cloud sessions. |
| `$HOME/workspace/state/alltrue/delivery/` | Local WSL state; absent in cloud sessions. Use GitHub issues/PRs. |
| `.cursor/skills-cursor/*` (create-rule, babysit, ...) | Cursor built-ins with no repo equivalent; use Claude Code's own `/code-review`, hooks and skills. |
