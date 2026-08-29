# AllTrue Codex adapter

Load [`governance/PORTFOLIO_AGENT_CONTRACT.md`](governance/PORTFOLIO_AGENT_CONTRACT.md)
before acting. It is the portable minimum for this repository; this adapter
must not duplicate or override it. If the overlay or required governance
context is unavailable, stop and report the missing context.

<!-- exo:governance:begin -->
<!-- Governance hash: ee4a26a1635176e5 -->
# ExoProtocol — Codex Operating Instructions

This repository is governed by ExoProtocol. All AI agent work must follow the session lifecycle.

## ExoProtocol Governance

- kernel: exo-kernel 0.1.0
- lock hash: `ee4a26a1635176e5...`
- generated: 2026-08-29T11:53:59+08:00

### Filesystem Deny Rules

- **RULE-SEC-001**: deny read, write on `~/.aws/**`, `~/.ssh/**`, `**/.env`, `**/.env.local`, `**/.env.*.local`, `**/.env.production`, `**/.env.staging`, `**/.env.development`, `**/.env.test`
- **RULE-GIT-001**: deny read, write, delete on `.git/**`

### Structural Rules

- **RULE-LOCK-001** (require_lock): Blocked by RULE-LOCK-001 (acquire a ticket lock first).
- **RULE-CHECK-001** (require_checks): Blocked by RULE-CHECK-001 (checks must pass before done).
- **RULE-EVO-001** (evolution_gate): T0/T1 autonomous after checks; T2 requires independent review; T3 stops at protected boundaries.
- **RULE-EVO-002** (patch_first): Patch-first evolution required.

### Default Budgets

- max files changed: 12

### Approved Checks

- `npm run test:unit`
- `npm run lint:no-undef`
- `npm run build`
- `vendor/bin/phpunit`

### Active Intents

