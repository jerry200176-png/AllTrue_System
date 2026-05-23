# Docs 整理提案：3-tier 階層 + 大廠對齊（v1，待 CEO 批准範圍）

> **狀態**：Plan / proposal — 不在本 PR 動手，本 PR 只寫提案 + 「白話留言」規則
> **建立日期**：2026-05-24
> **要求來源**：CEO「我們的 docs 要整理一下了 確保 AI 記憶 readme mempalace 等 各種文檔 參考開源或大公司作法 不要讓 AI 讀太長文章失憶或浪費 token」
> **既有相關文件**：`docs/INDEX.md`、`docs/AI_DOC_LITERACY.md`、`docs/DOCS_GOVERNANCE_SOP.md`、`.cursor/rules/doc-writing.mdc`

---

## 0. 現況審計（為何要整理）

### 0.1 重複內容（token 浪費）

| 文件 | 行數 | 自動載入 | 重疊對象 |
|------|------|----------|---------|
| `.cursorrules` | 238 | ✅ | 與 `CLAUDE.md` 重複 70%（P0 / 工作流程 / Gotchas） |
| `CLAUDE.md` | 378 | ✅（Claude Code） | 與 `.cursorrules` 重複 70% |
| `docs/INDEX.md` | 247 | 手動讀 | 與 `AI_DOC_LITERACY.md` 速讀卡 50% 重疊 |
| `docs/AI_DOC_LITERACY.md` | 135 | 手動讀 | 與 `INDEX.md` + `DOCS_GOVERNANCE_SOP.md` 重疊 |
| `docs/DOCS_GOVERNANCE_SOP.md` | 75 | 手動讀 | 與 `AI_DOC_LITERACY.md` §MemPalace 重疊 |
| `AGENTS.md` | 104 | ✅（部分工具） | OK，主要是入口導航 |

**每次 session 自動載入**：`.cursorrules` (238) + `CLAUDE.md` (378) + `AGENTS.md` (104) + 7 個 `.cursor/rules/*.mdc` (1100+) = **約 1800 行**。
這還沒算使用者主動讀的文件。

### 0.2 47 個 docs 檔的分類問題

- 入口／導航（4）：`INDEX`、`AI_DOC_LITERACY`、`DOCS_GOVERNANCE_SOP`、`AGENTS.md`
- 核心規則（6）：`AI_REGRESSION_LESSONS`、`DIRECTOR_PAYMENT_ALERT_RULES`、`PRICING_CONTRACT`、`SECURITY`、`OPERATIONS_RUNBOOK`、`DANGEROUS_OPERATIONS`
- 模組／業務（10）：`CHAT_BUG_SYSTEM`、`SUBSTITUTE_UX`、`ROLE_PLAYBOOK`、`SCHEDULE_DISCREPANCY_REVIEW` 等
- 維運（7）：`DAILY_CHECKLIST`、`SMOKE_TEST_RUNBOOK`、`DEPLOYMENT`、`WSL2_DEV_SETUP` 等
- SRE/Product Ops（3）：`SRE_POLICY`、`PRODUCT_OPS`、`PROFESSIONAL_PERCEPTION_SURVEY`
- 歷史／PRD（10）：`PRD_*.md`、`CTO_SPEC_*.md`、`TECH_REPORT_*.md`、`*_ARCHIVE.md` 等 — **這層最髒，應該移到 `docs/archive/`**
- 其他（7）：包含中文檔名、`security/` 子目錄、`alltrue.conf` 等

---

## 1. 大廠 / 開源做法調研

### 1.1 Google（內部 + 外部 dev docs）

- **Documentation Style Guide**：每篇 doc 一個明確目的（quickstart / how-to / reference / explanation — 四象限）
- **單一 source of truth**：禁止把同一個 SOP 寫在兩個地方
- **Doc owner**：每篇有指定維護人，stale 6 個月以上自動 issue

→ AllTrue 對齊：`DOCS_GOVERNANCE_SOP.md` 已有「單一真相來源」原則，但**沒有 owner 標籤**。

### 1.2 Stripe Developer Docs

- **三層金字塔**：
  - L1 Quickstart（< 5 分鐘上手）
  - L2 Recipes（任務導向，how-to）
  - L3 Reference（API 細節，搜尋導向）
- **Scanning-first writing**：標題 = TOC、短段落、code 在前

→ AllTrue 對齊：`INDEX.md` 算 L1 quickstart，但太多 L1 角色（INDEX + AI_DOC_LITERACY + AGENTS 都在搶入口）。

### 1.3 Vercel / Linear / Supabase（modern OSS）

- **flat 結構**：避免 `docs/foo/bar/baz.md` 多層；用 prefix 命名（`runbook-deploy.md`, `runbook-rollback.md`）
- **README → 連到一切**：根目錄 `README.md` 是唯一不變的入口
- **Deprecation banner**：過時文件頂部 yellow box「已 deprecated, 看 X.md」

