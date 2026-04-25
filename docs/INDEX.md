# AllTrue Docs Index — AI 導航地圖

> 第一步先讀這裡，再根據任務跳去對應文件。設計原則：**最小讀取，最大效果**。

---

## 🚨 開工前必讀（每次都要）

| 檔案 | 內容 | Token 成本 |
|------|------|-----------|
| `.cursorrules` §P0 | 5 條紅線 + 3 條黃線 + 6 次事故摘要 | 已自動載入 |
| `docs/AI_REGRESSION_LESSONS.md` | R1-R18 防再犯規則（127 行）| 按需讀 |

---

## 📋 任務導航（按任務類型跳）

### 新功能 / Bug 修復
1. `.cursor/rules/plan-as-prd-cross-functional.mdc` — PRD 14 節格式
2. `.cursor/rules/bug-fix-plan.mdc` — Bug 調查 SOP
3. `docs/AI_REGRESSION_LESSONS.md` — 對應模組的已知坑

### 後端開發
| 需要什麼 | 去哪裡找 |
|----------|---------|
| API 路由清單 | `CLAUDE.md` §API 路由 |
| DB schema / 資料表結構 | `CLAUDE.md` §核心資料表 |
| 高風險邏輯（繳費/堂數） | `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` |
| Migration 規則 | `.cursor/rules/module-migration.mdc` |
| 測試規則 / Factory | `.cursor/rules/module-test.mdc` |

### 前端開發
| 需要什麼 | 去哪裡找 |
|----------|---------|
| 頁面清單 + active key | `CLAUDE.md` §前端頁面 |
| Deploy SOP | `.cursor/rules/auto-frontend-deploy.mdc` |
| UI 設計規則 | `.cursor/rules/module-frontend.mdc` |

### 部署 / 維運
| 需要什麼 | 去哪裡找 |
|----------|---------|
| 部署 SOP（Phase 7） | `docs/OPERATIONS_RUNBOOK.md §A-B` |
| 緊急事故 / 回滾 | `docs/DANGEROUS_OPERATIONS.md` |
| Dependabot merge SOP | `docs/OPERATIONS_RUNBOOK.md §B0` |
| Secret 輪換 | `docs/OPERATIONS_RUNBOOK.md §O` |
| 工程成熟度現況 | `docs/OPERATIONS_RUNBOOK.md §P` |

### 資安審查
| 需要什麼 | 去哪裡找 |
|----------|---------|
| STRIDE 速查 | `.cursor/rules/module-security.mdc` |
| 已知安全漏洞 | `docs/SECURITY.md` |
| 家長入口安全規則 | `docs/AI_REGRESSION_LESSONS.md §R18` |

### 技術債
| 需要什麼 | 去哪裡找 |
|----------|---------|
| Open 技術債清單 | `docs/TECH_DEBT.md` |
| 清償流程 | `.cursor/rules/tech-debt.mdc` |

### 測試帳號 / 登入
- `.cursor/.local/test-credentials.md` — 各角色帳密 + Browser MCP 踩坑 SOP

---

## 🗄️ 文件目錄（完整）

### 核心規則（每次任務按需查）
| 檔案 | 一行說明 |
|------|---------|
| `.cursorrules` | P0 事故紀錄 + 工作流程總覽（自動載入）|
| `CLAUDE.md` | Claude Code 版總覽（同 `.cursorrules`，不重複讀）|
| `AGENTS.md` | Agent 開工順序 + Commit SOP |
| `.cursor/rules/p0-gate.mdc` | 5 紅線 3 黃線速查卡 |

### 防再犯
| 檔案 | 一行說明 |
|------|---------|
| `docs/AI_REGRESSION_LESSONS.md` | R1-R18 已發生的坑，改前必查 |
| `docs/AI_REGRESSION_LESSONS_ARCHIVE.md` | 33 條詳細事故記錄（按需查）|

