# AGENTS.md — AllTrue AI Agent 入口

> 唯一通用入口，只導航：一個主題只有一個權威出處。P0 紅黃線在 `CLAUDE.md`。
>
> **AllTrue AI 公司 slogan：前人種樹，後人乘涼。** 做事前查 `docs/INDEX.md` 的 Start here 與任務對應節；做完把決策寫回文件，讓下一個 AI 不靠猜測。

## Worktree & path safety (canonical)

**Default (Founder 2026-10-14（三）, [`FOUNDER_STANDING_DECISIONS.md`](docs/governance/FOUNDER_STANDING_DECISIONS.md)):** work in a Claude cloud session. Clone fresh from `origin/main`, branch with an accepted `scripts/ci/branch-policy.mjs` prefix, open a PR. No `agent-start`, no local preflight, no `.agent-session/manifest.json`. The PR diff, risk declaration, review and required checks are the delivery evidence.
**Local WSL:** only if a task truly needs it; use an isolated task worktree as the policy describes, never the forbidden checkouts. `agent-start` is retired.
**Policy:** [`docs/governance/WORKTREE_POLICY.md`](docs/governance/WORKTREE_POLICY.md)

## 開工前 First-read 順序

**SOP（防重踩同坑）**：收到任務後**先讀文檔再打程式**，禁止只靠對話上下文硬改。

**操作者權限（適用於每一次任務，不是要讀的檔案，是規則本身）**：操作者是 **Agent**。T0/T1 在 required checks、風險適當 review 與證據通過後可自行 merge／關 issue；T2 另需 independent review、rollback boundary 與完整 CI。read-only production-case-dump probe（`.github/workflows/production-case-dump.yml` 僅新增/修正唯讀 case，diff 無寫入標記）為 T2，Agent 於 CI 綠燈 + review 後自行 merge；寫入仍為 T3 Founder GO。T3 / protected 工作可研究、實作、測試並準備 evidence package，但在 production activation、production data repair、migration/schema cutover、billing／entitlement、identity/authz、破壞性動作、backup restore、security-sensitive credential 或重大產品方向前停下等 Founder GO（具名例外依 `docs/governance/FOUNDER_STANDING_DECISIONS.md` 的現行適用條款，此處不摘要）。機器禁令：Pi SSH / artisan / phpunit、印 secrets、force-push、`--admin`、Gmail 刪信。產品 P0 與 Control Plane I1 仍有效。Capabilities 詳見 `docs/governance/AGENT_CAPABILITY_REGISTRY.md`，勿假設權限。

**Founder 常設決定**：唯一定義見 [`docs/governance/FOUNDER_STANDING_DECISIONS.md`](docs/governance/FOUNDER_STANDING_DECISIONS.md)（每次任務都適用；此處不寫摘要，避免分歧）。

### 必讀（7 份，每次任務都讀；這是唯一一份必讀清單）

1. **`docs/governance/COMPANY_CONSTITUTION.md`** + **`docs/sop/AGENT_PREFLIGHT.md`**（公司根政策；cloud session 只略過 AGENT_PREFLIGHT 的步驟 1–2，其餘含 production 主張的 `make production-identity` 照舊）
2. **`docs/architecture/ALLTRUE_ENGINEERING_NORTH_STAR.md`**（現行工程主線；禁止整包重寫）
3. **`docs/INDEX.md`** 的 **Start here** + 任務對應節（導航）
4. **`docs/sop/MERGE_SOP.md`**（開 PR 到上線：審查、land queue、多 agent 並行規則）
5. **`CLAUDE.md`** §紅線／黃線（P0 紅黃線與事故）
6. **`docs/agents/token-discipline.md`**（省 token 作業規則）

其他文件（含 `docs/AI_REGRESSION_LESSONS.md`）都是「碰到對應任務才查」，見下表。
（不得假設任何 client 已自動載入規則檔，未載入時先讀原文，保留完整 P0。實際載入／截斷未能查證時記 unknown。）

### 條件分支（依任務類型挑對應章節，不用全讀）

