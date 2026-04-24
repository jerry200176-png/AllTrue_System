# AI／工程師防再犯紀錄（必讀）

本檔記錄**已發生過的產品／實作缺口**，避免下次改壞或改漏。  
**任何 AI Agent 或新進開發者**：請與 `AGENTS.md` 的 First-read 順序一併閱讀；修改下列模組前**先對照本檔**。

> 詳細事故記錄（33 條）→ [AI_REGRESSION_LESSONS_ARCHIVE.md](AI_REGRESSION_LESSONS_ARCHIVE.md)

---

# ⛔⛔⛔ 開工前必讀摘要（30 秒讀完）⛔⛔⛔

## 紅線（⛔ 違反 = P0 故障，零容忍）

### R1. `/home/admin` 就是 production — 改檔案 = 改線上

```
/home/admin/backend/  ← nginx 直接 serve 的 document root
/home/admin/frontend/ ← npm run deploy 後 copy 到 backend/public/
```

- **feature branch 上修改既有 .php/.vue 檔案 = 即時影響 production**
- git checkout -b 不會隔離 working tree
- 唯一安全的寫入：**新增** test file（`tests/` 目錄）、新增 Export class、新增 migration
- 事故：§P0-005、§事故F

### R2. 禁止在 Pi 上跑測試（已發生 3 次 DB 清空事故）

```
❌ cd /home/admin/backend && php artisan test     ← 會 DROP production DB
❌ cd /home/admin/backend && vendor/bin/phpunit   ← 同上
❌ cd /home/admin/backend && php artisan config:clear  ← 全站 401
```

- 測試只能在 GitHub Actions CI 跑
- debug CI 失敗 → 改檔案 → push → 看 CI log，不要本機跑
- **包括「只跑單一測試檔」也禁止** — `RefreshDatabase` trait 不管你跑幾個檔案都會 DROP 全部表

### R3. CI 全綠前禁止改 production 既有檔案

```
✅ 正確順序：
   1. 新增 test file → push → CI RED
   2. 改 production code → push → CI GREEN
   3. PR merge → git checkout main → git pull
   4. 前端有改才 npm run deploy

❌ 錯誤：直接改 Controller/Route/Config 再補測試
❌ 錯誤：CI 還在跑就通知使用者
❌ 錯誤：PR 還沒 merge 就 npm run deploy
```

### R4. 還原必須完全還原

```
❌ 部分還原（「看起來有問題的先拿掉，其他留著」）→ 二次故障
✅ git checkout HEAD -- <file>  完整還原
```

### R5. 禁止 git push --force / 禁止直接 push main

- 見 `.cursor/rules/p0-never-force-push-and-deploy.mdc`
- `scripts/git-sync.sh` 已加入守門：在 main branch 執行直接 abort（2026-04-24）

### R6. deploy.yml 禁止用 `optimize:clear`，改用 `config:cache && route:cache`

```bash
# ❌ 危險：先清後不補，清除瞬間所有 API 可能 404/500
php artisan optimize:clear

# ✅ 正確：直接重建，無空白期
php artisan config:cache && php artisan route:cache
```

- 事故記錄：`optimize:clear` 包含 config + route + view cache 複合清除，清除後若有任何延遲就會導致 API 失敗（2026-04-24 修正）

### R7. Pi SSH public key 拒絕 → 根因是 home 目錄 775（group-writable）

```
症狀：Permission denied (publickey,password) — key 正確、格式正確，仍被拒
根因：/home/admin 權限 775 (admin:www-data)，SSH StrictModes 預設拒絕 group-writable home
修法：/etc/ssh/sshd_config 加入 StrictModes no（因為 www-data 需要寫入 home，無法改 755）
```

- **禁止**：看到 `Permission denied (publickey)` 就換 key — 先查 `ls -la /home/ | grep admin` 確認 home 權限
- **確認修復**：`sudo systemctl restart sshd` 後，GitHub Actions Deploy run 應無 `Permission denied`
- **Pi 環境特殊說明**：`/home/admin` 為 `admin:www-data 775`，是刻意設計讓 Apache 可寫；`StrictModes no` 是正確解法，不是暫時 workaround（2026-04-24）

### R8. deploy.yml 禁止 `composer install --no-dev`（Pi 環境）

```bash
# ❌ 造成 "Class NunoMaduro\Collision not found" → health check 500
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# ✅ 正確：Pi 已有 dev 套件，--no-dev 無法乾淨移除，保留 dev 套件不影響生產
composer install --no-interaction --prefer-dist --optimize-autoloader
```

- 原因：Pi 本機 vendor 有舊 dev 安裝，`--no-dev` 雖移除 Collision 的檔案，但 `php artisan optimize` bootstrap 時仍讀到舊 `packages.php` 中的 provider 登記，導致 class not found
- 結果：health check 失敗 → 自動 rollback 觸發 → 服務短暫中斷（2026-04-24 事故）

