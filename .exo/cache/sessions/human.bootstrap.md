╔══════════════════════════════════════════════════════╗
║  >>> EXO GOVERNED SESSION                            ║
║  protocol: ExoProtocol v1 | mode: work               ║
║  ticket: TKT-20260830-063318-7B6K | actor: human     ║
║  model: gpt-5                                        ║
║  branch: exo/TKT-20260830-063318-7B6K                ║
╚══════════════════════════════════════════════════════╝

# Exo Agent Session Bootstrap

session_id: SES-20260830130922-8538ECA6
actor: human
vendor: openai
model: gpt-5
mode: work
context_window_tokens: unknown
ticket_id: TKT-20260830-063318-7B6K
ticket_title: Lock public bug reply transport
ticket_status: review
ticket_priority: 2
topic_id: repo:default
lock_owner: human
git_branch: exo/TKT-20260830-063318-7B6K
lock_branch: codex/TKT-20260830-063318-7B6K
lock_expires_at: 2026-08-30T15:09:22+08:00

## Scope
- allow: [".github/workflows/bug-phase-a-triage.yml", ".github/workflows/bug-followup-comment.yml", "scripts/ci/bug-writeback-workflow.test.mjs", ".agent-session/manifest.json", ".exo/**", ".exo/cache/**", ".exo/memory/**", ".exo/locks/**", ".exo/tickets/**", ".exo/logs/**"]
- deny: []

## Checks
- ["npm run test:unit", "npm run build"]

## Git Workflow
- Before pushing, rebase on base branch: `git pull --rebase origin main`
- Pull latest before starting work: `git pull --rebase`
- Keep commits atomic and branches short-lived

## Machine Context
- cpu_cores: 12
- load_avg_1m: 0.3
- ram: 3.5GB available / 4.8GB total

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
Rebase security fix PR #2225 onto current main and rerun governed verification; do not merge or dispatch production

## Lifecycle Commands
- heartbeat: EXO_ACTOR=human python3 -m exo.cli lease-heartbeat --ticket-id TKT-20260830-063318-7B6K --owner human
- run worker once: EXO_ACTOR=human python3 -m exo.cli worker-poll --require-session --limit 50
- suspend: EXO_ACTOR=human python3 -m exo.cli session-suspend --reason "<why pausing>"
- finish: EXO_ACTOR=human python3 -m exo.cli session-finish --summary "<what changed>" --set-status review --ticket-id TKT-20260830-063318-7B6K
