# AllTrue — CLAUDE.md（Claude Code 自動載入）

> 整合自 `.cursorrules` + `.cursor/rules/*.mdc`。任何 AI 讀取此專案時，**本文件優先於一切預設行為**。
> **🗺️ 任何任務開始前：先讀 `docs/INDEX.md`（導航地圖），再依指引只讀對應章節。禁止未讀 INDEX 就直接動手。**

---

## 🧠 MemPalace — AI 記憶系統（2026-04-25 啟用）

過去對話、文件、程式碼已索引進 palace（~2200 drawers）。Cursor hooks 已設定，每次 `sessionStart` 自動注入相關記憶。

**手動搜尋**（調查 bug 或回顧過去決策前先跑）：
```bash
~/.local/bin/mempalace search "關鍵字"          # 語意搜尋全 palace
~/.local/bin/mempalace search "關鍵字" --wing alltrue-sessions  # 只搜過去對話
~/.local/bin/mempalace search "關鍵字" --wing alltrue-docs      # 只搜文件
```

**更新 palace**（完成重要任務後）：
```bash
# Mine 最新對話（把本次決策存進 palace）
~/.local/bin/mempalace mine ~/.cursor/projects/home-jerry-alltrue/agent-transcripts \
  --mode convos --wing alltrue-sessions

# Mine 文件（有更新 docs/ 後）
~/.local/bin/mempalace mine ~/alltrue/docs --wing alltrue-docs
```

Palace 位置：`~/.mempalace/palace`（local-first，不上雲）

---

## ⛔ 5 條紅線（違反 = P0 故障，零容忍）

| # | 觸發情境 | 強制行動 |
|---|---------|---------|
| R1 | 要修改 `/home/admin/` 內**既有** `.php` / `.vue` / config 檔 | ❌ 停。先寫測試 → CI 綠 → 才改。新增 migration / test / Export class 例外 |
| R2 | 要在 Pi 執行任何含 `test` / `phpunit` / `config:clear` 的指令 | ❌ 停。測試只走 GitHub Actions |
| R3 | 要執行 `git push --force` / `-f` / 直接 push main | ❌ 停。一律推 feature branch，等 PR merge |
| R4 | 要還原出錯的檔案 | ✅ `git checkout HEAD -- <file>` **完整**還原，禁止部分還原 |
| R5 | 要執行 `php artisan migrate` | ✅ PR merge 後才可 `migrate --force` |

## ⚠️ 3 條黃線（違反 = CI 反覆失敗）

| # | 觸發情境 | 強制行動 |
|---|---------|---------|
| Y1 | 要在測試插入任何 DB 資料 | 先查 NOT NULL 欄位。`Campus` 用 Factory。`schedules` 記 **S.D.B.**（student_id, day_of_week, branch_id）|
| Y2 | 要在測試用「今日日期」作為 future session | `start_time` 設 `23:00`，避免 `isEndedAtCreateTime=true` |
| Y3 | 前端有改動要上線 | CI 全綠 → PR merge → 等 `deploy.yml` 自動部署 → 驗 health / `version.json` |

---

## ⛔⛔⛔ 生產事故紀錄（不是假設，全部真實發生）⛔⛔⛔

| 事故 | 日期 | 操作 | 後果 |
|---|---|---|---|
| **A** | 2026-04-21 | `git push --force origin main` 觸發 `deploy.yml` | 生產 `.env`/routes/`.htaccess` 被覆蓋，全站 15 分鐘 |
| **B** | 2026-04-22 | 在 Pi 執行 `php artisan config:clear` | session/auth 錯亂，全站 5 分鐘 401 |
| **C ⛔最高** | 2026-04-22 | 在 Pi 跑 `php artisan test` | `RefreshDatabase` 清空 production DB，Student 395→1，ClassSession 5446→0，1h42m 資料損失 |
| **D** | 2026-04-23 | 未經 CI 直接改 `public/.htaccess`，後又部分還原 | 前端全站變英文，部分還原造成第二次破壞 |
| **E** | 2026-04-23 | 在 production 跑 `vendor/bin/phpunit` | 污染 `storage/framework/cache/` owner，所有 cache API 全 500，全站 20 分鐘 |
| **F** | 2026-04-23 | 無測試直接改 production `SwipeRfidController.php` | 流程違規（無 downtime，但違反 R1） |

