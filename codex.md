# Portfolio agent governance overlay

This file is committed because a cloud or mobile agent may not have access to
Ubuntu's `/home/jerry`. The canonical, detailed policy is maintained in
`jerry200176-png/portfolio-ops` under `governance/`; this file is the portable
minimum that travels with each governed repository.

## Required behavior

- Treat Cursor, Codex, Claude Code, and Cubelv as untrusted writers.
- Before writing, identify the repository, branch/worktree, task scope, risk,
  and verification plan. Never work directly on the default branch.
- Read this repository's committed instructions and ExoProtocol's `.exo/`
  constitution/lock when present. Do not edit governance files to make a task
  pass or to bypass a lock, ticket, session, CI check, or review.
- Every change goes through a pull request and requires an independent human
  approval. Branch prefixes and self-authored provenance files never grant a
  bypass.
- Agents may prepare code and Draft PRs. Merge, deploy, production-data
  mutation, credential rotation/revocation, issue closure, and history rewrite
  require Founder approval.
- Prefer mature open-source tools for generic lint, security, workflow, and
  policy checks; keep company-specific risk, provenance, evidence, and release
  boundaries in committed policy and CI.

If this overlay conflicts with a stricter product or safety rule, the stricter
rule wins. If the overlay or required governance context is unavailable, stop
and report the missing context instead of inventing a replacement.

<!-- exo:governance:begin -->
<!-- Governance hash: f36ea664fa045696 -->
# ExoProtocol — Codex Operating Instructions

This repository is governed by ExoProtocol. All AI agent work must follow the session lifecycle.

## ExoProtocol Governance

- kernel: exo-kernel 0.1.0
- lock hash: `f36ea664fa045696...`
- generated: 2026-08-23T15:21:55+08:00

### Filesystem Deny Rules

- **RULE-SEC-001**: deny read, write on `~/.aws/**`, `~/.ssh/**`, `**/.env`, `**/.env.local`, `**/.env.*.local`, `**/.env.production`, `**/.env.staging`, `**/.env.development`, `**/.env.test`
- **RULE-GIT-001**: deny read, write, delete on `.git/**`

### Structural Rules

- **RULE-LOCK-001** (require_lock): Blocked by RULE-LOCK-001 (acquire a ticket lock first).
- **RULE-CHECK-001** (require_checks): Blocked by RULE-CHECK-001 (checks must pass before done).
- **RULE-EVO-001** (evolution_gate): Practice is mutable, governance requires explicit human approval.
- **RULE-EVO-002** (patch_first): Patch-first evolution required.

### Default Budgets

- max files changed: 12

### Approved Checks

- `npm --prefix frontend run test:unit`
- `npm run lint:no-undef`
- `npm run build`
- `bash scripts/phpunit-isolated.sh`

### Active Intents