- **INT-20260826-113539-5KZM**: Improve in-app bug report image attachment UX — boundary: *Do not change bug lifecycle permissions, status transitions, storage authorization, or unrelated chat behavior; do not alter production data.*
  - TKT-20260826-113654-Q9VF: Implement direct image paste and drop in bug reports [allow: frontend/src/components/BugReportLauncher.vue, frontend/src/components/__tests__/BugReportLauncher.test.js, frontend/src/lib/bugReportsApi.js, frontend/src/lib/bugReportAttachments.js, docs/**, .agent-session/**, .exo/**, .exo/cache/**, .exo/memory/**, .exo/locks/**, .exo/tickets/**, .exo/logs/**]
- **INT-20260826-120619-L693**: 主任繳費通知與明細直達 — boundary: *不修改 AlertController、PaymentReport 狀態機、帳務金額計算、production DB、排課與出缺勤資料；不新增第二套帳務 API。*
  - TKT-20260826-120625-JOON: 繳費通知與繳費明細入口 UI [allow: frontend/src/pages/DirectorDashboard.vue, frontend/src/pages/TuitionCollectionPage.vue, frontend/src/components/__tests__/**, docs/**, .agent-session/**, .exo/**, .exo/cache/**, .exo/memory/**, .exo/locks/**, .exo/tickets/**, .exo/logs/**]
- **INT-20260827-171548-YSLP**: 已繳費課程無法結算修復 — boundary: *只修改 AllTrue_System 的課程/帳務結算相關程式、測試與必要治理文件；禁止 production SSH、artisan、直接 SQL、秘密或個資輸出；保留已繳費與已上課歷史，不改付款語義。*
  - TKT-20260827-200034-BPF2: 修復吳艾潼堂次轉移扣堂台帳不一致 [allow: backend/app/Http/Controllers/StudentClassController.php, backend/app/Console/Commands/RepairTransferredSessionLedger.php, backend/tests/Feature/StudentClassTransferSessionsTest.php, .github/workflows/ops-reconcile-transferred-session-ledger.yml, docs/CHANGELOG.md, docs/AI_REGRESSION_LESSONS.md, docs/STAFF_UPDATES.yml, frontend/src/lib/changelogDraft.generated.js, frontend/src/lib/staffUpdates.generated.js, .exo/cache/**, .exo/memory/**, .exo/locks/**, .exo/tickets/**, .exo/logs/**; deny: backend/database/**]
- **INT-20260827-202158-4H5K**: 取消或請假課堂不應保留評量表 — boundary: *只調整取消／請假課堂的評量表一致性、既有指定異常資料修復與夜間堂數對帳零差異顯示；不改付款金額、既有有效出席歷史、帳務狀態、權限或行事曆的權威資料。*
  - TKT-20260827-202205-1MZT: 修正取消評量殘留與零差異對帳顯示 [allow: backend/app/**, backend/tests/**, frontend/src/**, docs/**, .github/workflows/**, .exo/cache/**, .exo/memory/**, .exo/locks/**, .exo/tickets/**, .exo/logs/**]
- **INT-20260828-190711-0W96**: Director daily progress UX — boundary: *只做 AllTrue director dashboard 的 bounded UI/progress cue 與 evidence；不改 API、auth、billing、schedule、attendance semantics、production data 或 deploy。*
  - TKT-20260828-190801-8TY7: Deliver director daily progress UX [allow: frontend/src/pages/DirectorDashboard.vue, frontend/src/components/TodayProgressCard.vue, frontend/src/components/__tests__/TodayProgressCard.test.js, frontend/src/lib/dailyWorkProgress.js, frontend/src/lib/dailyWorkProgress.test.js, docs/design/evidence/daily-progress-ux-2026-08-28.md, .exo/cache/**, .exo/memory/**, .exo/locks/**, .exo/tickets/**, .exo/logs/**; deny: **/.env*, backend/**, supabase/**]
- **INT-20260828-193940-H0FQ**: Student course page clarity UX — boundary: *Only frontend presentation and tests/docs. Do not change API endpoints, payloads, auth/permissions, branch isolation, billing/payment semantics, attendance, scheduling, course mutations, database, or production data. Preserve every existing action handler and route.*
  - TKT-20260828-193947-BEY7: Implement student course summary slice [allow: frontend/src/pages/StudentsList.vue, frontend/src/components/__tests__/StudentCourse*, docs/research/**, docs/design/evidence/**, docs/CHANGELOG.md, docs/STAFF_UPDATES.yml, frontend/src/lib/changelogDraft.generated.js, frontend/src/lib/staffUpdates.generated.js, frontend/e2e/smoke.spec.js, .exo/cache/**, .exo/memory/**, .exo/locks/**, .exo/tickets/**, .exo/logs/**]
- **INT-20260828-233910-QOQA**: Teacher daily queue clarity — boundary: *Only frontend TeacherHome presentation, tests, and required staff-facing documentation. Do not change API payloads, authorization, branch isolation, attendance, leave, learning-record semantics, scheduling, billing, database, or production data.*
  - TKT-20260828-233917-4VHZ: Implement teacher queue priority explanation [allow: frontend/src/pages/TeacherHomePage.vue, frontend/e2e/teacher-daily-workflow.spec.js, docs/CHANGELOG.md, docs/STAFF_UPDATES.yml, .exo/cache/**, .exo/memory/**, .exo/locks/**, .exo/tickets/**, .exo/logs/**]
- **INT-20260829-001131-D2UV**: AllTrue visual companion dashboard slice — boundary: *只改 TeacherHome 視覺呈現與前端 asset/e2e evidence；禁止 API、auth、billing、attendance、leave、learning-record、scheduling、DB、production data。*
  - TKT-20260829-001138-KK5R: Implement original learning companion hero [allow: frontend/src/pages/TeacherHomePage.vue, frontend/src/assets/alltrue-learning-companion.png, frontend/e2e/teacher-daily-workflow.spec.js, .exo/cache/**, .exo/memory/**, .exo/locks/**, .exo/tickets/**, .exo/logs/**]
- **INT-20260829-105203-Y3JI**: Converge AllTrue autonomy policy — boundary: *Only modify governance/PORTFOLIO_AGENT_CONTRACT.md, codex.md, AGENTS.md, CLAUDE.md, docs/governance/RISK_BASED_MERGE_POLICY.md, docs/governance/COMPANY_CONSTITUTION.md, docs/sop/MERGE_SOP.md, docs/governance/GOVERNANCE_CHANGELOG.md, .github/pull_request_template.md, .agent-session/manifest.json, and .exo/policy.sealed.json. Do not modify application code, CI workflows, production, credentials, or issue/PR state.*

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