→ AllTrue 對齊：`docs/` 是 flat 的（好），但**缺乏 prefix 命名**（不知道哪個檔是 runbook、哪個是 PRD）。

### 1.4 Kubernetes / 大型 OSS

- **CONTRIBUTING.md**（怎麼貢獻）+ **DEVELOPMENT.md**（怎麼開發）+ **GOVERNANCE.md**（怎麼決策）三檔分工清楚
- **archive/**：歷史 PR、舊 RFC、舊 design docs 全部進 archive，不污染主目錄

→ AllTrue 對齊：`CONTRIBUTING.md` 已有，但 `archive/` 還沒系統化。

### 1.5 AI-native（Claude / Cursor / Continue）

- **Single `AGENTS.md`** 趨勢（取代散落的 `.cursorrules` / `CLAUDE.md` / `.github/copilot-instructions.md`）
- **Always-loaded 規則放最短**（每 session 都吃 token）
- **長 SOP 放 on-demand**（INDEX 路由）

→ AllTrue 對齊：**`.cursorrules` 和 `CLAUDE.md` 重複是最大可省 token 項目**。

---

## 2. 提案：3-tier 階層

```
┌─────────────────────────────────────────────────────────┐
│ Tier 0 — 永遠載入（每 session 都吃 token）              │
│   .cursorrules              ← 唯一 source of truth      │
│   CLAUDE.md                 ← 5 行指針，全部委派給 .cursorrules │
│   AGENTS.md                 ← 入口（保持 100 行內）     │
│   .cursor/rules/p0-gate.mdc ← 5 紅線 + 3 黃線速查       │
│   .cursor/rules/user-facing-communication.mdc ← 新增    │
└─────────────────────────────────────────────────────────┘
              ↓ 任務開始
┌─────────────────────────────────────────────────────────┐
│ Tier 1 — 導航（手動讀，每次任務開始一次）              │
│   docs/INDEX.md             ← 唯一導航                  │
│   （AI_DOC_LITERACY.md 合併進 INDEX 附錄）              │
│   （DOCS_GOVERNANCE_SOP.md 合併進 INDEX 附錄）          │
└─────────────────────────────────────────────────────────┘
              ↓ 按 INDEX 路由
┌─────────────────────────────────────────────────────────┐
│ Tier 2 — 主題（只讀對應段，不通讀）                    │
│   docs/AI_REGRESSION_LESSONS.md                         │
│   docs/DIRECTOR_PAYMENT_ALERT_RULES.md                  │
│   docs/OPERATIONS_RUNBOOK.md                            │
│   docs/CHAT_BUG_SYSTEM.md                               │
│   docs/SECURITY.md                                      │
│   ... (約 20 個活躍 docs)                               │
│   .cursor/rules/module-*.mdc（按 glob 條件載入）        │
└─────────────────────────────────────────────────────────┘
              ↓ 搜尋而非閱讀
┌─────────────────────────────────────────────────────────┐
│ Tier 3 — 歷史／參考（只 rg / MemPalace search）        │
│   docs/archive/             ← 新建統一資料夾            │
│     PRD_*.md                                            │
│     CTO_SPEC_*.md                                       │
│     TECH_REPORT_*.md                                    │
│     AI_REGRESSION_LESSONS_ARCHIVE.md                    │
│     CHANGELOG_ARCHIVE_*.md                              │
│     更新網站前端.md                                     │
│     使用說明_*.md                                       │
│   MemPalace（語意搜尋）                                 │
└─────────────────────────────────────────────────────────┘
```

---

## 3. 具體執行清單（CEO 圈選後分 PR）

### 3.1 Phase A — 永遠載入瘦身（token 大省）

**目標**：每 session 自動載入從 1800 行 → < 700 行

| 動作 | 影響檔 | 預估省 | 風險 |
|------|--------|-------|------|
| `CLAUDE.md` 改為 5 行 pointer，主體合併進 `.cursorrules` | `CLAUDE.md` (378 → 5) | -373 行 / session | 低（Claude Code 自動跟 pointer） |
| `.cursor/rules/alltrue-system.mdc` （209 行）與 `.cursorrules` §核心資料表 / §API 整併去重 | -100 行 | 中（要小心邊界） |
| `.cursor/rules/p0-never-force-push-and-deploy.mdc`（233 行）拆 30 行 must-know + 200 行 on-demand | -150 行 | 低 |

預估每 session 省 **~600 行 token**。

### 3.2 Phase B — 入口導航整合

**目標**：把「怎麼讀 docs」相關文件收斂成 1 個

- `INDEX.md` + `AI_DOC_LITERACY.md` + `DOCS_GOVERNANCE_SOP.md` → 合併為**單一** `INDEX.md`：
  - §1 導航表（原 INDEX 主體）
  - §2 速讀卡（原 AI_DOC_LITERACY 速讀卡）
  - §3 治理節奏（原 DOCS_GOVERNANCE_SOP §2-§6）
- 舊兩檔保留 1 行 redirect 後刪除

### 3.3 Phase C — 47 個 docs 分類與命名

**統一 prefix 命名**：

| 前綴 | 用途 | 範例 |
|------|------|------|
| `RULE_` | 規範性，read-before-doing | `RULE_PAYMENT_ALERTS.md`（原 DIRECTOR_PAYMENT_ALERT_RULES）|
| `RUNBOOK_` | 操作手冊 | `RUNBOOK_DEPLOY.md`、`RUNBOOK_DANGEROUS.md` |
| `REF_` | 純參考查表 | `REF_API_ROUTES.md`、`REF_DB_SCHEMA.md` |
| `MODULE_` | 模組設計 | `MODULE_CHAT_BUG.md`、`MODULE_SUBSTITUTE.md` |
| `GUIDE_` | 教學 / how-to | `GUIDE_WSL2_SETUP.md` |
| `POLICY_` | 政策決策 | `POLICY_SRE.md`、`POLICY_SECURITY.md` |

> 不強制全改，但**新 docs 一律遵守**；舊 docs 在下次大改時順手改名。

### 3.4 Phase D — Archive 整理

新建 `docs/archive/` 並移入：
- `PRD_*.md`（4 個）
- `CTO_SPEC_*.md`（1 個）
- `TECH_REPORT_*.md`（1 個）
- `*_ARCHIVE_*.md`（2 個）
- `更新網站前端.md`、`使用說明_*.md`（過時操作手冊）
- `ENGINEERING_MATURITY_GAPS.md`（決策已轉入 OPERATIONS_RUNBOOK §P）

**rule**：archive 內檔案**不被 INDEX 推薦讀取**，只能 `rg` 或 MemPalace search 命中。

### 3.5 Phase E — MemPalace 自動化

- 每次 PR merge 後（post-merge hook）自動 `mempalace mine`
- 每月 1 號 cron 重 mine 整個 `docs/`
- `mempalace wake-up` 改為「過去 7 天 PR + 過去 30 天事故」摘要（取代讀 INDEX）

### 3.6 Phase F — Doc owner 制度

每個 Tier 2 docs 加 header：
```yaml
---
owner: jerry@alltrue (CEO) | super_admin
review_cycle: quarterly | monthly | as-needed
last_reviewed: 2026-05-24
---
```

`docs-integrity.yml` workflow 加檢查：超過 review_cycle 自動開 issue 提醒。

---

## 4. 不在本 PR 範圍

**在本 PR**：
- 設計提案（本檔）
- `.cursor/rules/user-facing-communication.mdc`（白話留言規則，這是 CEO 同一個 message 內的另一個要求）
- `docs/CHAT_BUG_SYSTEM.md` §3.8（白話留言檢查清單）
- CHANGELOG

**不在本 PR**（等 CEO 批准 Phase A/B/C/D/E/F 哪幾個）：
- 任何重命名 / 移動 / 合併操作
- `archive/` 資料夾建立
- `CLAUDE.md` 瘦身

---

## 5. 風險與緩解

| 風險 | 緩解 |
|------|------|
| 改完所有 doc link 都壞 | 每個 Phase 結束跑 `node scripts/docs-integrity-check.mjs --strict` |
| AI 找不到舊 doc（被 archive 了） | archive 內留 redirect markdown「本檔已 archive，請見 XXX.md」 |
| `.cursorrules` 太短反而失去細節 | 不刪規則，只是把細節從「auto-load」移到「按 glob 條件 load」 |
| CEO 不喜歡新命名（RULE_, RUNBOOK_ 等） | 命名僅適用新 doc；舊 doc 維持原名直到自然需要動 |
| MemPalace mine 沒跑 → AI 失憶 | post-merge hook + 每月 cron 雙保險 |

---

## 6. CEO 回覆即可推進

請圈選要做的 Phase（可複選）：

- [ ] **Phase A**（永遠載入瘦身，省 token 最大）
- [ ] **Phase B**（入口導航整合，3 檔合 1）
- [ ] **Phase C**（命名 prefix 規則，僅約束新 doc）
- [ ] **Phase D**（archive 整理，搬 10 個檔到 `docs/archive/`）
- [ ] **Phase E**（MemPalace 自動化）
- [ ] **Phase F**（Doc owner / review_cycle 元資料）
- [ ] **全做**（A→F 依序，預估 4-6 個 PR）

CEO 回覆後我會逐 Phase 開 PR，每個 PR 都跑 `docs-integrity-check`，merge 前 CI 必須綠。