- **INT-20260823-235337-GPGC**: 降低 AllTrue 主任課程編輯失敗與誤用流程 — boundary: *不碰 production DB、正式部署、付款資料批次修復、既有治理檔案；只改課程管理前後端、對帳診斷與測試。*
  - TKT-20260823-235344-W26Y: Implement director course-edit triage and reconciliation safeguards [allow: backend/app/Http/Controllers/StudentClassController.php, backend/app/Services/**, backend/routes/api.php, backend/tests/Feature/**, frontend/src/pages/CourseManagement.vue, frontend/src/components/CourseEditForm.vue, frontend/src/components/course-management/**, frontend/src/lib/**, frontend/src/**/*.test.*, docs/**, .exo/cache/**, .exo/memory/**, .exo/locks/**, .exo/tickets/**, .exo/logs/**]
- **INT-20260824-003324-GDVV**: Align Exo governance with AllTrue monorepo checks — boundary: *Do not change product application logic, production data, deployment workflows, or application dependencies; governance manifests and generated agent adapters only.*
  - TKT-20260824-003332-G1J3: Apply monorepo governance check and adapter alignment [allow: .exo/**, AGENTS.md, CLAUDE.md, .cursorrules, codex.md, .exo/cache/**, .exo/memory/**, .exo/locks/**, .exo/tickets/**, .exo/logs/**]

### Source of Truth

The values above are a **snapshot** generated from the governance manifest.

Manifest paths:
- `.exo/config.yaml` — budgets, checks allowlist, scheduler config
- `.exo/governance.lock.json` — compiled rules, deny patterns, source hash

### Test-Driven, Manifest-First Workflow

This principle applies to **all code you write** — governance and application logic alike.

1. **Config/contract is the source of truth.** When a value is defined in a config file,
   schema, manifest, or contract — code must load it from that source at runtime.
   Never copy a value from a config file and paste it as a literal in source code.
2. **Tests verify the wiring, not the value.** Tests must assert that code reads from
   the config/contract, not that it produces a specific hardcoded result.
   A test that passes when you swap the config value *and* swap the assertion is useless —
   it only proves both sides were copy-pasted from the same place.
3. **If you can change a config value and no test breaks, the test is missing.**
   Every configurable value should have at least one test that will vary the input
   and verify the output follows.

Examples:
- **BAD**: `assert budget == 10` (hardcoded, passes even if config is ignored)
- **GOOD**: set config to 42, assert output contains 42 and not the old default
- **BAD**: `MAX_RETRIES = 3` (literal in source when retries is in config)
- **GOOD**: `max_retries = load_config()['max_retries']`

### Operational Learnings

When you discover a reusable pattern, gotcha, or operational insight during a session:
- Record it with `exo reflect` (CLI) or `exo_reflect` (MCP) — NOT your private memory
- ExoProtocol reflections are injected into future session bootstraps for all agents
- Private memory files (MEMORY.md, .cursorrules, etc.) are agent-specific and invisible to the team
- If you must write to private memory, also create an ExoProtocol reflection with the same insight

**Private memory monitoring**: If `private_memory.watch_paths` in `.exo/config.yaml` is empty,
add the absolute path to your memory file (e.g., `~/.claude/.../memory/MEMORY.md`) so that
ExoProtocol can detect when you write to private memory without creating a shared reflection.

### End-of-Work Reflection

When you complete significant work or the user appears to be wrapping up:
- **Proactively** run `exo reflect --pattern '<what kept happening>' --insight '<what was learned>'`
  for each non-trivial insight discovered during the conversation
- Do NOT wait for `session-finish` — many users close the editor without explicit session end
- Good reflection triggers: bug fixes, CI failures, gotchas, architectural decisions, workflow improvements

### Tool Reuse Protocol

Before writing new utility functions, SEARCH the tool registry:
  `exo tool-search "<keywords>"`

After building a reusable utility, REGISTER it:
  `exo tool-register <module> <function> --description "..."`

Mark a tool as used when you import/call it:
  `exo tool-use <tool_id>`

**Registered Tools (4):**
- `frontend.src.lib.parentAssessmentProgress.js:formatAssessmentProgressDate`: Format reviewed parent assessment dates for display.
- `frontend.src.lib.parentAssessmentProgress.js:assessmentProgressScoreLabel`: Format safe parent assessment score labels.
- `frontend.src.lib.parentAssessmentProgress.js:assessmentProgressPercentLabel`: Format safe parent assessment percentage labels.
- `frontend.src.lib.dashboardLoadPlan.js:runDashboardLoaders`: Run independent dashboard loaders concurrently with isolated failures


## Session Lifecycle

1. `exo session-start --ticket-id <TICKET> --vendor openai --model <MODEL> --task "<TASK>"`
2. Read `.exo/cache/sessions/<actor>.bootstrap.md`
3. Execute work within ticket scope
4. `exo session-finish --ticket-id <TICKET> --summary "<SUMMARY>" --set-status review`

## Approval Mode

Recommended Codex approval mode for this repo: **suggest**

- `suggest` (recommended when checks are configured): Codex proposes changes, human approves
- `auto-edit`: Codex applies changes automatically (use only in governed sessions)
- `full-auto`: Full autonomy (requires active governed session + sandbox enforcement)

Run with: `codex --approval-mode suggest`

## Sandbox Policy

The following paths are denied by governance and MUST NOT be read, written, or deleted:

- `~/.aws/**`
- `~/.ssh/**`
- `**/.env`
- `**/.env.local`
- `**/.env.*.local`
- `**/.env.production`
- `**/.env.staging`
- `**/.env.development`
- `**/.env.test`
- `.git/**`

When running Codex with `--full-auto`, these paths should be added to your
sandbox deny list. Use `exo sandbox-policy` for the machine-readable version.

## Governed Push

Before pushing code, ALWAYS run checks first:

```
exo push                      # runs exo check, then git push (recommended)
exo check && git push         # manual equivalent
```

## Non-Negotiables

- No governed execution without active session
- Respect lock ownership and ticket scope
- Verification is default at finish; break-glass must be explicit
- All configurable values must be loaded from their source of truth at runtime
- Read `.exo/LEARNINGS.md` for operational learnings from prior sessions

<!-- exo:governance:end -->

## Portfolio governance overlay

Read `governance/PORTFOLIO_AGENT_CONTRACT.md` before writing. It is committed
for cloud/mobile agents and contains the company's PR, approval, production,
and Founder-approval boundaries.