### R10. 家長入口登入：必須同時讀 `parent_phone` 與 `Phone`

```
UI「家長手機」欄 → 儲存到 Student.parent_phone
登入驗證         → 原本只讀 Student.Phone → 永遠不符 → 401
```

- **禁止**：只用 `$s->Phone` 驗證家長身份
- **正確**：`resolveContactPhone()` 優先 `parent_phone`，空才 fallback `Phone`
- 修復：PR #38，2026-04-24

### R9. deploy.yml `git pull` 改為 `git fetch + reset --hard`

```bash
# ❌ Pi 有本地 auto-commit（nightly tag、hourly sync），造成 divergent branches，git pull 卡住
git pull origin main

# ✅ 強制對齊 origin/main，忽略 Pi 本地 commit
git fetch origin main
git reset --hard origin/main
```

- Pi 上有 `nightly-backup.sh`、hourly auto-sync 等 cron 腳本會產生本地 commit；deploy 必須用 reset --hard 確保版本一致（2026-04-24）

---

## 黃線（⚠️ 違反 = CI 反覆失敗、浪費時間）

### Y1. 寫測試前先查 NOT NULL 欄位

```sql
SELECT COLUMN_NAME FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='AllTrue' AND TABLE_NAME='<表名>'
  AND IS_NULLABLE='NO' AND EXTRA NOT LIKE '%auto_increment%'
  AND COLUMN_DEFAULT IS NULL;
```

- 常漏：`schedules` 的 **S.D.B.**（`student_id`, `day_of_week`, `branch_id`）
- `Campus` 有 10+ 個 NOT NULL 欄位，用 `firstOrCreate` 別 raw insert

### Y2. 測試用 `now()` + `addHours(N)` 跨午夜 → CI 22:00+ TWN 之後失敗

```php
// ❌ 危險：now() + 2h 在 22:01 TWN = 00:01 次日，EndTime "00:01" < "22:01" → session 窗口失敗
$endTime = now()->addHours(2)->format('H:i:s');

// ✅ 正確：setUp() 固定 10:00 AM，EndTime 永遠落在中午
Carbon::setTestNow(Carbon::today()->setTime(10, 0)); // in setUp()
// tearDown() 記得 Carbon::setTestNow()
```

- 同理：`start_time='23:00'` + `duration_minutes=30` → EndTime=23:30；CI 在 23:31 跑 → 計數差一

### Y3. PhpSpreadsheet sheet 名稱不能為空

- 動態 sheet name 必須 guard 空字串，fallback 到 `"Sheet"` 或 `"老師{$id}"`

### Y4. 前端改了必須 build 才生效

- `npm run deploy` 只能在 **main branch + PR merged 後** 執行

### Y5. DB::table() raw queries 不受 Eloquent global scope 保護

```php
// ❌ campus 過濾漏掉 → 跨分校資料洩漏
// ✅ 使用 resolveEffectiveCampusIds() 統一驗證，空 auth_campus_ids 非 super_admin → 403
```

---

### R11. 家長入口評量科目名稱：LearningRecord.Subject 必須過 mapSubjectLabel
- 歷史資料中 `LearningRecord.Subject` 可能存 `English` / `英文課` 等非標準值
- **禁止**直接回傳 `$rec->Subject` 原始值給前端
- 修法：優先用 `resolveSubjectName(StudentClass)` 課程名稱（非 '課程' 時）；課程無科目時用 `mapSubjectLabel` 轉換；最後才用原始值
- `mapSubjectLabel` 需同時涵蓋英文 key (`English`) 和中文別名 (`英文課`)（PR #39，2026-04-24）

---

### R12. 出勤頁「已記錄出缺勤紀錄」只顯示今天 — 管理員無法查昨天是否已點名

- `fetchRecords` 寫死 `date: localTodayYmd()`，查不到過去日期
- 「待補點名」只顯示**尚未點名**的堂次；已點名的只能去行事曆看綠勾才知道
- **修法**：加 `recordsDate` ref + 日期選擇器，讓管理員可查詢指定日期的出勤紀錄（2026-04-24，fix/makeup-attendance-flow）
- **防再犯**：凡是「出勤確認」類 UI 新增任何日期查詢，都必須支援指定日期，不可寫死今天

---

### R13. ScheduleController::store 補課 schedule 不建立 ClassSession

- `POST /api/v1/schedules`（status=rescheduled 或 type=extra）只寫 `schedules`，不建 `ClassSession`
- 出勤（`GET /api/v1/class-sessions`）、評量（LearningRecordsPage）、待補點名 — 三處全部依賴 ClassSession，補課日完全不可見
- **修法**：`ScheduleController::store` 在建立補課 schedule 後加 `ClassSession::firstOrCreate`（冪等）（2026-04-24）
- **防再犯**：任何新增「補課/調課目標日期」的 API 都必須同步建立 ClassSession，不可只寫 schedules

