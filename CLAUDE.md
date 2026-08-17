# AllTrue — CLAUDE.md（Claude Code 自動載入）

> 任何 AI 讀取此專案時，先遵守 **[`docs/governance/COMPANY_CONSTITUTION.md`](docs/governance/COMPANY_CONSTITUTION.md)** 與 **[`docs/governance/PRECEDENCE.md`](docs/governance/PRECEDENCE.md)**。  
> **操作者：** 艦隊 [portfolio-ops `AUTONOMY_POLICY`](https://github.com/jerry200176-png/portfolio-ops/blob/main/governance/AUTONOMY_POLICY.md)。Required checks 綠 → Agent squash-merge R0–R3。不要等人類橡皮圖章。Pi SSH 仍禁。  
> 本檔是 **Claude Code adapter**，**不是**凌駕 Constitution / Control Plane 的最高法。  
> **🗺️ 任何任務開始前：先讀 `docs/INDEX.md`（導航地圖）。禁止未讀 INDEX 就直接動手。**  
> **現行工程主線：** [`docs/architecture/ALLTRUE_ENGINEERING_NORTH_STAR.md`](docs/architecture/ALLTRUE_ENGINEERING_NORTH_STAR.md) — 不要重寫整個前端／後端；排課資料模型根治見 [`RFC_SCHEDULE_OCCURRENCE_IDENTITY.md`](docs/architecture/RFC_SCHEDULE_OCCURRENCE_IDENTITY.md)。  
> 通用入口：[`AGENTS.md`](AGENTS.md)。完整工作流程 / 角色規格 / P0 詳細全文：請讀 **`.cursorrules`**（不要跳過）。

---

## 🧠 MemPalace — AI 記憶系統

**更新索引（唯一入口）：**
```bash
bash scripts/mempalace-ingest.sh
```

**搜尋（調查 bug 或回顧決策前）：**
```bash
~/.local/bin/mempalace search "關鍵字" --wing alltrue-sessions
~/.local/bin/mempalace search "關鍵字" --wing alltrue-docs
```

Palace：`~/.mempalace/palace`（local-first）。權威文件仍在 git markdown；MemPalace 僅供召回。

---

## In-app Bug 回報（Claude／Cursor 必讀）

處理「系統上 bug 回報」時，唯一長文 SOP：[`docs/CHAT_BUG_SYSTEM.md`](docs/CHAT_BUG_SYSTEM.md) **§3.6–§3.7**。

| 階段 | 要做什麼 |
|------|----------|
| 分診 | §3.6 撈附件 → 開 GitHub issue → in-app `triaged` + **公開回覆** |
| 修復 | `bug-fix-plan.mdc` → branch → CI → PR merge |
| 上線後 | in-app `resolved` + 公開回覆 → `reporter-verify` → `closed` |

**禁止**只關 GitHub issue 而不回 App 留言（§R51、§R53）。

---

## ⛔ 5 條紅線（違反 = P0 故障，零容忍）

| # | 觸發情境 | 強制行動 |
|---|---------|---------|
| R1 | 要修改 `/home/admin/` 內**既有** `.php` / `.vue` / config 檔 | ❌ 停。先寫測試 → CI 綠 → 才改。新增 migration / test / Export class 例外 |
| R2 | 要在 Pi 執行任何含 `test` / `phpunit` / `config:clear` 的指令 | ❌ 停。測試只走 GitHub Actions |
| R3 | 要執行 `git push --force` / `-f` / 直接 push main | ❌ 停。一律推 feature branch，等 PR merge |
| R4 | 要還原出錯的檔案 | ✅ `git checkout HEAD -- <file>` **完整**還原，禁止部分還原 |
| R5 | 要執行 `php artisan migrate` | ✅ PR merge 後才可 `migrate --force` |
| R6 | 要 SSH 到 Pi 直接編輯任何程式碼 | ❌ 停。所有改動走 WSL2 → feature branch → PR → CI → auto-deploy |

## ⚠️ 3 條黃線（違反 = CI 反覆失敗）

| # | 觸發情境 | 強制行動 |
|---|---------|---------|
| Y1 | 要在測試插入任何 DB 資料 | 先查 NOT NULL 欄位。`Campus` 用 Factory。`schedules` 記 **S.D.B.**（student_id, day_of_week, branch_id）|
| Y2 | 要在測試用「今日日期」作為 future session | `start_time` 設 `23:00`，避免 `isEndedAtCreateTime=true` |
| Y3 | 前端有改動要上線 | CI 全綠 → PR merge → 等 `deploy.yml` → 驗 health / `version.json` |

---

## ⛔⛔⛔ 生產事故紀錄（全部真實發生）

| 事故 | 日期 | 操作 | 後果 |
|---|---|---|---|
| **A** | 2026-04-21 | `git push --force origin main` | 生產 `.env`/routes 被覆蓋，全站 15 分鐘 |
| **B** | 2026-04-22 | Pi 執行 `php artisan config:clear` | session/auth 錯亂，全站 5 分鐘 401 |
| **C ⛔最高** | 2026-04-22 | Pi 跑 `php artisan test` | `RefreshDatabase` 清空 production DB，1h42m 資料損失 |
| **D** | 2026-04-23 | 未經 CI 改 `public/.htaccess` + 部分還原 | 全站變英文，再次破壞 |
| **E** | 2026-04-23 | production 跑 `vendor/bin/phpunit` | 污染 cache owner，全站 API 500，20 分鐘 |
| **F** | 2026-04-23 | 無測試直接改 production `SwipeRfidController.php` | 流程違規 |

---

## 開發環境（2026-04-24 起）

| 環境 | 說明 |
|---|---|
| **本地開發** | WSL2 task worktree — **never** `/home/jerry/alltrue` / `~/alltrue` if it resolves there. Canonical policy: [`docs/governance/WORKTREE_POLICY.md`](docs/governance/WORKTREE_POLICY.md) |
| **多 agent 並行** | ⛔ 禁止共用 forbidden dirty tree。用 `git worktree add /home/jerry/alltrue-<task> origin/main -b <type>/<slug>` + `make agent-preflight`。見 WORKTREE_POLICY + `AI_REGRESSION_LESSONS` §Y6 |
| **生產伺服器** | Raspberry Pi `/home/admin` — ⛔ 禁止直接 SSH 進去改程式碼 |
| **部署方式** | WSL2 push → GitHub CI 通過 → `deploy.yml` 自動 SSH 部署到 Pi |

---

## 核心資料表 Gotchas（bug 偵查前必讀）

### G-001：Teacher.id === User.id（同一人，兩張表 ID 相同）
`StudentClass.TeacherID`、`StudentSingIn.TeacherID` 存的都是 `User.id`。查 `Teacher` 或 `User` 用同一個 ID 都能命中。

### G-006：GitHub Actions SSH Secrets 格式嚴格，含 `@` 就爆
`PI_SSH_USER` 只能填 `admin`；`PI_SSH_HOST` 只能填 `pi.lifenet.com.tw`。詳見 `docs/OPERATIONS_RUNBOOK.md` §Pi + AI_REGRESSION_LESSONS §R18。

### G-007：智慧行事曆週檢視必須走 occurrence resolver，不可分散 if 合併
**唯一合法路徑**：`SmartCalendar.vue` → `calendarOccurrenceMerge.js` `mergeWeekCalendarOccurrences()`。
違反會導致課程消失或同一堂掛兩位老師。回歸測試：`npm run test:calendar`。
混班型容量（一對二與一對三同一格）見 [`docs/plans/2026-08-17-mixed-class-type-occupancy.md`](docs/plans/2026-08-17-mixed-class-type-occupancy.md)（#1889）；剩餘依即將加入的班型算。二次調課殘影見 §R114，不可回退。

### G-008：家長入口更新卡只吃 `docs/PARENT_UPDATES.yml`（禁止 CHANGELOG 關鍵字推導）
教職員卡唯一來源 `docs/STAFF_UPDATES.yml`（§R85）；家長卡唯一來源 `docs/PARENT_UPDATES.yml`（§R45）。CHANGELOG 只產草稿、不得自動發布。改 YAML 後跑 `npm run sync-release-notes` 並提交 generated 檔。

### G-009：課程「繳費狀態」是雙真相 OR 邏輯，`StudentClass.Paid` 壓不過帳單付款
`payment_status = Paid=1 或 Invoice 有效付款`（`StudentClassController.php` summary 段）。只要帳單有未作廢的 Payment，課程管理切「未繳費」會被靜默蓋回「已繳費」；要先到帳務作廢誤登款項。另：`update()` 的 preservedDelta 會把 `Charge − Rate×數量` 的差額當手動微調永久保留——若差額來自錯誤舊資料，UI 怎麼改都改不回（GitHub #798/#799，in-app #158/#159）。

### G-010：行事曆來源真相 = 已物化的 `ClassSession`；「某天課很少」未必是 bug
行事曆/點名/評量的來源真相是**已物化的 `ClassSession`**（非 `schedules` 模板），經 `class-sessions` API（`branch_id`+`start`/`end`）→ 前端 `mergeWeekCalendarOccurrences()`。**判定「課表漏顯/數量不對」前，先查 DB**：`ClassSession JOIN StudentClass JOIN Student.CampusID` by `SessionDate`，DB 數量 = API 數量 = 真相。週日/低量日只有少數堂是**正常**（補習班週日課少），主任週檢視是依時段聚合成「堂」（例：2026-06-28 新莊分校 = 3 時段 / 12 筆 student-session，皆正確，非資料遺失）。
**但 count 模式（預付包堂）課程目前無「向前產生 session」的排程 job**——`Kernel.php` 只跑 reconcile/close-orphans，`schedules:backfill-class-sessions` 只能從既有 `schedules` 物化、無法向前生成。導致已付堂數未物化、行事曆向前看不到 = 系統性 P0（**#1062**，全分校約 2,000 堂預付堂卡住）。每日 03:40 已排 `sessions:audit-stranded` 稽核；根因修復（向前生成 + 永不遺失已付餘額）屬 owner-gated 紅區。產品／架構決策包（**Accepted — Phase 0 evidence authorized**，非 implemented）：[`docs/ADR_006_prepaid_session_horizon_and_commitment.md`](docs/ADR_006_prepaid_session_horizon_and_commitment.md)——Schedule Commitment → materialize → pool coverage；禁止餘額猜堂。

### G-011：「一堂 = 幾分鐘」是**每門課自己的**設定，沒有全公司統一值；契約在第一次扣堂後鎖死
`StudentClass.standard_lesson_minutes`（計費標準堂長）與 `SessionDuration`（排課時長）是**兩件事**。同樣一次 180 分鐘的課，在標準 90 / 120 / 180 分鐘的課程裡分別扣 **2.00 / 1.50 / 1.00** 堂；買 8 堂分別 = 720 / 960 / 1440 分鐘。
**後端沒有任何 fallback 到 120**：`resolvedStandardLessonMinutes()` 未設定時回傳 `null`，不會退回 `SessionDuration` 也不會退回 120；建課表單的 `120` 只是輸入框初值，一定會隨 payload 明確送出。任何「AllTrue 一堂固定 120 分鐘」「8 堂 = 16 小時」「3 小時課永遠扣 1.5 堂」的假設都是 bug。
**兩個旗標缺一不可且皆預設關**：`perfflags.actual_duration_deduction_enabled`（環境）＋ `deduction_basis`（每門課）。旗標關閉時，已標記 `actual_duration` 的課行為**完全等同** `fixed_session`（fail-safe，非 fail-open），因此關旗標＝完整回滾、無資料要清。
**第一筆扣堂 ledger 之後**，`standard_lesson_minutes`／`deduction_basis`／`SessionCount` 由 `BillingContractLockGuard` 在**後端**鎖定（回 422）；前端變灰只是 UX。v1 **不提供**任何扣堂後修正管道——額度仍由 `SessionCount × standard_lesson_minutes` 推導，事後改標準堂長等於重新解釋歷史，宣稱「只影響未來」是假保證。要改就結掉重開。
**超額永遠不擋點名**：確認只發生在建課階段（D5）。若出現「因額度不足而無法點名」＝ bug，先關旗標。上線／回滾見 [`docs/RUNBOOK_ACTUAL_DURATION_ACTIVATION.md`](docs/RUNBOOK_ACTUAL_DURATION_ACTIVATION.md)。

### G-012：雲端／遠端 session（無 SSH、無 DB 連線）撈／回寫 in-app bug 資料——讀走 push request file，寫要交給人類
Claude Code on the web／其他雲端 session 的 container 是全新隔離環境，**不會**掛載本機 WSL2 的 Pi SSH key 或 DB 連線；`~/.ssh`、`PI_SSH_*` 環境變數皆為空，且 `gh workflow run` / `workflow_dispatch` 對雲端 agent 一律回 403（GitHub App 權限限制）。
**撈資料（唯讀）**：編輯 `operations/closeout/bug-queue-dump.request.md`（撈開放佇列，無參數）與 `operations/closeout/bug-detail-dump.request.md`（**要改檔內 `bug_id: <n>` 那一行**，workflow 真的會解析這行，不是只看 diff 有沒有變動）— 走 **`chore/`（禁止 `ops/`，`branch-policy.mjs` 只認 `chore|ci|fix`）** branch → PR → CI 綠 → merge 進 `main`（`push: branches:[main]` 才會觸發）→ 抓 run。
**artifact zip 抓不到**：`blob.core.windows.net` 下載連結會被這個 session 的 egress 政策擋 403（`curl $HTTPS_PROXY/__agentproxy/status` 可確認），這是真的政策拒絕、不要重試繞過。改用 `mcp__github__get_job_logs`（`return_content:true`，`tail_lines` 建議 2000+）——每個 dump 步驟都會把完整 JSON `echo` 到 log 裡，抓那行就等於拿到 artifact 內容。
**兩者有 15 分鐘新鮮度限制**（`scripts/validate-bug-intake-evidence.py --max-age-minutes 15`）：queue-dump 與 detail-dump 必須在同一輪 15 分鐘內連續觸發並互相對得上，否則視為 stale、禁止拿舊 dump 直接分診。
**回寫（new→triaged + 公開留言）沒有雲端路徑**：`bug-phase-a-triage.yml`／`bug-followup-comment.yml` 只有 `workflow_dispatch`、**沒有** push fallback（這是刻意的——會寫production，不像唯讀 dump 給雲端 agent 開後門）。雲端 session 能做到開 GitHub issue 為止；`bug_id` + `github_issue_url` + 符合 §3.8 白話規則的 `public_reply`（必須含 issue URL）要交給有 `workflow_dispatch` 權限的人類手動跑。`bug-phase-c-allowlist.yml` 雖有 push trigger，但限定「修復已上線」的 Phase C，不可拿來做未驗證的 Phase A。
完整程序見 [`docs/sop/BUG_INTAKE_TO_PRODUCTION.md`](docs/sop/BUG_INTAKE_TO_PRODUCTION.md)。

完整 Gotchas G-001 ~ G-012：見 `.cursorrules` §核心資料表 gotcha 或 `alltrue-system.mdc`。

---

## 任務完成後的記錄原則

| 發現了什麼 | 記在哪裡 |
|---|---|
| 非直覺的 DB / 流程行為 | `CLAUDE.md` §Gotchas（格式：`G-NNN: 一句話 + 後果`）|
| AI 犯的錯誤（行為/流程） | `docs/AI_REGRESSION_LESSONS.md` |
| 新功能 / bug 修復上線 | `docs/CHANGELOG.md`（一行原則）|
| 技術債發現 | `docs/TECH_DEBT.md`（TD-NNN 表格）|
| 複雜系統流程 / 架構決策 | `docs/SYSTEM_TECH_GUIDE.md` |

---

## On-demand 快查（按需讀，不用全讀）

| 需要什麼 | 去哪讀 |
|---|---|
| **SOP 對標大廠 / AI 接手地圖（換手前先讀「進行中狀態」）** | `docs/SOP_MATURITY.md` |
| 完整工作流程 + 角色規格 + SOP | `.cursorrules` |
| P0 紅線速查 + OPS checklist | `.cursor/rules/p0-gate.mdc` |
| API 路由 / DB schema / Gotchas | `.cursorrules` 或 `alltrue-system.mdc` |
| 測試規則（NOT NULL / Factory / 時間敏感）| `.cursor/rules/module-test.mdc` |
| Migration 規則（chunkById）| `.cursor/rules/module-migration.mdc` |
| 前端 deploy SOP | `.cursor/rules/auto-frontend-deploy.mdc` |
| 各模組已知坑 | `docs/AI_REGRESSION_LESSONS.md` |
| 繳費/續課提醒規則 | `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` |
| 已回報 vs 確認入帳（#1827） | `docs/architecture/RFC_REPORTED_PAID_ACCOUNTING_SPLIT.md` |
| 各角色測試帳號 | `.cursor/.local/test-credentials.md` |

<!-- exo:governance:begin -->
<!-- Governance hash: f45f0f00b0698aa4 -->
# ExoProtocol — Governed Repository

This repository uses ExoProtocol governance. All work must go through the session lifecycle.

## ExoProtocol Governance

- kernel: exo-kernel 0.1.0
- lock hash: `f45f0f00b0698aa4...`
- generated: 2026-08-15T14:50:36+08:00

### Filesystem Deny Rules

- **RULE-SEC-001**: deny read, write on `~/.aws/**`, `~/.ssh/**`, `**/.env*`
- **RULE-GIT-001**: deny read, write, delete on `.git/**`

### Structural Rules

- **RULE-LOCK-001** (require_lock): Blocked by RULE-LOCK-001 (acquire a ticket lock first).
- **RULE-CHECK-001** (require_checks): Blocked by RULE-CHECK-001 (checks must pass before done).
- **RULE-EVO-001** (evolution_gate): Practice is mutable, governance requires explicit human approval.
- **RULE-EVO-002** (patch_first): Patch-first evolution required.

### Default Budgets

- max files changed: 12

### Approved Checks

- `npm test`
- `npm run lint`
- `pytest`
- `python -m pytest`
- `python3 -m pytest`

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


## Session Lifecycle

Before starting any work:

1. **Start session**: `EXO_ACTOR=agent:claude python3 -m exo.cli session-start --ticket-id <TICKET> --vendor anthropic --model <MODEL> --task "<TASK>"`
2. **Read bootstrap**: Open `.exo/cache/sessions/agent-claude.bootstrap.md` and follow its directives
3. **Execute work** within ticket scope
4. **Finish session**: `EXO_ACTOR=agent:claude python3 -m exo.cli session-finish --ticket-id <TICKET> --summary "<SUMMARY>" --set-status review`

## Governed Push

Before pushing code, ALWAYS run checks first:

```
exo push                      # runs exo check, then git push (recommended)
# OR
exo check && git push         # manual equivalent
```

Do NOT use bare `git push` — it bypasses governance checks.
If checks fail, fix the issues before pushing.

## Non-Negotiables

- Do NOT start work without an active session (`session-start`)
- Do NOT close without a session finish (`session-finish`)
- Respect ticket scope: only modify files allowed by the ticket's `scope.allow` / `scope.deny`
- If checks fail at finish, fix them — do not use `--skip-check` without `--break-glass-reason`
- The bootstrap file is your source of truth for the current session
- Never hardcode values that belong in config — load from manifest at runtime, write tests that vary the config
- Read `.exo/LEARNINGS.md` for operational learnings from prior sessions

<!-- exo:governance:end -->

## Portfolio governance overlay

Read `governance/PORTFOLIO_AGENT_CONTRACT.md` before writing. It is committed
for cloud/mobile agents and contains the company's PR, approval, production,
and Founder-approval boundaries.
