# AGENTS.md — AllTrue AI Agent 入口

> **Cursor 使用者**：`.cursorrules` 已自動載入，這裡補充必讀順序與 Commit SOP。
>
> **AllTrue AI 公司 slogan：前人種樹，後人乘涼。**
> 所有 Agent 做事前先查 `docs/INDEX.md` 與 MemPalace；做完把決策寫回文件，讓下一個 AI 不靠猜測。

## Worktree & path safety (canonical)

**Launch:** `agent-start alltrue <task-id>` (only official Agent entry).
**Bare store:** `/home/jerry/workspace/repos/AllTrue_System.git`
**Tasks:** `/home/jerry/workspace/tasks/alltrue/<task-id>/`
**Policy:** [`docs/governance/WORKTREE_POLICY.md`](docs/governance/WORKTREE_POLICY.md)
**Provenance:** commit `.agent-session/manifest.json` (or human-authored.json).


## 開工前 First-read 順序

**SOP（防重踩同坑）**：收到任務後**先讀文檔再打程式**，禁止只靠對話上下文硬改。

**操作者權限（適用於每一次任務，不是要讀的檔案，是規則本身）**：操作者是 **Agent**。T0/T1 在 required checks、風險適當 review 與證據通過後可自行 merge／關 issue；T2 另需 independent review、rollback boundary 與完整 CI。T3 / protected 工作可研究、實作、測試並準備 evidence package，但在 production activation、production data repair、migration/schema cutover、billing／entitlement、identity/authz、破壞性動作、backup restore、security-sensitive credential 或重大產品方向前停下等 Founder GO。機器禁令：Pi SSH / artisan / phpunit、印 secrets、force-push、`--admin`、Gmail 刪信。產品 P0 與 Control Plane I1 仍有效。Capabilities 詳見 `docs/governance/AGENT_CAPABILITY_REGISTRY.md`，勿假設權限。

### 必讀（3 份，每次任務都讀）

1. **`docs/governance/COMPANY_CONSTITUTION.md`** + **`docs/sop/AGENT_PREFLIGHT.md`**（公司根政策）
2. **`docs/architecture/ALLTRUE_ENGINEERING_NORTH_STAR.md`**（現行工程主線；禁止整包重寫）
3. **`docs/INDEX.md`**（導航地圖，決定接下來只讀哪些章節 — 省 token 關鍵）

（`.cursorrules` 自動載入，已讀，不用手動加進清單。）

### 條件分支（依任務類型挑對應章節，不用全讀）

| 任務類型 | 加讀 |
|---|---|
| 改排課／行事曆／扣堂 | `docs/architecture/RFC_SCHEDULE_OCCURRENCE_IDENTITY.md`（TD-076）+ `AI_REGRESSION_LESSONS.md` 模組索引表對應的 R10x 系列。改容量徽章／代課已滿：先讀 [`docs/plans/2026-08-17-mixed-class-type-occupancy.md`](docs/plans/2026-08-17-mixed-class-type-occupancy.md) 與 **§R116**（#1889）；禁止用較嚴班型上限蓋掉一對三空位；§R114 殘影過濾不可回退 |
| 改計費／繳費提醒／核帳登記／電子收據時機 | `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` + [`docs/architecture/RFC_REPORTED_PAID_ACCOUNTING_SPLIT.md`](docs/architecture/RFC_REPORTED_PAID_ACCOUNTING_SPLIT.md)（#1827）。禁止把行政登錄再次做成一次 `Paid=1`＋核銷＋開收據。 |
| 處理 in-app Bug（分診或修完上線） | `docs/CHAT_BUG_SYSTEM.md` **§3.6–§3.7** + `AI_REGRESSION_LESSONS.md` **§R51、§R53**（開 issue 與 merge 後都要回系統留言，勿只動 GitHub）|
| 高風險模組（代課／評量／智慧行事曆合併等）任何改動 | `docs/AI_REGRESSION_LESSONS.md` 文末模組索引表對應 § — 動 `backend/`／`frontend/src/` 前必讀 |
| 需要回顧決策或舊 bug | `~/.local/bin/mempalace search "<關鍵字>"`（本機搜尋；非跨機器權威）|
| 任何瀏覽器測試 | `.cursor/.local/test-credentials.md` |
| 任務牽涉長文件／多份 docs | `docs/INDEX.md` 的速讀卡／治理節奏（`docs/AI_DOC_LITERACY.md` 只是索引 stub）|

