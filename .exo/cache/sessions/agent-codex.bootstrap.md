╔══════════════════════════════════════════════════════╗
║  >>> EXO GOVERNED SESSION                            ║
║  protocol: ExoProtocol v1 | mode: work               ║
║  ticket: TKT-20260830-172428-ZZYG | actor: agent:codex║
║  model: gpt-5.6-sol                                  ║
║  branch: exo/TKT-20260830-172428-ZZYG                ║
╚══════════════════════════════════════════════════════╝

# Exo Agent Session Bootstrap

session_id: SES-20260920201133-E0A13805
actor: agent:codex
vendor: codex
model: gpt-5.6-sol
mode: work
context_window_tokens: unknown
ticket_id: TKT-20260830-172428-ZZYG
ticket_title: Implement undeployed-range queue and risk-based activation
ticket_status: todo
ticket_priority: 1
topic_id: repo:default
lock_owner: agent:codex
git_branch: exo/TKT-20260830-172428-ZZYG
lock_branch: exo/TKT-20260830-172428-ZZYG
lock_expires_at: 2026-09-20T22:11:33+08:00

## Scope
- allow: [".github/workflows/deploy.yml", "scripts/governance/autonomy_gate.py", "scripts/tests/test_deploy_activation_state.py", "docs/governance/RISK_BASED_MERGE_POLICY.md", "docs/OPERATIONS_RUNBOOK.md", "docs/governance/GOVERNANCE_CHANGELOG.md", ".agent-session/manifest.json", ".exo/**", ".exo/cache/**", ".exo/memory/**", ".exo/locks/**", ".exo/tickets/**", ".exo/logs/**"]
- deny: []

## Checks
- ["npm run test:unit", "npm run lint:no-undef", "npm run build"]

## Git Workflow
- Before pushing, rebase on base branch: `git pull --rebase origin main`
- Pull latest before starting work: `git pull --rebase`
- Keep commits atomic and branches short-lived

## Machine Context
- cpu_cores: 12
- load_avg_1m: 0.1
- ram: 6.8GB available / 7.8GB total

## Sibling Sessions (other agents working concurrently)
- human: ticket=TKT-20260901-045848-UJSJ on feat/TKT-20260901-045848-UJSJ (session=SES-20260901045942-87630ED6, age=471.2h)

