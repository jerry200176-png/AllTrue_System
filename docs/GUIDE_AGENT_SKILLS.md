# Agent Skills 整合指南（AllTrue × addyosmani/agent-skills）

> **上游專案**：[addyosmani/agent-skills](https://github.com/addyosmani/agent-skills)（MIT）  
> **本文件角色**：評估「能不能用」＋「怎麼安全地用」；**不取代** `.cursorrules`、`AGENTS.md`、`docs/INDEX.md`。  
> **衝突時**：AllTrue P0 紅線與既有 SOP **永遠優先**。

---

## 這是什麼？

[agent-skills](https://github.com/addyosmani/agent-skills) 是一套給 AI 編碼代理用的 **工程技能包**：把資深工程師的習慣（先寫規格、TDD、五軸 code review、安全檢查、部署前驗證）寫成可重複執行的 `SKILL.md` 工作流程，而不是泛泛的 prompt。

生命週期對照：

```
定義 → 規劃 → 實作 → 驗證 → 審查 → 上線
/spec   /plan   /build  /test   /review  /ship
```

內含 **24 個技能**、**4 個專家 persona**（code-reviewer、security-auditor、test-engineer、web-performance-auditor）、參考 checklist 與多工具安裝說明（Claude Code、Cursor、Copilot 等）。

---

## 結論：我們能用嗎？

**能用，但只能「挑選＋改寫後嫁接」，不能整包覆蓋。**

| 維度 | 評估 |
|------|------|
| 技術相容 | ✅ `SKILL.md` 純 Markdown，Cursor 可讀；可用 `npx skills add` 或複製到 `.cursor/skills/` |
| 與現有治理重疊 | ⚠️ 我們已有 ORCH→PLAN→DEV→TEST→OPS 流水線、PRD 14 節、P0 gate、模組 rules — 整包 24 skills 會 **打架＋爆 context** |
| 與 P0 安全 | ⚠️ 上游 `git-workflow`、`shipping-and-launch` 是通用 trunk/CI 敘述；**必須加 AllTrue 附錄**（禁 push main、禁 Pi 跑測試、deploy.yml 唯一路徑） |
| 與業務域 | ⚠️ 不含補教業規則（堂數扣除、多校區、繳費提醒、行事曆合併 G-007 等）— 這些仍以 `AI_REGRESSION_LESSONS.md` 為準 |
| 投資報酬 | ✅ 下列 **5 個技能**與我們痛點高度重合，值得本地化 |

**一句話建議**：把 agent-skills 當 **外部 playbook 素材庫**（與 `agency-agents` 同級），挑技能、加 AllTrue 紅線附錄、放進 `.cursor/skills/`，不要一次灌進 `.cursor/rules/`。

---

## 與 AllTrue 現有體系對照

| agent-skills | AllTrue 已有 | 建議 |
|--------------|-------------|------|
| `spec-driven-development` | `plan-as-prd-cross-functional.mdc`（PRD 14 節） | **不重複安裝**；新功能走既有 PRD |
| `test-driven-development` | `module-test.mdc`、P0「先測試再改 production」 | **可補強**反合理化表格（「稍後再補測試」） |
| `code-review-and-quality` | Staff REVIEW phase、`review-bugbot` skill | **可補強**五軸 review 清單 |
| `debugging-and-error-recovery` | `bug-fix-plan.mdc` §B1 | **可補強**五步 triage 結構 |
| `security-and-hardening` | `module-security.mdc`、`review-security` skill | **可補強** OWASP checklist 執行順序 |
| `shipping-and-launch` | `auto-frontend-deploy.mdc`、`deploy.yml`、OPS checklist | **必須改寫** — 上游不含 Pi / RefreshDatabase 事故防護 |
| `git-workflow-and-versioning` | feature branch → PR、`scripts/git-sync.sh` | **必須改寫** — 禁 force push main |
| `context-engineering` | `docs/INDEX.md`、MemPalace | **不重複**；我們的 INDEX 更貼業務 |
| `incremental-implementation` | 小 PR、bounded context（`AGENTS.md`） | **可參考**垂直切片敘述 |
| `interview-me` / `idea-refine` | CEO 決策 + PLAN phase | 需求模糊時 **可選用** |

---

## 建議採用的技能（優先順序）

### 第一批（低風險、高 CP）

1. **`test-driven-development`** — 強化 RED→GREEN、先寫測試再動 production（對齊 P0 R1）
2. **`debugging-and-error-recovery`** — 補 bug 分診結構（與 `bug-fix-plan.mdc` 並用）
3. **`code-review-and-quality`** — merge 前五軸 review（與 Bugbot 互補）

### 第二批（需加 AllTrue 附錄）

4. **`security-and-hardening`** — auth/PII/RFID 任務前；**必須**交叉讀 `module-security.mdc`
5. **`shipping-and-launch`** — 僅作 checklist 骨架；**exit criteria 換成** OPS 驗收（health、version.json、禁 Pi 直改）

### 暫不建議整包安裝

- `frontend-ui-engineering` — 我們有 `RULE_DESIGN_SYSTEM.md`、`design-hex-guard`；整 skill 易與 token 規範衝突
- `ci-cd-and-automation` — 以 `.github/workflows/ci.yml`、`deploy.yml` 為準
- `documentation-and-adrs` — 以 `doc-writing.mdc`、CHANGELOG 一行原則為準

---

## Cursor 安裝方式（三選一）

### 方式 A：Vercel skills CLI（最快試用）

```bash
# 瀏覽清單
npx skills add addyosmani/agent-skills --list

# 只裝單一技能（建議）
npx skills add addyosmani/agent-skills --skill test-driven-development
npx skills add addyosmani/agent-skills --skill debugging-and-error-recovery
npx skills add addyosmani/agent-skills --skill code-review-and-quality
```

裝到 **個人層**（`~/.cursor/skills/`）試跑，確認不與 `.cursorrules` 衝突後，再決定是否 commit 到 repo。

### 方式 B：專案技能目錄（推薦正式採用）

```bash
# 在 repo 根目錄
mkdir -p .cursor/skills

# 複製上游 SKILL.md（之後手動加「AllTrue 附錄」區塊）
curl -sL https://raw.githubusercontent.com/addyosmani/agent-skills/main/skills/test-driven-development/SKILL.md \
  -o .cursor/skills/test-driven-development/SKILL.md
```

**每個本地化 skill 頂部必加：**

```markdown
## AllTrue 附錄（強制）

- 開工前：讀 `docs/INDEX.md` → 對應模組 §`AI_REGRESSION_LESSONS`
- P0：禁 push main、禁 Pi 跑 phpunit、CI 綠才改 production
- 高風險模組：行事曆合併、扣堂、繳費 — 先讀文件再動手
- 完成：更新 `docs/CHANGELOG.md`；若踩坑則 `AI_REGRESSION_LESSONS.md`
```

### 方式 C：複製到 `.cursor/rules/`（不建議整包）

上游 [cursor-setup.md](https://github.com/addyosmani/agent-skills/blob/main/docs/cursor-setup.md) 建議複製到 rules。**AllTrue 禁止一次灌多個 `.mdc`**（`AGENTS.md` §外部 Agent Playbook）。若用 rules，**同時最多 2–3 個**，且走 T0 docs-only PR。

---

## Persona（agents/）怎麼用？

上游 `agents/code-reviewer.md` 等可搭配現有 Cursor subagent：

| 上游 persona | AllTrue 對應 | 用法 |
|-------------|-------------|------|
| `code-reviewer` | `review-bugbot` skill、Task `bugbot` | PR merge 前 |
| `security-auditor` | `review-security` skill、Task `security-review` | auth/PII/RFID 變更 |
| `test-engineer` | `module-test.mdc`、[TEST] phase | 補測試策略時 |
| `web-performance-auditor` | 行事曆效能 epic | 效能 regression 時 |

**規則**：persona 不互相呼叫；由使用者或 slash 指令編排（與上游 `orchestration-patterns.md` 一致）。

---

## 反模式（禁止）

| 禁止 | 原因 |
|------|------|
| `npx skills add addyosmani/agent-skills` 整包 24 個 | context 爆炸；與 30+ 條 `.cursor/rules` 重複 |
| 用上游 `shipping-and-launch` 取代 `deploy.yml` SOP | 不含 Pi 事故防護（RefreshDatabase 等） |
| 用上游 git 流程取代 feature branch 治理 | 違反 P0 R3 |
| 跳過 AllTrue 附錄直接照 SKILL 做繳費/扣堂 | 業務規則在 `DIRECTOR_PAYMENT_ALERT_RULES.md` |
| 為 agent-skills 另建平行 `AGENT_PROGRESS.md` | 違反 `agent-long-running.mdc` — 用 TodoWrite + CHANGELOG |

---

## 建議落地步驟（T0，可開 docs-only PR）

1. **試用**：個人層安裝 `test-driven-development` + `debugging-and-error-recovery`，跑一個 in-app bug 修復驗證手感。
2. **本地化**：複製 3 個 SKILL 到 `.cursor/skills/`，加「AllTrue 附錄」。
3. **掛鉤**：在 `AGENTS.md` First-read 或 `bug-fix-plan.mdc` 加一行「可選讀 `.cursor/skills/debugging-and-error-recovery`」。
4. **評估**：一週後看 CI 失敗率、復發 bug 是否下降；無效益則移除，不堆技術債。

---

## 與其他外部專案比較

| 專案 | 定位 | AllTrue 關係 |
|------|------|-------------|
| [agent-skills](https://github.com/addyosmani/agent-skills) | 通用工程技能工作流 | **本文件** — 挑技能嫁接 |
| [agency-agents](https://github.com/msitarzewski/agency-agents) | 角色/交付物模板 | `AGENTS.md` 已引用 — 只借格式 |
| [CronusL AI-company](https://github.com/CronusL-1141/AI-company) | 公司型 agent 編排實驗 | 概念類似我們 ORCH 流水線；**不整包匯入** |

---

## 授權

上游 MIT。本地化改動（AllTrue 附錄）以本 repo 授權為準；引用時保留上游連結即可。

---

## 變更紀錄

| 日期 | 說明 |
|------|------|
| 2026-07-08 | 初版：可行性評估 + Cursor 安裝三途徑 + 優先技能清單 |