---

## 完整 SOP（Phase 1→7，不可跳步）

### 任務類型判斷
```
收到新功能需求  → [PLAN] 寫 PRD（14 節）
收到 Bug 報告   → [BUG] 寫 Bug Fix Plan
收到技術債清償  → 輕量流程：ARCH評估→DEV→TEST→REVIEW
```

### 執行順序
```
Phase 1 [PLAN]   → 存 .cursor/plans/<slug>_<date>.md
Phase 2 [DEV]    → git checkout -b <type>/<slug>
                 → 寫測試(RED) → push → CI RED 確認
                 → 改 production code → push → CI GREEN（自己等，自己驗）
Phase 3 [TEST]   → 跑所有 AC，自動驗收
Phase 4 [SEC]    → STRIDE 審查（涉及 auth/PII/RFID/webhook 才做）
Phase 5 [REVIEW] → 逐條對照 FR；Minor 問題登記 docs/TECH_DEBT.md
Phase 6 [DOCS]   → 更新 docs/CHANGELOG.md
Phase 7 [OPS]    → PR merge → 等 `deploy.yml`（有 deployable diff 才跑）
                 → migration / frontend deploy 由 `deploy.yml` 自動處理
                 → curl health check → 才回報使用者「完成」
```

### CI 自驗規則（不要叫使用者去看）
```bash
gh run list --limit 1       # 看最新 run ID
gh run view <run_id>        # 等到 completed
# success → 繼續下一步
# failure → 自己看 log 修，不要說「請你去 GitHub 看」
```

### Branch 命名規範
```
feat/<slug>          新功能
fix/<slug>           Bug 修復
td-batch<N>-<slug>   技術債清償
chore/<slug>         文件/規則/維護
```

### OPS 驗收清單（Phase 7 必做）
```bash
# 1. Health check
curl -sk https://daan.lifenet.com.tw/api/v1/health | python3 -m json.tool
# 必須看到 {"status":"ok",...}

# 2. 前端版本確認（有 deploy 才做）
cat /home/admin/backend/public/version.json   # 時間戳應為剛才的時間

# 3. Migration 確認（有 migration 才做）
cd /home/admin/backend && php artisan migrate:status | tail -5
```

### 緊急備份（高風險操作前必做）
```bash
TS=$(date '+%Y-%m-%d_%H%M%S')
mysqldump -h 127.0.0.1 -u admin -p"$(grep DB_PASSWORD /home/admin/backend/.env | cut -d= -f2)" \
  --single-transaction AllTrue | gzip > /home/admin/backups/emergency/db_pre_${TS}.sql.gz
```

---

## 開發環境（2026-04-24 起正式啟用）

| 環境 | 說明 |
|---|---|
| **本地開發** | Windows WSL2（Ubuntu）`~/alltrue` — 所有程式碼改動在這裡 |
| **生產伺服器** | Raspberry Pi `/home/admin` — ⛔ 禁止直接 SSH 進去改程式碼 |
| **部署方式** | WSL2 push → GitHub CI 通過 → `deploy.yml` 自動 SSH 部署到 Pi |

**⛔ 新增紅線 R6**：禁止 SSH 到 Pi 直接編輯任何程式碼。所有改動必須走 WSL2 → feature branch → PR → CI → auto-deploy。

---

## 技術棧快速參照

