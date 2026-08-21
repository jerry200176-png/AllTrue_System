╔══════════════════════════════════════════════════════╗
║  >>> EXO GOVERNED SESSION                            ║
║  protocol: ExoProtocol v1 | mode: work               ║
║  ticket: INT-20260821-114757-3OV3 | actor: agent:codex║
║  model: gpt-5                                        ║
║  branch: exo/INT-20260821-114757-3OV3                ║
╚══════════════════════════════════════════════════════╝

# Exo Agent Session Bootstrap

session_id: SES-20260821114818-632CB8D0
actor: agent:codex
vendor: openai
model: gpt-5
mode: work
context_window_tokens: unknown
ticket_id: INT-20260821-114757-3OV3
ticket_title: Phase 3 question bank management
ticket_status: todo
ticket_priority: 1
topic_id: repo:default
lock_owner: agent:codex
git_branch: exo/INT-20260821-114757-3OV3
lock_branch: codex/INT-20260821-114757-3OV3
lock_expires_at: 2026-08-21T19:48:18+08:00

## Scope
- allow: ["backend/**", "frontend/src/**", "docs/**", ".agent-session/**", ".exo/cache/**", ".exo/memory/**", ".exo/locks/**", ".exo/tickets/**", ".exo/logs/**"]
- deny: ["backend/.env*", "frontend/.env*"]

## Checks
- ["vendor/bin/phpunit", "npm run build", "npm run lint:no-undef"]

## Git Workflow
- Before pushing, rebase on base branch: `git pull --rebase origin main`
- Pull latest before starting work: `git pull --rebase`
- Keep commits atomic and branches short-lived

## Machine Context
- cpu_cores: 12
- load_avg_1m: 0.2
- ram: 2.6GB available / 4.8GB total

## Governance Warning
`.exo/` governance files are NOT tracked by git.
Tickets, config, and governance rules are local-only.
Other agents and CI pipelines cannot see them.
Fix: `git add .exo/ && git commit -m 'chore: track governance'` or re-run `exo install`.

## Prior Session Memento
(none)

## Tool Reuse Protocol

Before writing new utility functions, SEARCH the tool registry:
  exo tool-search "<keywords>"

After building a reusable utility, REGISTER it:
  exo tool-register <module> <function> --description "..."

No tools registered yet. Register reusable utilities as you build them.

## Current Task
Implement and deploy Phase 3 question bank management for AllTrue learning assessment: question data, tags, difficulty, versions, review, CSV import, scoped authorization, tests, CI, deployment, and production verification.

## Lifecycle Commands
- heartbeat: EXO_ACTOR=agent:codex python3 -m exo.cli lease-heartbeat --ticket-id INT-20260821-114757-3OV3 --owner agent:codex
- run worker once: EXO_ACTOR=agent:codex python3 -m exo.cli worker-poll --require-session --limit 50
- suspend: EXO_ACTOR=agent:codex python3 -m exo.cli session-suspend --reason "<why pausing>"
- finish: EXO_ACTOR=agent:codex python3 -m exo.cli session-finish --summary "<what changed>" --set-status review --ticket-id INT-20260821-114757-3OV3