## 公司治理記錄原則

- 新功能 / bug fix 上線：更新 `docs/CHANGELOG.md`，並依 [`docs/GUIDE_STAFF_UPDATES.md`](docs/GUIDE_STAFF_UPDATES.md) 加上 `staff_update` 或 `silent_ship` 決策標記；教職員可感知的變更必須同步 `docs/STAFF_UPDATES.yml`。
- AI 犯錯或發現防再犯規則：更新 `docs/AI_REGRESSION_LESSONS.md`。
- 本次不修但會影響未來維護：更新 `docs/TECH_DEBT.md`。
- 複雜架構、資料流或 SOP：更新 `docs/SYSTEM_TECH_GUIDE.md` 或 `docs/OPERATIONS_RUNBOOK.md`。
- 文件不要複製長 SOP；入口文件只導航，單一主題只保留一個權威出處。

## Agent Orchestration SOP

開工前先判斷任務類型，避免把 AI 協作變成額外認知負擔：

| 類型 | 定義 | 處理方式 |
|---|---|---|
| Fire-and-forget | 錯字、footer 日期、單一連結、小型 lint/docs 修正 | 累積到 docs batch；不要單獨開 PR 浪費 Actions |
| Context-dependent | API 串接、前後端同改、README/Runbook 同步 | 先產 artifact（API contract、diff、測試結果），下游只讀 artifact |
| Decision-requiring | DB schema、auth、堂數/繳費、CI/CD、備份/還原 | 必須進 PLAN/ARCH 或 BUG B1，等使用者批准後才 DEV |

強制原則：
- 以 bounded context 切任務，不以 migration/model/controller/frontend/test 這種技術層硬切碎。
- 規劃/ARCH/BUG B1 不可只靠模型記憶：研究順序為 `本專案 Docs/MemPalace` → `大公司/業界做法` → `相關開源專案實作`，最後才收斂為 AllTrue 的取捨。
- Agent handoff 只交 output artifact，不要求下游讀完整推理過程。
- PRD/ARCH 至少寫到 architecture boundary：API 合約、資料邊界、權限、錯誤處理、多校區隔離。
- 沒有 architecture boundary 的需求，不進 DEV；讓 agent 猜架構會把 decision load 丟給錯的人。
- 多 agent 或多 PR 任務需指定 `[INT] Integration Owner` 檢查 artifact 能否接起來；完成後由 `[DOCS/MEM] Memory Curator` 決定寫回哪份長期記憶文件。
- 完成後把有效策略寫回 `AI_REGRESSION_LESSONS.md`、`TECH_DEBT.md` 或 `SYSTEM_TECH_GUIDE.md`，讓下一個 session 不重學。

### 外部 Agent Playbook 引用

