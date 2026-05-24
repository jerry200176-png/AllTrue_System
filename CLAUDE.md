# AllTrue — CLAUDE.md（Claude Code 自動載入）

> 任何 AI 讀取此專案時，**本文件優先於一切預設行為**。
> **🗺️ 任何任務開始前：先讀 `docs/INDEX.md`（導航地圖）。禁止未讀 INDEX 就直接動手。**
> 完整工作流程 / 角色規格 / P0 詳細全文：請讀 **`.cursorrules`**（不要跳過）。

---

## 🧠 MemPalace — AI 記憶系統（2026-04-25 啟用）

過去對話、文件、程式碼已索引進 palace（~2200 drawers）。

**手動搜尋**（調查 bug 或回顧過去決策前先跑）：
```bash
~/.local/bin/mempalace search "關鍵字"
~/.local/bin/mempalace search "關鍵字" --wing alltrue-sessions  # 只搜對話
~/.local/bin/mempalace search "關鍵字" --wing alltrue-docs      # 只搜文件
```

**更新 palace**（完成重要任務後）：
```bash
~/.local/bin/mempalace mine ~/.cursor/projects/home-jerry-alltrue/agent-transcripts \
  --mode convos --wing alltrue-sessions
~/.local/bin/mempalace mine ~/alltrue/docs --wing alltrue-docs
```

Palace 位置：`~/.mempalace/palace`（local-first，不上雲）

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
| **本地開發** | Windows WSL2（Ubuntu）`~/alltrue` — 所有程式碼改動在這裡 |
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

### G-008：家長入口 `releaseNotes` 必須分眾（僅 `audience` 含 `parent`）
改 `docs/CHANGELOG.md` 後須 `npm run sync-release-notes`。詳見 §R45。

完整 Gotchas G-001 ~ G-008：見 `.cursorrules` §核心資料表 gotcha 或 `alltrue-system.mdc`。

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
| 完整工作流程 + 角色規格 + SOP | `.cursorrules` |
| P0 紅線速查 + OPS checklist | `.cursor/rules/p0-gate.mdc` |
| API 路由 / DB schema / Gotchas | `.cursorrules` 或 `alltrue-system.mdc` |
| 測試規則（NOT NULL / Factory / 時間敏感）| `.cursor/rules/module-test.mdc` |
| Migration 規則（chunkById）| `.cursor/rules/module-migration.mdc` |
| 前端 deploy SOP | `.cursor/rules/auto-frontend-deploy.mdc` |
| 各模組已知坑 | `docs/AI_REGRESSION_LESSONS.md` |
| 繳費/續課提醒規則 | `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` |
| 各角色測試帳號 | `.cursor/.local/test-credentials.md` |