---

### R14. submitQuickAttend 缺 StudentID + 日期寫死 today

- `AttendancePage.vue` 的老師「補建並點名」送出的 body 沒有 `StudentID`，後端驗證必填 → 422 靜默失敗
- `SessionDate` 寫死 `localTodayYmd()`，老師無法補登昨天的補課堂次
- **修法**：從 teacherCourses 補上 StudentID；加日期選擇器（最多回溯 14 天）（2026-04-24）
- **防再犯**：任何呼叫 `POST /api/v1/attendance` 的地方，都必須帶 StudentID；任何老師補登入口都必須支援選日期

---

### R15. 出勤頁「出缺勤紀錄」預設只顯示今天，管理員看不到歷史已到班紀錄

- `fetchRecords` 固定傳 `date: 今天`；`AttendanceController::index` 只支援單日 `date` 參數 → 管理員需一天一天手動切換才能看過去紀錄
- **修法**：後端加 `start_date`/`end_date` 區間參數，無參數預設最近 7 天；前端管理員加「最近 7 天 / 今天」快捷切換（2026-04-24，`fix/attendance-range-view`）
- **防再犯**：任何「列表型」API（attendance、class-sessions 等）若依賴日期篩選，必須支援區間查詢並設合理預設窗口（≤ 14 天），不得預設只回今天

### R16. ⛔ `<script setup>` 中 `const` 初始化時呼叫尚未宣告的 `const` → TDZ 整頁空白（P0）

- `quickForm = ref({ date: localTodayYmd() })` 在 line 1473，但 `localTodayYmd` const arrow 在 line 1620 → JavaScript TDZ → `ReferenceError: Cannot access 'Xt' before initialization`（minified）→ Vue `setup()` 中止 → 整頁空白（P0 regression，PR #41 引入）
- **根本原因**：`const`/`let` 宣告在其 binding 初始化前無法存取（Temporal Dead Zone），`function` 宣告才有 hoisting；minifier 只改名字，不改執行順序，TDZ 問題在 production 同樣觸發
- **修法**：將 `localTodayYmd` 宣告移到首次使用之前（2026-04-24，`hotfix/attendance-tdz-blank-page`，PR #45）
- **防再犯**：
  1. 在 `<script setup>` 中，任何工具函式（helper function）如果在 **`ref()`/`reactive()`/`computed()` 初始化時**被直接呼叫，必須確保該函式在呼叫點**之前**宣告
  2. 宣告順序優先：工具函式（`localTodayYmd` 類）→ 時間無關的常數（`quickMinDate` 類）→ 用到工具函式的 reactive state（`quickForm`、`recordsDate` 類）
  3. 如需 hoisting 特性，改用 `function` declaration 而非 `const` arrow function

---

## 模組對照索引（改特定模組前讀 Archive 對應條目）

| 模組 | 必讀條目（在 Archive） |
|------|----------|
| 堂數 / 扣堂 | §2026-04-17 繳費日期、§單堂費用固定 |
| 繳費 / 學收 | §繳費狀態 paid_at、§歷史課程漏算、§催繳名單六狀態、§幽靈課程 |
| 薪資 / 併堂 | §兼職薪資 concurrency、§同層級併堂 v1.4、§契約時長為準 |
| 代課 / 調課 | §代課Undo通知、§合併Undo還原時間、§雙層防護重複行、§atomic transaction、§R13（補課 schedule 不建 ClassSession） |
| 評量 | §同天多堂課 buildEvents、§請假後不填評量 |
| 課表回報 | §2026-04-17 回報系統（14 條禁止項） |
| 排課 | §start_time 格式、§智慧排課誤標取消 |
| 出缺勤 / 分校隔離 | §SEC-001、§分校隔離後端強制、§R12（查詢日期寫死今天）、§R14（submitQuickAttend 缺 StudentID）、§R15（出勤頁預設只顯示今天，歷史到班紀錄不可見）、§R16（`script setup` const TDZ 初始化順序 → 整頁空白）|
| 月結制 | §b3 inactive 歷史、§b4 加購分流 |
| routes/api.php | §AI 靜默回退路由（改前必讀完整檔案 + route:list） |
| 備份 / nightly | §nightly 覆蓋修正、§備份還原演練 |
| Bug 回報 / 附件存檔 | §R11 storage symlink（Archive） |

---

> 新增事故：請直接寫到 [AI_REGRESSION_LESSONS_ARCHIVE.md](AI_REGRESSION_LESSONS_ARCHIVE.md)，並更新上方黃/紅線（若升級為通用規則）。