| 項目 | 說明 |
|---|---|
| 前端 | Vue 3.4 + Vite 5，`<script setup>`，**無 Vue Router**（用 `active` ref 切頁） |
| 後端 | Laravel 8.x + PHP 8+，MySQL（database: `AllTrue`） |
| 認證 token | `localStorage.alltrue_session`（Bearer）⚠️ `supabase.js` 是**自製 client**，非真實 Supabase |
| 分校 | `currentBranch` ref，持久化 `localStorage.app_branch`；後端 `require_campus` middleware |
| 部署指令 | **自動**：PR merge → `deploy.yml` 執行（docs-only merge 跳過；禁止手動在 Pi 跑 `npm run deploy`，除非 CI 掛掉緊急修復）|
| 刷卡 | RFID → `POST /api/v1/swipe-rfid` |
| 通知 | LINE Webhook / LINE Login |
| 測試 | PHPUnit 9.6（⛔ **只能在 GitHub Actions 跑，絕不在 Pi 上跑**） |

**四間分校**：興隆、新店、大安、木柵

### 改動前必讀
- `docs/AI_REGRESSION_LESSONS.md`：已踩過的缺口（調課後評量作廢、繳費提醒漏月結等）
- `docs/DIRECTOR_PAYMENT_ALERT_RULES.md`：繳費/續課提醒規則，**勿擅自改條件**
- `.cursor/.local/test-credentials.md`：各角色測試帳號 + 登入 SOP

---

## 核心資料表 Gotchas（bug 偵查前必讀）

### G-001：Teacher.id === User.id（同一人，兩張表 ID 相同）
`StudentClass.TeacherID`、`StudentSingIn.TeacherID` 存的都是 `User.id`。
`auth_teacher_id` = `User.id`（`AttachAuthUser.php`）。查 `Teacher` 或 `User` 用同一個 ID 都能命中。

### G-002：TeacherID = NULL → 老師查不到該記錄
`AttendanceController::index()` 過濾：`WHERE si.TeacherID = auth_teacher_id`。
`NULL = X` → false → 記錄從老師視角消失（director 可見，用 CampusID 過濾）。
修補 SQL：
```sql
UPDATE StudentSingIn si
  JOIN ClassSession cs ON ...
  JOIN StudentClass sc ON ...
  SET si.TeacherID = sc.TeacherID
  WHERE si.TeacherID IS NULL AND sc.TeacherID IS NOT NULL
```

### G-003：ClassSession.Status 不由刷卡自動更新（2026-04-23 前歷史記錄）
PR #23 修復只對新刷卡有效，歷史記錄需手動 UPDATE。
`AttendanceEffectsService::applySessionStatus()` 有 guard：只更新 `scheduled`，不覆寫人工決策。

### G-004：SwipeRfidController 時間窗口 hardcoded 30min
不讀 `Campus.SwipeWindowMinutes`（TD 待清償）。

### G-005：Memo 欄位識別記錄來源
- `'swipe-rfid'`：刷卡且比對到 StudentClass
- `'self_study'`：刷卡但無匹配課程
- `'presence-window'`：刷退時 backfill 補建
- `NULL`：老師手動點名或 legacy

### G-007：智慧行事曆週檢視必須走 occurrence resolver，不可分散 if 合併

三個資料源（`StudentClass` 常態規則、`ClassSession` 實際堂次、`schedules` 例外）若在 Vue component 內分段 `if` 合併，必然出現「base 先跳過 + exception 又跳過」的雙殺，導致課消失，或同一堂同時掛兩位老師（吳艾潼 SC#382 / 2026-05-10 事故）。

- **唯一合法路徑**：`SmartCalendar.vue` 週檢視透過 `frontend/src/lib/calendarOccurrenceMerge.js` 的 `mergeWeekCalendarOccurrences()` 產生單一 occurrence list。
- **Occurrence 身分識別**：同一 `ClassSession.id` 只輸出一張卡；`scheduled` 例外若匹配同一堂只做 `teacher_id` overlay，不另建第二張卡。
- **API 合約**：`GET /api/v1/class-sessions` 必須回傳 `substitute_teacher_id`（非 null 時表示代課老師），前端 `classSessionsApi.js` `normalizeClassSessionsPayload` 已解析此欄位。
- **回歸測試**：任何修改都必須先跑 `npm run test:calendar`，覆蓋「不重複、不消失、leave 不被遮蔽」三種 fixture。