### 業務規則
| 檔案 | 一行說明 |
|------|---------|
| `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` | 繳費/續課提醒規則，**禁止擅改** |
| `docs/PRICING_CONTRACT.md` | 費率合約（每堂費用計算）|
| `docs/ROLE_PLAYBOOK.md` | 各角色權限與 UI 行為 |
| `docs/DIRECTOR_SCALING_FAQ.md` | 主任常見問題 |
| `docs/FAQ.md` | 系統常見問題 |

### 技術文件
| 檔案 | 一行說明 |
|------|---------|
| `docs/SYSTEM_TECH_GUIDE.md` | 架構深度文件（延伸閱讀，非必讀）|
| `docs/CHANGELOG.md` | 最近上線功能記錄 |
| `docs/CHANGELOG_ARCHIVE_2026-04.md` | 舊 CHANGELOG |
| `docs/TECH_DEBT.md` | TD-NNN 技術債清單 |
| `docs/DANGEROUS_OPERATIONS.md` | 高風險操作清單與 SOP |
| `docs/DEPLOYMENT.md` | 部署架構說明 |
| `docs/DB_PERF.md` | DB 效能優化記錄 |
| `docs/SECURITY.md` | 安全設計決策 |
| `docs/WSL2_DEV_SETUP.md` | WSL2 本地開發環境設定 |

### 維運 SOP
| 檔案 | 一行說明 |
|------|---------|
| `docs/OPERATIONS_RUNBOOK.md` | 完整 SOP 手冊（§A-P，按節查）|
| `docs/DAILY_CHECKLIST.md` | 每日例行檢查清單 |

### 模組文件
| 檔案 | 一行說明 |
|------|---------|
| `docs/SCHEDULE_DISCREPANCY_REVIEW.md` | 課表出入差異審核流程 |
| `docs/SUBSTITUTE_UX.md` | 代課 UX 設計 |
| `docs/MANUAL_SCHEDULE_DATE_SEMANTICS.md` | 排課日期語義 |
| `docs/CHAT_BUG_SYSTEM.md` | 問題回報系統設計 |
| `docs/LINE_LIFF_CHECKLIST.md` | LINE LIFF 上線檢查清單 |

---

## 🤖 GitHub Workflows（自動化）

| Workflow | 觸發時機 | 功能 |
|----------|---------|------|
| `ci.yml` | 每次 PR | PHPUnit + coverage gate + composer/npm audit |
| `deploy.yml` | merge to main | 自動部署 Pi + smoke test + rollback |
| `presubmit.yml` | 每次 PR | Branch 命名規範檢查 |
| `codeql.yml` | 每次 PR | PHPStan level 5 靜態分析 |
| `pi-health.yml` | 每 6 小時 | 磁碟/溫度/備份年齡/UptimeRobot |
| `slow-query-report.yml` | 每週一 | MySQL 慢查詢報告 |
| `backup-restore-test.yml` | 每月 1 日 | 備份還原完整性驗證 |
| `dora-metrics.yml` | 每週一 | DORA 指標計算（部署頻率/lead time/CFR）|
| `branch-hygiene.yml` | 每週日 | 清理過期 branches |

---

## 📐 MemPalace 導航（AI 記憶層）

```bash
# 搜尋任何主題
mempalace search "<關鍵字>"

# 看全局記憶摘要
mempalace wake-up

# 每次 PR merge 後自動 mine（post-merge hook）
```

Wings：`alltrue-sessions`（對話記憶）、`alltrue-code`（程式碼知識）

---

## ⚡ 省 Token 原則

1. **先讀 INDEX.md（本檔）** → 確定要讀哪個文件 → 只讀那個
2. **不要全讀 SYSTEM_TECH_GUIDE.md**（延伸閱讀，按需查對應節）
3. **`.cursorrules` 已自動載入**，不需再 Read
4. **`CLAUDE.md` = `.cursorrules` 的 Claude 版**，兩者不需同時讀
5. **MemPalace `wake-up`** 在 session 開始時替代全讀文件
