# AllTrue 補習班管理系統

AllTrue 是一套給補習班使用的全端管理系統，把「學生、課程、排課、點名、繳費、學習評量、家長通知」整合在同一個平台，降低人工行政成本。

> **部署環境**：Raspberry Pi 5（生產伺服器）+ Apache / Nginx + PHP-FPM  
> **GitHub**：[jerry200176-png/AllTrue_System](https://github.com/jerry200176-png/AllTrue_System)

---

## 目錄

- [專案功能](#專案功能)
- [角色與使用情境](#角色與使用情境)
- [技術架構](#技術架構)
- [目錄結構](#目錄結構)
- [本地開發快速開始](#本地開發快速開始)
- [部署方式](#部署方式)
- [GitHub 同步工作流程](#github-同步工作流程)
- [AI 開發工作流程（Engineering Workflow）](#ai-開發工作流程engineering-workflow)
- [重要文件索引](#重要文件索引)
- [⚠️ 安全警示（必讀）](#️-安全警示必讀)

---

## 專案功能

| 模組 | 說明 |
|---|---|
| **學生管理** | 建立學生資料、課程主約、加購堂數、歷程管理 |
| **智慧排課** | 固定週期排課、調課、補課、請假、教室與老師時段協調 |
| **出缺勤** | 櫃台點名、RFID 刷卡自動登記、缺勤補登、出勤紀錄查詢 |
| **財務管理** | 課程收費、剩餘堂數追蹤、帳單與繳費狀態、月結報表 |
| **學習評量** | 老師填寫評量、主任審核（approve/reject）、學習進度留存 |
| **家長入口** | 家長可查課程排程、評量內容、繳費狀態與 LINE 通知 |
| **教師管理** | 老師帳號、分校權限、週課表、代課管理 |
| **校區管理** | 多分校隔離、教室管理、主任帳號管理 |

---

## 角色與使用情境

| 角色 | 主要使用裝置 | 主要操作 |
|---|---|---|
| **主任 / 行政** | 電腦（桌面優先） | 建立學生與課程、統整排課、追蹤繳費、審核評量、查看營運總覽 |
| **老師** | 平板 / 手機 | 查看個人課表、填寫學習評量、處理上課與補課紀錄 |
| **櫃台** | 電腦 / 平板 | 處理到班點名、RFID 綁定、缺勤補登與家長通知 |
| **家長** | 手機 | 查詢孩子課程進度、評量內容與繳費狀態 |

---

## 技術架構

```
前端（Vue 3 + Vite）
  ├── 頁面：frontend/src/pages/*.vue
  ├── API Client：frontend/src/supabase.js（模擬 Supabase 介面，實際打 Laravel API）
  └── build → backend/public（SPA 靜態資產）

後端（Laravel 8 / PHP 8+）
  ├── API Routes：backend/routes/api.php（/api/v1/*）
  ├── Controllers：backend/app/Http/Controllers/
  ├── Models：backend/app/Models/（PascalCase 資料表命名，歷史遺留）
  └── Auth：Laravel Sanctum + localStorage Bearer token

資料庫：MySQL（生產主用）
  ├── 核心表：Student, StudentClass, ClassSession, StudentSingIn（注意拼字）
  ├── 財務表：Invoice, InvoiceItem, Payment
  ├── 評量表：LearningRecord
  └── 其他：User, Campus, Teacher, rooms, schedules …

部署
  ├── 伺服器：Raspberry Pi 5（/home/admin/）
  ├── Web Server：Apache / Nginx + PHP-FPM
  ├── 前端 build → backend/public（npm run deploy）
  └── CI：GitHub Actions（.github/workflows/）
```

**前端主要頁面：**

| 頁面 | 功能 |
|---|---|
| `DirectorDashboard.vue` | 主任總覽（繳費提醒、今日排課、待審評量） |
| `SmartCalendar.vue` | 智慧排課日曆 |
| `StudentsList.vue` | 學生與課程管理 |
| `AttendancePage.vue` | 出缺勤管理 |
| `LearningRecordsPage.vue` | 學習評量 |
| `BillingList.vue` | 帳單與繳費 |
| `ParentPortal.vue` | 家長入口 |

---

## 目錄結構

```
/home/admin/
├── frontend/          # Vue 3 + Vite 前端
├── backend/           # Laravel 8 後端（含 public/ 存放前端 build）
├── docs/              # 所有技術文件與操作手冊
├── scripts/           # 維運腳本（備份、部署、git sync）
├── docker/            # Docker 設定（開發用）
├── docker-compose.yml # 容器化開發環境
├── .cursorrules       # AI 開發規則（技術棧摘要 + Engineering Workflow）
└── README.md          # 本文件
```

---

## 本地開發快速開始

### 前端

```bash
cd frontend
npm install
npm run dev       # 開發伺服器 http://localhost:5173
```

### 後端

```bash
cd backend
cp .env.example .env
# 填入 DB_DATABASE, DB_USERNAME, DB_PASSWORD …
composer install
php artisan key:generate
php artisan migrate
php artisan serve  # http://localhost:8000
```

### Docker（一鍵啟動）

```bash
docker-compose up -d
```

---

## 部署方式

### 前端部署（同步到 backend/public）

```bash
cd frontend
npm run deploy    # build + 複製到 backend/public
```

### 生產環境（Raspberry Pi 遠端部署）

```bash
./scripts/git-sync.sh "feat: 你的 commit 訊息"
# → 自動 stage all + commit + push 到 origin/jerry-sync-main
```

詳細步驟見 `docs/DEPLOYMENT.md` 與 `docs/deploy-raspberry-pi.md`。

---

## GitHub 同步工作流程

- **生產主分支（遠端）**：`origin/jerry-sync-main`
- **本機工作分支**：`main`

### 一般日常更新（最常用）

```bash
./scripts/git-sync.sh "feat: 這次改動內容"
```

### 手動 git 流程

```bash
git add .
git commit -m "feat: 說明"
git push origin main:jerry-sync-main
```

詳細 SOP 見 `docs/GITHUB_SYNC_WORKFLOW.md`。

---

## AI 開發工作流程（Engineering Workflow）

本專案採用**角色分工的多 Phase 開發流程**，任何新功能或修改都必須走以下流程：

```
[PLAN] Product Manager → 14 節 PRD
    ↓ 批准
[ARCH] Tech Lead → 技術設計文件（API 合約、DB schema、模組依賴）
    ↓ 批准（依情況進入 [UX] 和/或 [DBA]）
[DEV] 全端工程師 → 後端 API + 前端 Vue 實作
    ↓ 批准
[TEST] QA 工程師 → Pest 測試（Feature / Unit / Regression）
    ↓ 批准
[SEC] Security Engineer → OWASP / STRIDE 資安審查
    ↓ 批准
[REVIEW] Staff Engineer → Code Review
    ↓ LGTM
[DOCS] Technical Writer → CHANGELOG + 操作手冊
    ↓ 完成
[OPS] DevOps Engineer → 部署 + Health Check + Smoke Test
```

**獨立角色（隨時可呼叫，不走主線）：**

| 角色 | 呼叫時機 |
|---|---|
| `[BUG]` Bug Investigator → Fixer | 有 bug 需要調查與修復（B1 偵查 → B2 修復） |
| `[IT]` IT Administrator | Raspberry Pi 異常、SSL、備份、磁碟空間 |
| `[SRE]` Site Reliability Engineer | 系統變慢、刷卡失敗率上升、效能分析 |
| `[LEGAL]` Compliance Officer | 個資法合規、隱私政策 |
| `[DATA]` BI / Analytics Engineer | 統計報表、Dashboard 指標設計 |

> 完整 Phase 規格與每個角色的禁止事項詳見 `.cursorrules`。

---

## 重要文件索引

| 文件 | 說明 |
|---|---|
| `docs/CHANGELOG.md` | 功能異動歷程 |
| `docs/AI_REGRESSION_LESSONS.md` | AI 已踩過的坑（**改動前必讀**） |
| `docs/DANGEROUS_OPERATIONS.md` | 高風險操作清單與 SOP |
| `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` | 繳費提醒規則（勿擅自修改） |
| `docs/GITHUB_SYNC_WORKFLOW.md` | GitHub 協作 SOP |
| `docs/DEPLOYMENT.md` | 部署步驟 |
| `docs/deploy-raspberry-pi.md` | Pi 部署指南 |
| `docs/OPERATIONS_RUNBOOK.md` | 日常維運手冊 |
| `docs/SECURITY_HARDENING_MIRAI.md` | 安全加固紀錄 |
| `docs/使用說明_主任與超級管理員.md` | 使用者操作手冊（中文） |
| `docs/TECH_DEBT.md` | 技術債清單 |
| `docs/FAQ.md` | 常見問題 |

---

## ⚠️ 安全警示（必讀）

本專案曾因以下操作發生生產事故，**任何人（含 AI）在執行前必須先閱讀 `docs/DANGEROUS_OPERATIONS.md`**：

| 禁止事項 | 原因 |
|---|---|
| `git push --force origin main` | 會觸發 CI deploy，覆蓋生產 `.env` / routes |
| 在 `/home/admin/backend/` 執行 `php artisan test` | `RefreshDatabase` 會清空生產資料庫 |
| 在生產後端執行 `config:clear` / `route:clear` 用於 debug | 造成 session / auth 配置錯亂 |
| 直接修改 `backend/.env` | 影響生產認證與資料庫連線 |

> **要跑測試**：`cp -r /home/admin/backend /tmp/backend-test` → 改 `.env` → 在 `/tmp` 跑  
> **CI 問題**：改 `.github/workflows/ci.yml` 後 push，看 GitHub Actions log

---

*最後更新：2026-04-22*