### G-008：家長入口 `releaseNotes` 必須分眾（僅 `audience` 含 `parent`）

見 `docs/AI_REGRESSION_LESSONS.md` §R45；`npm run sync-release-notes` 會從 `CHANGELOG.md` 重產 `releaseNotes.generated.js`。

### G-006：GitHub Actions SSH Secrets 格式嚴格，含 `@` 就爆
- `PI_SSH_USER` / `PI_USER`：只能填 `admin`，含 `@hostname` → sshd 收到 `admin@admin` → Invalid user
- `PI_SSH_HOST` / `PI_HOST`：只能填 `pi.lifenet.com.tw`，含 `user@` → 同上
- `PI_SSH_KEY`：必須是 `base64 -w0 /home/admin/.ssh/rpi_actions_deploy` 的輸出
- SSH deploy 失敗 → 第一步看 `sudo journalctl -u ssh`，比 verbose SSH log 更快指出 username 格式錯
- 詳見 `docs/OPERATIONS_RUNBOOK.md` §Pi authorized_keys + AI_REGRESSION_LESSONS R18

### 命名坑（必記）
- `StudentSingIn`（Sing ≠ Sign，歷史 typo）→ Model 須 `protected $table = 'StudentSingIn'`
- `schedules`（snake_case 新表）vs `StudentClass`（PascalCase 舊表）：兩套並存，勿混用
- `UserCampus.Approved` ≠ `User.status`：老師待審核需同時查兩欄
- `LearningRecord` `Status=approved` 才觸發 `RemainingSessions` 扣減
- `student_course_id`（前端）= `StudentClassID`（後端）

---

## API 路由 (`/api/v1/`)

### 公開（無認證）
`POST auth/login` · `POST auth/register` · `GET branches`
`POST swipe-rfid` · `POST directors/register`
`POST parent/login` · `POST parent/login-line` · `POST line/webhook`

### director + teacher 共用
`GET/PUT me` · `GET students` · `GET/POST/PUT/DELETE student-classes`
`GET/POST/PUT/DELETE schedules` · `GET/POST learning-records`
`POST learning-records/{id}/approve` · `POST learning-records/reschedule-session`
`GET/POST attendance` · `GET/POST/PUT/DELETE rooms`

### director 限定
`POST students` · `PUT students/{id}` · `POST/GET invoices`
`GET finance/summary|revenue|outstanding|teacher-payroll|subject-units`
`GET/POST/DELETE pending-swipes` · `GET/POST profiles` · `GET campuses`

---

## 商業邏輯速查

### 科目數加權規則
一對一 ×1.5、一對二 ×0.75、一對三 ×0.5、輔導 ×0.5
科目數 = 各老師加權總分 ÷ 8

### 補課空檔演算法（CourseManagement.vue `fetchMakeupSlots`）
容量：`one_on_one=1, one_on_two=2, one_on_three=3, tutoring=4`
掃描 09:00-21:00，每 30 分鐘格子 `count < capacity` 的連續足夠長區間 → 補課候選

---

## 測試規則（觸碰 `backend/tests/` 時）

### 寫測試前：查 NOT NULL 欄位（§TEST-001，已犯 4+ 次）
```sql
SELECT COLUMN_NAME FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='AllTrue' AND TABLE_NAME='<表名>'
  AND IS_NULLABLE='NO' AND EXTRA NOT LIKE '%auto_increment%'
  AND COLUMN_DEFAULT IS NULL;
```

### 高頻地雷表
| 表名 | 地雷必填欄位 |
|------|------------|
| `Campus` | 10+ 個 NOT NULL → ✅ 用 `CampusFactory::new()->create()` |
| `schedules` | **S.D.B.**：`student_id`, `day_of_week`, `branch_id` |
| `StudentClass` | `StudentID`, `TeacherID`, `GradeID`, `SubjectID`, `Rate` |
| `Student` | `name`, `CampusID`, `SchoolName`（≤32 chars）|

