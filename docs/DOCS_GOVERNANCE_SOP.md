# Docs Governance SOP

> 目的：確保 `docs/`、`README`、`INDEX`、Mempalace 記憶鏈維持一致，避免 AI 因文件分叉而失憶或執行錯誤 SOP。

---

## 1) 單一真相來源

- `docs/INDEX.md` 是文件導航唯一入口（先讀 INDEX，再讀對應章節）。
- `README.md`、`AGENTS.md`、`.cursorrules` 只做導航與原則，不複製長版 SOP。
- 流程或規則有異動時，只改「權威來源」並同步入口連結。

---

## 2) 固定節奏

- **每日（PR/任務完成時）**
  - 更新 `docs/CHANGELOG.md`（一行原則，描述使用者可感知變化）。
  - 若發現 AI 新踩坑，更新 `docs/AI_REGRESSION_LESSONS.md`。

- **每週（文件巡檢）**
  - 執行 `node scripts/docs-integrity-check.mjs --strict`
  - 修正斷鏈、遺漏導航、入口與章節不一致問題。

- **每月（記憶保鮮）**
  - 將近期對話與 docs 重新 mine 到 MemPalace。
  - 抽查高風險關鍵字是否可被 `mempalace search` 命中。

---

## 3) CI 自動化

- Workflow：`.github/workflows/docs-integrity.yml`
- 觸發：
  - PR（文件/規則/README/INDEX 相關檔案改動）
  - 每週排程（週一）
- 檢查重點：
  - 核心文件是否存在（INDEX、README、CHANGELOG、AI regression lessons）
  - `README` / `AGENTS` 是否有導向 `docs/INDEX.md`
  - Markdown 相對連結與 anchor 是否有效

---

## 4) Mempalace 保鮮流程

```bash
# A. 重要任務完成後：對話記憶入庫
~/.local/bin/mempalace mine ~/.cursor/projects/home-jerry-alltrue/agent-transcripts \
  --mode convos --wing alltrue-sessions

# B. docs 有更新時：文件知識入庫
~/.local/bin/mempalace mine ~/alltrue/docs --wing alltrue-docs

# C. 每週抽查（任一高風險關鍵字）
~/.local/bin/mempalace search "deploy workflow"
~/.local/bin/mempalace search "php artisan test production"
```

---

## 5) 變更守則（避免 SOP 分叉）

- 變更流程時，先改權威文件，再補 `docs/INDEX.md` 的導航項目。
- 不在多份文件複製完整 SOP（避免版本漂移）。
- 若發現衝突規則，以 `.cursorrules` + `docs/INDEX.md` + `docs/OPERATIONS_RUNBOOK.md` 為準，並在同一 PR 修正衝突文件。

---

## 6) 驗收標準（Definition of Done）

- `node scripts/docs-integrity-check.mjs --strict` 通過
- `docs/INDEX.md` 已收錄新文件導航
- 相關 PR 的 CI（含 docs-integrity）為綠燈