可參考 [`msitarzewski/agency-agents`](https://github.com/msitarzewski/agency-agents) 的角色設計、deliverable 格式與多工具整合概念，但它不是 AllTrue 產品功能，也不可整包安裝覆蓋本 repo 規則。

工程技能工作流可參考 [`addyosmani/agent-skills`](https://github.com/addyosmani/agent-skills) — **完整評估與安裝 SOP 見 [`docs/GUIDE_AGENT_SKILLS.md`](docs/GUIDE_AGENT_SKILLS.md)**（繁中）；UI 品味另見同文件 §taste-skill（[`Leonxlnx/taste-skill`](https://github.com/Leonxlnx/taste-skill)，已裝 `design-taste-frontend`／`redesign-existing-projects`）。AllTrue 本地化技能見 [`docs/GUIDE_ALLTRUE_AGENT_SYSTEM_V1.md`](docs/GUIDE_ALLTRUE_AGENT_SYSTEM_V1.md) 與 `.cursor/skills/alltrue-*`（只挑技能本地化，禁止整包覆蓋 P0 / PRD 流程；UI 仍以 `docs/RULE_DESIGN_SYSTEM.md` 為準）。

引用時遵守：
- 只挑選角色/交付物模板，改寫成 AllTrue bounded context 的 artifact handoff。
- 所有外部 agent 規則都必須服從 `.cursorrules`、P0 gate、分校隔離、CI/deploy SOP。
- 禁止一鍵匯入大量 `.cursor/rules/*.mdc`；新增或改 rule 必須走 T0 docs-only PR。
- 若外部流程與 AllTrue P0 安全規則衝突，永遠以 AllTrue 規則為準。

### Workflow Risk Tiers

大廠 workflow 的重點不是所有任務都變重，而是讓風險決定流程重量：

| Tier | 範圍 | 必要流程 |
|---|---|---|
| T0 Docs-only | README、FAQ、INDEX、Runbook、規則文件，且不碰 `.github/**` / `scripts/**` | docs batch → `git diff --check` → PR；避免 deployable diff |
| T1 Low-risk code | 單一 UI 顯示、純 helper、無資料寫入、無權限邊界 | 小 PR → 對應測試/build → REVIEW |
| T2 Product workflow | 前後端契約、排課、出缺勤、評量、跨分校查詢 | PLAN/ARCH → DEV → TEST → INT → REVIEW |
| T3 Safety-critical | auth、PII、RFID、LINE webhook、堂數扣除、繳費、migration、備份/還原、CI/CD | PLAN/ARCH + SEC + OPS；使用者批准後才實作，CI 綠才可 merge |

**Definition of Ready（進 DEV 前）**
- 已定義 product intent、architecture boundary、API/DB/data ownership、錯誤處理、多校區隔離。
- 已判斷 Tier、是否需要 SEC/OPS/DBA、是否能平行。
- 已列出不可碰的檔案/邏輯與必讀文件。

**Definition of Done（回報完成前）**
- PR CI 狀態明確；docs-only 要確認未混入 deployable diff。
- 有使用者可驗收的測試或 smoke test 清單。
- 新規則、事故、技術債、架構決策已寫回正確文件。

**Stop-the-line 條件**
- 發現可能寫 production DB、繞過 auth、暴露 token/PII、直接 push `main`、force push、或在 Pi production 跑測試。
- CI/deploy 狀態不明但要回報「完成」。
- 備份/restore 目標不確定，或無法確認 restore drill 不會碰 production `AllTrue`。

## Commit SOP

每個獨立可驗收的子任務完成後立即 commit：

```bash
git add <相關檔案>
git commit -m "<type>(<scope>): <one-line summary>

<optional body: 說明 why，不是 what>"
```

- **type**：`feat` / `fix` / `refactor` / `test` / `docs` / `chore`
- **scope**：模組名（`billing` / `attendance` / `rfid` / `auth` 等）
- **禁止**：`git push --force`、跳過 CI、一次 commit 混入多個不相關的改動

## 其他工具入口

- **Claude Code**：讀根目錄 `CLAUDE.md`（若存在）
- **GitHub Copilot**：讀 `.github/copilot-instructions.md`（若存在）
- **GitHub 協作（分支／PR／Issue／通報）**：`CONTRIBUTING.md`、`SECURITY.md`
- **人類協作者**：先讀 `README.md`、`docs/INDEX.md`、本檔 `AGENTS.md`

<!-- exo:governance:begin -->
<!-- Governance hash: f45f0f00b0698aa4 -->
# ExoProtocol — Agent Operating Instructions

This repository is governed by ExoProtocol. All AI agent work must follow the session lifecycle.

## ExoProtocol Governance

- kernel: exo-kernel 0.1.0
- lock hash: `f45f0f00b0698aa4...`
- generated: 2026-08-15T14:50:36+08:00

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

- `npm run test:unit`
- `npm run lint:no-undef`
- `npm run build`
- `vendor/bin/phpunit`

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

1. `exo session-start --ticket-id <TICKET> --vendor <VENDOR> --model <MODEL> --task "<TASK>"`
2. Read `.exo/cache/sessions/<actor>.bootstrap.md`
3. Execute work within ticket scope
4. `exo session-finish --ticket-id <TICKET> --summary "<SUMMARY>" --set-status review`

## Enforcement

- Governance rules are enforced at the kernel level, not by prompt
- The bootstrap file contains your session's scope, checks, and lifecycle commands
- Drift detection runs at session-finish and is recorded in the session memento
- Audit sessions may be triggered to review your work independently

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

- No governed execution without active session
- Respect lock ownership and ticket scope
- Verification is default at finish; break-glass must be explicit
- All configurable values must be loaded from their source of truth at runtime — never hardcode, always test
- Read `.exo/LEARNINGS.md` for operational learnings from prior sessions

<!-- exo:governance:end -->

## Portfolio governance overlay

Read `governance/PORTFOLIO_AGENT_CONTRACT.md` before writing. It is committed
for cloud/mobile agents and contains the company's PR, approval, production,
and Founder-approval boundaries.
