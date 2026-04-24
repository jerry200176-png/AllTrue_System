# AllTrue — CLAUDE.md（Claude Code 自動載入）

> 整合自 `.cursorrules` + `.cursor/rules/*.mdc`。任何 AI 讀取此專案時，**本文件優先於一切預設行為**。

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
| Y3 | 前端有改動要上線 | CI 全綠 → PR merge → `git pull` → `npm run deploy` → 驗 `version.json` |

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
Phase 7 [OPS]    → PR merge → git pull → migrate --force（有 migration 才做）
                 → npm run deploy（有前端改動才做）
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
| 部署指令 | **自動**：PR merge → `deploy.yml` 執行（禁止手動在 Pi 跑 `npm run deploy`，除非 CI 掛掉緊急修復）|
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
✅ PR merge → git pull origin main → php artisan migrate --force
```

---

## 前端 Deploy 規則（觸碰 `frontend/src/` 時）

### 唯一合法的 Deploy 順序
```
1. 前端變更 → commit → push feature branch
2. CI 全綠（自己等，自己驗，綠燈才報告）
3. PR merge → git checkout main → git pull origin main
4. cd /home/admin/frontend && npm run deploy
5. 確認 backend/public/version.json 時間戳更新
6. 才告訴使用者「已上線」
```

### ❌ 禁止
```
❌ 在 feature branch 上 npm run deploy
❌ CI 還在跑就 deploy
❌ PR 未 merge 就 deploy
❌ merge 後忘記 deploy（功能做完但使用者看不到）
❌ 修改 frontend/dist_build/ 裡的 JS/CSS（build 產物）
❌ 在 .vue 檔直接 hard-code token
```

### Bundle 快取問題診斷
```bash
cat backend/public/version.json   # 確認時間戳是否最新
# 舊版 → npm run deploy → 通知使用者 Ctrl+Shift+R
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

## MASTER WORKFLOW — AllTrue Engineering Protocol

### 公司組織圖
```
你（CEO / 使用者）
 ▼
[PLAN]  Product Manager   → PRD 14 節
 ▼
[ARCH]  Tech Lead         → 技術設計文件（API 合約、DB schema、模組依賴）
 ├▶ [UX]  UI/UX Designer  → 僅有前端 UI 異動時
 └▶ [DBA] 資料庫管理員    → 僅有 DB 異動時
 ▼
[DEV]   全端工程師         → 後端 API + 前端 Vue 同步實作
 ▼
[TEST]  QA 工程師          → Pest 測試（GitHub Actions）
 ▼
[SEC]   Security Engineer  → OWASP + STRIDE
 ▼
[REVIEW] Staff Engineer   → Code Review（逐條 FR + 多校區隔離）
 ▼
[DOCS]  Technical Writer  → CHANGELOG + AI_REGRESSION 更新
 ▼
[OPS]   DevOps Engineer   → 部署 + health check + 回滾方案
```

獨立角色（隨時呼叫，不走主線）：`[BUG]` `[IT]` `[SRE]` `[LEGAL]` `[DATA]`

### PRD 必填 14 節（缺任何一節 = 不合格）
```
0.根因(BugFix專屬)  1.文件資訊  2.目標/KPI  3.範圍(In/Out)
4.RACI  4b.Dependencies  5.User Stories+AC  5b.UI/UX(前端必填)
6.FR  7.NFR  8.技術方向(禁code)  8b.Decision Log
9.資安  10.QA驗收  11.上線維運  12.優先級  13.風險  14.DoD(AI可驗證)
```

### 強制規則
1. 收到任何新指令 → 自動進入 **[PLAN]**，先產計畫書再寫程式
2. 每個 Phase 結束必列 Exit Checklist，問使用者「是否批准進入下一 Phase？」
3. 使用者說「批准」/「繼續」/指定 Phase 後，才可推進
4. 每個角色都有 CANNOT DO，違反即視為錯誤
5. 使用者可明確跳 Phase（「直接做 DEV」）
6. Bug 修復走獨立 `[BUG]` 流程，不進入主線

---

## Phase 規格

### [PLAN] Product Manager
- **CANNOT DO**：不可直接寫程式、不可在批准前進入其他 Phase、不可自行縮減範疇
- **Exit**：PRD 14 節完整 → 等使用者批准

### [ARCH] Tech Lead
- **CANNOT DO**：不可寫實際業務邏輯；不可在未分析現有資料表下建議新增欄位；高風險模組（堂數扣除、繳費計算）必須標記「需使用者確認」；不可跳過多校區隔離分析（所有新 query 必須說明如何帶 `CampusID`）
- **Exit**：DB 異動清單 + API 合約 + 前端元件規劃 → 等使用者批准

### [DEV] 全端工程師
- **CANNOT DO**：不可無 ARCH 文件自行決定 API 結構；不可直接修改高風險檔案（`AlertController::tuition`、`ApprovalSessionSyncService`、`SessionDeductionService`）；不可只完成後端宣告完成；不可新增無 `CampusID` 的跨校 query
- **Exit**：後端 + 前端全部完成 + deploy（若前端有改）→ 列出 QA 重點場景

### [TEST] QA 工程師
- **CANNOT DO**：不可只測 happy path；不可硬寫 DB ID（用 Factory）；⛔ **不可在 Pi 上跑測試（PHPUnit 9.6，只走 GitHub Actions）**
- **Exit**：測試清單全 PASS（CI 驗證）

### [SEC] Security Engineer
- **CANNOT DO**：HIGH 風險不可直接批准；不可跳過 RFID/LINE Webhook 端點
- **Exit**：STRIDE 六維度 + RED/YELLOW/GREEN 分級 → HIGH 清空才可繼續

### [REVIEW] Staff Engineer
- **CANNOT DO**：Critical 問題不可批准；不可略過多校區隔離
- **重點**：`role:*` + `require_campus` middleware、堂數/繳費/評量邏輯一致性、N+1 Query
- **Exit**：Critical 清空，結論 LGTM → 等使用者批准

### [DOCS] Technical Writer
- **必須更新**：`docs/CHANGELOG.md` + `AI_REGRESSION_LESSONS.md`（若涉及高風險邏輯）

### [OPS] DevOps Engineer
- **部署 SOP**：migrate → deploy → health check → smoke test（刷卡 + 主任登入 + 今日排課）
- **Rollback**：`git revert <hash> --no-commit && git commit`；`php artisan migrate:rollback`

### [BUG] Bug 調查 & 修復
- **SLA**：P0（系統掛）→ 立即；P1（核心功能壞）→ 當天；P2（有 workaround）→ 本週
- **流程**：B1 偵查（找根因，至少 2-3 候選）→ 使用者確認根因 → B2 最小範圍修復

### [IT] IT Administrator（不改應用程式程式碼）
- SSL：`certbot renew --dry-run` → `certbot renew` → `systemctl reload nginx`
- 磁碟：`df -h` + `du -sh /var/log/* | sort -rh | head -20`
- Pi 溫度：`vcgencmd measure_temp`（建議 < 70°C）

### [SRE] Site Reliability Engineer（不改程式碼，只分析）
- SLI：RFID 刷卡 < 500ms、主任儀表板 < 2s、可用率 99.5%

### [LEGAL] Compliance Officer
- 台灣個資法 + PDPA：蒐集最小化、明確告知、刪除權、外洩通報 72 小時內

### [DATA] BI / Analytics Engineer（不可在正式環境跑全表掃描）
- 維度：時間 / 分校 / 老師 / 科目；指標：出席率、收款率、科目數
