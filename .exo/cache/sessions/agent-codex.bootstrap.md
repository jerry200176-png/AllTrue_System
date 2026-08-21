╔══════════════════════════════════════════════════════╗
║  >>> EXO GOVERNED SESSION                            ║
║  protocol: ExoProtocol v1 | mode: work               ║
║  ticket: TKT-20260821-115408-HRYH | actor: agent:codex║
║  model: gpt-5                                        ║
║  branch: feat/task-learning-assessment-mvp-question-bank║
╚══════════════════════════════════════════════════════╝

# Exo Agent Session Bootstrap

session_id: SES-20260821115450-D899A6E2
actor: agent:codex
vendor: openai
model: gpt-5
mode: work
context_window_tokens: unknown
ticket_id: TKT-20260821-115408-HRYH
ticket_title: Question bank API and data contract
ticket_status: todo
ticket_priority: 1
topic_id: repo:default
lock_owner: agent:codex
git_branch: feat/task-learning-assessment-mvp-question-bank
lock_branch: codex/TKT-20260821-115408-HRYH
lock_expires_at: 2026-08-21T19:54:50+08:00

## Scope
- allow: ["backend/**", "docs/**", ".exo/cache/**", ".exo/memory/**", ".exo/locks/**", ".exo/tickets/**", ".exo/logs/**"]
- deny: ["backend/.env*"]

## Checks
- ["vendor/bin/phpunit", "npm run build", "npm run lint:no-undef"]

## Git Workflow
- Before pushing, rebase on base branch: `git pull --rebase origin main`
- Pull latest before starting work: `git pull --rebase`
- Keep commits atomic and branches short-lived

## Machine Context
- cpu_cores: 12
- load_avg_1m: 0.1
- ram: 2.6GB available / 4.8GB total

## Prior Session Memento
(none)

## Tool Reuse Protocol

Before writing new utility functions, SEARCH the tool registry:
  exo tool-search "<keywords>"

After building a reusable utility, REGISTER it:
  exo tool-register <module> <function> --description "..."

No tools registered yet. Register reusable utilities as you build them.

## Current Task
question-bank-api

## Lifecycle Commands
- heartbeat: EXO_ACTOR=agent:codex python3 -m exo.cli lease-heartbeat --ticket-id TKT-20260821-115408-HRYH --owner agent:codex
- run worker once: EXO_ACTOR=agent:codex python3 -m exo.cli worker-poll --require-session --limit 50
- suspend: EXO_ACTOR=agent:codex python3 -m exo.cli session-suspend --reason "<why pausing>"
- finish: EXO_ACTOR=agent:codex python3 -m exo.cli session-finish --summary "<what changed>" --set-status review --ticket-id TKT-20260821-115408-HRYH
