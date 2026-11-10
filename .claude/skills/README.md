# Claude Code skills

Claude Code loads project skills from `.claude/skills/<name>/SKILL.md`.

## Linked skills (single source of truth)

Each entry is a relative symlink. Edit the canonical file, not the link.

| Skill | Canonical location |
|---|---|
| `alltrue-inapp-product-loop`, `alltrue-code-review`, `alltrue-debugging`, `alltrue-release`, `alltrue-security`, `alltrue-testing`, `security-audit`, `design-taste-frontend`, `redesign-existing-projects` | `.agents/skills/<name>/` |

`grill-me`, `grilling`, `impeccable`, `ui-ux-pro-max`, `make-interfaces-feel-better` and `web-design-guidelines` are vendored here directly (see `VENDORED.md`).
`agent-preflight` and `knowledge-graph-link` (retiring local WSL flow) were removed with `.cursor/skills/`; nothing referenced them.
`scripts/ci/inapp-product-loop-entry.test.mjs` fails if a link is missing or points somewhere else.

## Legacy references in these skills

| Skill text says | In Claude Code |
|---|---|
| `agent models` (check the Sol slug) | Not applicable; the session model is fixed. Don't substitute a weaker model for a step that requires strong planning, and say so in the PR. |
| `agent-start alltrue <task-id>` / WSL worktree | Cloud session: branch from `origin/main` (`docs/governance/WORKTREE_POLICY.md`). |
| Local `vendor/bin/phpunit` / `/tmp/backend-test-*` (WSL) | Never on the Pi/production (R2). In a cloud session, running `vendor/bin/phpunit` against the local `AllTrue_test` MariaDB is allowed and expected before pushing (the SessionStart hook installs `vendor/`, `node_modules/` and that DB); CI (MySQL 8) stays the source of truth. |
| `.cursor/.local/test-credentials.md` | Local only, never committed; not available in cloud sessions. |
| `$HOME/workspace/state/alltrue/delivery/` | Local WSL state; absent in cloud sessions. Use GitHub issues/PRs. |

**Cloud checks before pushing:** when dependency setup succeeds, run `cd backend && vendor/bin/phpunit --filter X` (or a test file) and `vendor/bin/phpstan analyse --memory-limit=1G <files>`; CI (MySQL 8) stays the source of truth. SessionStart failures are non-fatal and do not establish test readiness.

The hook preserves the managed runtime proxy and CA trust, disables dependency lifecycle scripts/plugins, and does not seed Git archives after a download refusal. Backend setup requires Composer's `source-fallback=false` control and verifies that project configuration has not enabled it; an unsupported Composer version safely skips the install. See [Composer's security rationale](https://getcomposer.org/doc/06-config.md#source-fallback). Database clients ignore default connection files and inherited MySQL variables and use only `/run/mysqld/mysqld.sock`; failed provisioning never claims readiness. These are local cloud-container operations, never a Production setup path.

Offline regression: `python3 .claude/hooks/test_session_start.py`. The existing entry check runs it too; all installers, network and DB commands are synthetic mocks.
