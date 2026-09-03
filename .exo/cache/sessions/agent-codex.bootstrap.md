╔══════════════════════════════════════════════════════╗
║  >>> EXO GOVERNED SESSION                            ║
║  protocol: ExoProtocol v1 | mode: work               ║
║  ticket: TKT-20260903-165120-IGVX | actor: agent:codex║
║  model: gpt-5                                        ║
║  branch: chore/task-transfer-contract-integrity-20260903║
╚══════════════════════════════════════════════════════╝

# Exo Agent Session Bootstrap

session_id: SES-20260903165129-5C08849E
actor: agent:codex
vendor: openai
model: gpt-5
mode: work
context_window_tokens: unknown
ticket_id: TKT-20260903-165120-IGVX
ticket_title: 堂次轉移契約容量與衝突診斷
ticket_status: todo
ticket_priority: 1
topic_id: repo:default
lock_owner: agent:codex
git_branch: chore/task-transfer-contract-integrity-20260903
lock_branch: codex/TKT-20260903-165120-IGVX
lock_expires_at: 2026-09-03T20:51:29+08:00

## Scope
- allow: ["backend/app/Http/Controllers/StudentClassController.php", "backend/app/Services/ClassSessionMaterializationService.php", "backend/app/Services/SessionEntitlementTransferService.php", "backend/app/Exceptions/SlotOccupiedException.php", "backend/tests/Feature/StudentClassTransferSessionsTest.php", "backend/tests/Feature/SessionEntitlementTransferTest.php", ".agent-session/**", ".exo/**", ".exo/cache/**", ".exo/memory/**", ".exo/locks/**", ".exo/tickets/**", ".exo/logs/**"]
- deny: ["backend/database/**", "frontend/**", "production/**"]

## Checks
- ["vendor/bin/phpunit", "bash scripts/phpunit-isolated.sh"]

## Git Workflow
- Before pushing, rebase on base branch: `git pull --rebase origin main`
- Pull latest before starting work: `git pull --rebase`
- Keep commits atomic and branches short-lived

## Machine Context
- cpu_cores: 12
- load_avg_1m: 0.2
- ram: 3.1GB available / 4.8GB total

## Sibling Sessions (other agents working concurrently)
- human: ticket=TKT-20260901-045848-UJSJ on feat/TKT-20260901-045848-UJSJ (session=SES-20260901045942-87630ED6, age=59.9h)

## Start Advisories
- [WARNING] human working on TKT-20260901-045848-UJSJ on feat/TKT-20260901-045848-UJSJ — overlapping scope: .agent-session/**, .exo/**, .exo/cache/**, .exo/memory/**, .exo/locks/**, .exo/tickets/**, .exo/logs/**
- [INFO] Unmerged work on branch chore/task-contract-session-date-overlap-20260903-final (ticket=TKT-20260903-155417-MUVF, actor=agent:codex) — Fixed student slot conflict queries to ignore ClassSession and schedule residue 
- [INFO] Unmerged work on branch chore/task-bug247-dump-refresh-20260831 (ticket=TKT-20260831-080838-ENZB, actor=human) — Refresh paired read-only evidence requests for in-app bug 247 after restoring ma
- [INFO] Unmerged work on branch chore/task-bug247-evidence-refresh-20260831 (ticket=TKT-20260831-073322-7VYG, actor=human) — Refreshed the paired read-only bug dump requests for in-app bug 247, corrected t
- [INFO] Unmerged work on branch chore/task-smart-calendar-room-form-a11y-20260831 (ticket=TKT-20260831-015607-L7IS, actor=human) — Added explicit accessible names to the SmartCalendar director room-manager name 
- [INFO] Unmerged work on branch chore/task-learning-record-selection-a11y-20260831 (ticket=TKT-20260831-013757-EJR0, actor=human) — Added contextual aria-labels to Learning Records director batch-selection contro
- [INFO] Unmerged work on branch chore/task-attendance-checkbox-a11y-20260831 (ticket=TKT-20260831-012049-ELLK, actor=human) — Named AttendancePage pending-session selection checkboxes with visible student, 
- [INFO] Unmerged work on branch chore/task-students-import-button-a11y-20260831 (ticket=TKT-20260831-010101-YH5H, actor=agent:codex) — Replaced the non-focusable StudentsList import label with a native labelled butt
- [INFO] Unmerged work on branch chore/task-subject-units-disclosure-a11y-20260831 (ticket=TKT-20260831-004116-P8Z9, actor=agent:codex) — Split Subject Units disclosure controls into separate native toggles; focused ac
- [INFO] Unmerged work on branch chore/task-learning-filters-clear-action-a11y-20260830 (ticket=TKT-20260830-204105-QKZI, actor=human) — Split Learning Records filter controls into independent native buttons; focused 
- [INFO] Unmerged work on branch chore/task-director-makeup-candidate-tabs-a11y-20260830 (ticket=TKT-20260830-202351-RQ41, actor=human) — Connected director parent-leave makeup candidate date tabs to their active label
- [INFO] Unmerged work on branch chore/task-learning-feedback-preview-a11y-20260830 (ticket=TKT-20260830-200621-56BV, actor=human) — Converted both Learning Records parent-feedback preview chips from non-focusable
- [INFO] Unmerged work on branch chore/task-calendar-teacher-list-a11y-20260830 (ticket=TKT-20260830-193601-RIQ2, actor=agent:codex) — Clarified SmartCalendar view tabs and panels, calendar filter/date semantics, an
- [INFO] Unmerged work on branch chore/task-attendance-controls-a11y-20260830 (ticket=TKT-20260830-064903-SYI5, actor=human) — Added explicit accessible names to attendance teacher date, attendance record da
- [INFO] Unmerged work on branch chore/task-class-session-response-envelope-20260830 (ticket=TKT-20260830-062342-OROW, actor=agent:codex) — Added class-sessions response envelope, pagination, aliases, stable row keys/typ
- [INFO] Unmerged work on branch chore/task-calendar-247-regression-20260830 (ticket=TKT-20260830-061056-4LIH, actor=agent:codex) — Added exact #247 production-payload regression coverage for mixed-capacity subst
- [INFO] Unmerged work on branch chore/task-teacher-overdue-partial-failure-20260830 (ticket=TKT-20260830-055433-A4MH, actor=agent:codex) — Fixed TeacherHome partial-failure classification: attendance and weekly projecti

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
修復堂次轉移的幽靈衝突、目標課程空值、預排超額與堂數契約計算；補回歸測試並準備 CI/merge/deploy/production read-only verify。

## Lifecycle Commands
- heartbeat: EXO_ACTOR=agent:codex python3 -m exo.cli lease-heartbeat --ticket-id TKT-20260903-165120-IGVX --owner agent:codex
- run worker once: EXO_ACTOR=agent:codex python3 -m exo.cli worker-poll --require-session --limit 50
- suspend: EXO_ACTOR=agent:codex python3 -m exo.cli session-suspend --reason "<why pausing>"
- finish: EXO_ACTOR=agent:codex python3 -m exo.cli session-finish --summary "<what changed>" --set-status review --ticket-id TKT-20260903-165120-IGVX