| 任務類型 | 加讀 |
|---|---|
| 改排課／行事曆／扣堂 | `docs/architecture/RFC_SCHEDULE_OCCURRENCE_IDENTITY.md`（TD-076）+ `AI_REGRESSION_LESSONS.md` 模組索引表對應的 R10x 系列。改容量徽章／代課已滿：先讀 [`docs/plans/2026-08-17-mixed-class-type-occupancy.md`](docs/plans/2026-08-17-mixed-class-type-occupancy.md) 與 **§R116**（#1889）；禁止用較嚴班型上限蓋掉一對三空位；§R114 殘影過濾不可回退 |
| 改計費／繳費提醒／核帳登記／電子收據時機 | `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` + [`docs/architecture/RFC_REPORTED_PAID_ACCOUNTING_SPLIT.md`](docs/architecture/RFC_REPORTED_PAID_ACCOUNTING_SPLIT.md)（#1827）。禁止把行政登錄再次做成一次 `Paid=1`＋核銷＋開收據。 |
| 處理 in-app 意見與建議／BugReport 產品閉環 | **先載** [`.agents/skills/alltrue-inapp-product-loop/SKILL.md`](.agents/skills/alltrue-inapp-product-loop/SKILL.md)→ [`docs/plans/INAPP_PRODUCT_LOOP_EXECUTION_POLICY_V1.md`](docs/plans/INAPP_PRODUCT_LOOP_EXECUTION_POLICY_V1.md) + `docs/CHAT_BUG_SYSTEM.md` **§3.6–§3.7**；依風險再開 `alltrue-debugging`／`testing`／`code-review`／`release`／`security` |
| 處理 in-app Bug（分診或修完上線） | 同上 skill + `AI_REGRESSION_LESSONS.md` **§R51、§R53**（開 issue 與 merge 後都要回系統留言，勿只動 GitHub）|
| 高風險模組（代課／評量／智慧行事曆合併等）任何改動 | `docs/AI_REGRESSION_LESSONS.md` 文末模組索引表對應 § — 動 `backend/`／`frontend/src/` 前必讀 |
| 需要回顧決策或舊 bug | `~/.local/bin/mempalace search "<關鍵字>"`（本機搜尋；非跨機器權威）|
| 任何瀏覽器測試 | `.cursor/.local/test-credentials.md` |
| 任務牽涉長文件／多份 docs | `docs/INDEX.md` 的速讀卡／治理節奏（`docs/AI_DOC_LITERACY.md` 只是索引 stub）|
| Codex 長任務接續／交接／重複失敗 | 按需使用 [alltrue-task-continuity](.agents/skills/alltrue-task-continuity/SKILL.md)，沿用 `RUNBOOK_HANDOFF` 並核對現況；不載入整段歷史、不自動重播動作 |
| 改 critical domain（排課／帳務／出缺勤等） | 先讀 [`docs/architecture/README.md`](docs/architecture/README.md) 中與任務相關的圖（跨 domain 才讀 `alltrue-runtime.architecture.json`）。圖是導覽，不高於程式：與現行實作不一致時，查是文件過期還是 regression |
| 每次任務開工前先判斷任務類型（含多 agent／多 PR、PRD／ARCH 規劃、引用外部 agent playbook） | [`docs/agents/orchestration.md`](docs/agents/orchestration.md) |

## Code Review Rules

### External notifications

- When a PR changes an outbound notification or its switch, trace every producer and sender, including device/client consumers of API payloads. Verify that disabling the switch suppresses all sends and that enabled paths do not duplicate messages or charges. A controller-only test is insufficient when another sender uses its response.

### Campus and guardian boundaries

- For new or changed APIs and notifications, verify the server enforces the authenticated campus scope on reads, writes, and recipient selection; an empty campus list grants no campus. Parent LINE sends must use verified bindings for the same student and campus. Preserve the documented super-admin exception.

### Attendance and billing effects

- When a PR changes RFID, attendance, scheduling, or payment flows, trace effects to the authoritative session and ledger records. Flag duplicate attendance or deduction, cross-campus effects, and paths that can claim success before the intended write or external send is verified. Require a focused regression scenario for the affected path.