### 時間敏感測試（§TEST-005）
```php
// ❌ 危險：CI 在 18:00+ 後跑，今日 future session 被 isEndedAtCreateTime=true
'start_time' => '16:00', 'duration_minutes' => 120

// ✅ 安全：23:30 才下課，CI 全天不觸發
'start_time' => '23:00', 'duration_minutes' => 30
```
**規則**：測試中含「今日日期」的 future session，`start_time` 一律用 `23:00`。

### chunkById 驗證（§MIGRATION-002）
backfill/migration 邏輯測試必須包含 chunk 邊界驗證（插入超過 chunk size 的資料，驗證 0 筆留在原狀態）。

---

## Migration 規則（觸碰 `database/migrations/` 時）

### M1. `chunk()` 改 Status 欄位 → 必須用 `chunkById()`
```php
// ❌ 危險：chunk() 用 OFFSET，修改 WHERE 條件欄位後 OFFSET 漂移，跳過 row
// ✅ 安全：chunkById() 用 WHERE id > last_id，mutation 安全
```
歷史血案：2026-04-23 backfill 用 chunk()，600 筆 pending_review 未被修正。

### M2. 新增帶 DEFAULT 的欄位 → 同 PR 必須附 backfill migration
MySQL 新增帶 DEFAULT 欄位會自動回填所有舊記錄，必須搭配 chunkById backfill 修正歷史資料。

### M3. Migration 時機
```
❌ CI 還在跑就 migrate
❌ 直接在 feature branch 跑 migrate --force
✅ PR merge → `deploy.yml` 自動偵測 pending migration → 備份 → php artisan migrate --force
```

---

## 前端 Deploy 規則（觸碰 `frontend/src/` 時）

### 唯一合法的 Deploy 順序
```
1. 前端變更 → commit → push feature branch
2. CI 全綠（自己等，自己驗，綠燈才報告）
3. PR merge → `deploy.yml` 自動部署
4. 確認 deploy workflow success
5. 確認 backend/public/version.json 時間戳更新（前端有變更時）
6. health check + smoke test 通過後才告訴使用者「已上線」
```

### ❌ 禁止
```
❌ 在 feature branch 上 npm run deploy
❌ CI 還在跑就 deploy
❌ PR 未 merge 就 deploy
❌ merge 後不看 deploy.yml / health check 就回報完成
❌ 修改 frontend/dist_build/ 裡的 JS/CSS（build 產物）
❌ 在 .vue 檔直接 hard-code token
```

### Bundle 快取問題診斷
```bash
cat backend/public/version.json   # 確認時間戳是否最新
# 舊版 → 先看 deploy.yml log；必要時 hotfix PR 重新觸發部署 → 通知使用者 Ctrl+Shift+R
```

### 出缺勤模組特殊規則（AttendancePage.vue）
```
自修（self_study）：DB 沒有 ENUM 值，用 Memo='self_study' + Status='present' 表示
編輯：PATCH { status: 'self_study' } → 後端設 Memo='self_study', Status='present'
```
老師出勤狀態標籤：`normal`=正常、`late`=遲到、`source_only`=行政出勤、`pending_review`=系統待確認（異常）、`adjusted`=已調整

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

## MASTER WORKFLOW 與 Phase 規格（避免重複膨脹）

**單一權威來源**：組織圖、PRD 14 節、各角色 **CANNOT DO／Exit**、P0 紅線全文 — 請讀 **`.cursorrules`**（Always Applied，會隨 repo 更新）。  
本檔 **不重複** 貼上該長文，以免與 `.cursorrules` 分叉。

- **導航**：`docs/INDEX.md` → 依任務類型跳到對應 `.cursor/rules/*.mdc` 或長文章節。
- **精簡協作語意**：`AGENTS.md`（Risk Tier、Definition of Done、artifact handoff）。
- **若本檔與 `.cursorrules` 衝突**：以 **`.cursorrules` + INDEX 導航** 為準。