## Start Advisories
- [WARNING] human working on TKT-20260901-045848-UJSJ on feat/TKT-20260901-045848-UJSJ — overlapping scope: docs/**, .agent-session/manifest.json, .exo/**, .exo/cache/**, .exo/memory/**, .exo/locks/**, .exo/tickets/**, .exo/logs/**
- [INFO] Unmerged work on branch exo/TKT-20260912-135926-9P4S (ticket=TKT-20260912-135926-9P4S, actor=human) — Integrated PR #2757 source head 458b6417 onto current main in a fresh governed s
- [INFO] Unmerged work on branch exo/TKT-20260907-095040-GNDS (ticket=TKT-20260907-095040-GNDS, actor=human) — Added a director-authenticated, read-only production classroom-management smoke 
- [INFO] Unmerged work on branch exo/INT-20260907-074745-682Y (ticket=INT-20260907-074745-682Y, actor=agent:codex) — Resumed the existing classroom UX delivery, reconciled the latest origin/main wi
- [INFO] Unmerged work on branch exo/INT-20260907-071544-9K2N (ticket=INT-20260907-071544-9K2N, actor=agent:codex) — Implemented mobile More navigation search, role-scoped filtering, empty-state re
- [INFO] Unmerged work on branch exo/INT-20260907-063735-M93H (ticket=INT-20260907-063735-M93H, actor=agent:codex) — Implemented role-authorized SPA page history with preserved notification deep-li

## Prior Session Memento
(none)

## Operational Learnings
The following patterns have been learned from prior sessions. Heed these to avoid repeating known mistakes.

- [MEDIUM]! 已取消的重複堂次仍殘留扣堂證據
  -> 重複課程審核不能只查 attended/completed；取消狀態若仍有 active attendance、learning record 或正扣堂 ledger，必須回到具名審核流程，清理證據並以 ledger 反向事件重算合約。
  (ref: REF-20260823-155425-E92X, scope: global)

- [MEDIUM]! 主任在課程管理看到未繳費課程仍需切換到帳務中心產生通知
  -> 既有 PaymentSlipModal 與唯讀通知 API 已可支援通知單產生；改善這類跨頁往返時，優先把既有唯讀元件掛到當下已驗證的課程脈絡，並沿用同一組付款狀態條件，避免重複帳務邏輯與狀態分歧。
  (ref: REF-20260827-121303-5KZH, scope: global)

- [MEDIUM]! 前端重構後未使用變數沒有防線
  -> 以既有檔案數 baseline 作 ratchet，build 只阻擋單檔新增 no-unused-vars；先控制新增債，再逐步接 Vue recommended 與清償歷史債。
  (ref: REF-20260827-123911-UJ2V, scope: global)

- [MEDIUM]! inline Markdown backticks in gh --body commands
  -> Use a body file or a subprocess argument array for GitHub comments; shell command substitution can corrupt evidence text even when no production action is intended.
  (ref: REF-20260829-210837-Q2BN, scope: global)

- [MEDIUM]! Vue template accessibility contract test failed before checking product behavior
  -> When adding a static contract for multiline Vue button tags, verify the regex against the actual source before interpreting a zero-match failure; run the targeted Vitest from frontend so Vite Vue transforms are loaded.
  (ref: REF-20260829-225008-U1RJ, scope: global)

- [MEDIUM]! Static Vue accessibility contracts misread opening tags when attribute expressions contain greater-than operators
  -> For Vue template button contracts, a regex that stops at the first greater-than character can truncate v-if expressions such as count > 0 and create false missing-type failures. Match complete button elements or use a Vue-aware parser, and verify the contract against the actual source before diagnosing product behavior.
  (ref: REF-20260829-230724-T7WR, scope: global)

- [MEDIUM]! UI simplification removed a disclosure but its E2E test still clicked the deleted selector
  -> When a workflow intentionally removes a progressive-disclosure block, update its E2E contract in the same change to assert the block is absent and no obsolete lazy-fetch occurs; do not restore removed UI just to satisfy stale tests.
  (ref: REF-20260830-050305-ODRN, scope: global)

- [MEDIUM]! bot auto-merge left no exact-main CI or deploy evidence
  -> A bounded scheduled reconciler can query exact-main CI/deploy runs and dispatch only the existing CI workflow when no active or downstream evidence exists; normal main request-file pushes then restore read-only evidence workflows, while non-deployable control-plane changes remain activation-gated and do not deploy.
  (ref: REF-20260831-081032-6RTU, scope: global)

- [MEDIUM]! SPA top-level navigation loses browser context
  -> For a Vue shell without a router, keep role-authorized page IDs in a namespaced query key and let popstate apply state without pushing; preserve existing workflow deep-link parameters only for their authorized target.
  (ref: REF-20260907-064813-6D03, scope: global)

- [MEDIUM]! Mobile navigation More sheet hides low-frequency destinations in a long list
  -> Reuse the role-scoped navigation registry and fixed-tab exclusion, but add search and empty-state recovery at the mobile More surface; keep desktop behavior, page IDs, and backend authorization unchanged.
  (ref: REF-20260907-072424-1OCG, scope: global)

(Showing top 10. Run `exo reflections` for the full list.)

## Tool Reuse Protocol

Before writing new utility functions, SEARCH the tool registry:
  exo tool-search "<keywords>"

After building a reusable utility, REGISTER it:
  exo tool-register <module> <function> --description "..."

### Registered Tools (5)
- `frontend.src.lib.parentAssessmentProgress.js:formatAssessmentProgressDate`: Format reviewed parent assessment dates for display.
- `frontend.src.lib.parentAssessmentProgress.js:assessmentProgressScoreLabel`: Format safe parent assessment score labels.
- `frontend.src.lib.parentAssessmentProgress.js:assessmentProgressPercentLabel`: Format safe parent assessment percentage labels.
- `frontend.src.lib.dashboardLoadPlan.js:runDashboardLoaders`: Run independent dashboard loaders concurrently with isolated failures
- `scripts.check-eslint-unused-baseline.mjs:main`: Run the frontend no-unused-vars per-file baseline ratchet and fail only on newly added debt

## Current Task
Bounded follow-up: converge stale production approval waits and bound deploy execution without weakening protected activation

## Lifecycle Commands
- heartbeat: EXO_ACTOR=agent:codex python3 -m exo.cli lease-heartbeat --ticket-id TKT-20260830-172428-ZZYG --owner agent:codex
- run worker once: EXO_ACTOR=agent:codex python3 -m exo.cli worker-poll --require-session --limit 50
- suspend: EXO_ACTOR=agent:codex python3 -m exo.cli session-suspend --reason "<why pausing>"
- finish: EXO_ACTOR=agent:codex python3 -m exo.cli session-finish --summary "<what changed>" --set-status review --ticket-id TKT-20260830-172428-ZZYG