## PR review and delivery handoff

- Follow the live GitHub ruleset and [`docs/governance/RISK_BASED_MERGE_POLICY.md`](docs/governance/RISK_BASED_MERGE_POLICY.md). For T0–T2, the implementing Agent resolves review findings, passes exact-head required checks, follows the risk-specific merge SOP, and continues delivery without asking the Founder to say GO or click Approve for each phase or PR when no approving review is required.
- A Codex or other bot review supplies evidence, not a substitute identity for the PR author. Review findings must be addressed; a clean bot review alone does not satisfy tests, rollback readiness, or production verification.
- For T3/protected actions, prepare the exact target, effect, credible downside, rollback, and verification evidence, then obtain the Founder decision required by the canonical policy. An approval already given for that concrete scope continues through its necessary steps; ask again only if the scope or protected effect materially changes.

## 公司治理記錄原則

- 新功能 / bug fix 上線：新增 `docs/changes/<date>-<slug>.md` fragment（不要改凍結的 `docs/CHANGELOG.md`），並依 [`docs/GUIDE_STAFF_UPDATES.md`](docs/GUIDE_STAFF_UPDATES.md) 加上 `staff_update` 或 `silent_ship` 決策標記；教職員可感知的變更必須新增 `docs/staff-updates/<id>.yml`（不要改 `docs/STAFF_UPDATES.yml`）。
- AI 犯錯或發現防再犯規則：更新 `docs/AI_REGRESSION_LESSONS.md`。
- 本次不修但會影響未來維護：更新 `docs/TECH_DEBT.md`。
- 複雜架構、資料流或 SOP：更新 `docs/SYSTEM_TECH_GUIDE.md` 或 `docs/OPERATIONS_RUNBOOK.md`。
- 文件不要複製長 SOP；入口文件只導航，單一主題只保留一個權威出處。

## Workflow risk tiers

大廠 workflow 的重點不是所有任務都變重，而是讓風險決定流程重量。各級定義只看 [`docs/governance/RISK_BASED_MERGE_POLICY.md`](docs/governance/RISK_BASED_MERGE_POLICY.md) §Risk classes；各級要走的車道、九個階段、Definition of Ready（階段 4）與 Definition of Done（階段 9）只看 [`docs/sop/DELIVERY_LIFECYCLE.md`](docs/sop/DELIVERY_LIFECYCLE.md)；Founder 何時要決定或按，只看 `FOUNDER_STANDING_DECISIONS.md`（此處都不摘要）。

**Stop-the-line 條件**
- 發現可能寫 production DB、繞過 auth、暴露 token/PII、直接 push `main`、force push、或在 Pi production 跑測試。
- CI/deploy 狀態不明但要回報「完成」。
- 備份/restore 目標不確定，或無法確認 restore drill 不會碰 production `AllTrue`。

## Land queue / Parallel agents

只在 [`docs/sop/MERGE_SOP.md`](docs/sop/MERGE_SOP.md#land-queue-and-auto-delivery-mechanics)（含 [Parallel agents](docs/sop/MERGE_SOP.md#parallel-agents)）。

## Commit SOP

每個獨立可驗收的子任務完成後立即 commit：`<type>(<scope>): <one-line summary>`，body 寫 why。
type：`feat` / `fix` / `refactor` / `test` / `docs` / `chore`；scope：模組名。
禁止：`git push --force`、跳過 CI、一次 commit 混入多個不相關的改動。

## 其他入口

Claude Code 另讀 `CLAUDE.md`；Codex 另讀 `codex.md`；GitHub 協作見 `CONTRIBUTING.md`、`SECURITY.md`；人類協作者先讀 `README.md`、`docs/INDEX.md`。

## Portfolio governance overlay

Read `governance/PORTFOLIO_AGENT_CONTRACT.md` before writing. It is committed
for cloud/mobile agents and contains the company's PR, approval, production,
and Founder-approval boundaries.
