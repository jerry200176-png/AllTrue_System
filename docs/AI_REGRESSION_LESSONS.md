# AI／工程師防再犯紀錄（必讀）

本檔記錄**已發生過的產品／實作缺口**，避免下次改壞或改漏。  
**任何 AI Agent 或新進開發者**：請與 `AGENTS.md` 的 First-read 順序一併閱讀；修改下列模組前**先對照本檔**。

> 詳細事故記錄（33 條）→ [AI_REGRESSION_LESSONS_ARCHIVE.md](AI_REGRESSION_LESSONS_ARCHIVE.md)

---

# ⛔⛔⛔ 開工前必讀摘要（30 秒讀完）⛔⛔⛔

## 紅線（⛔ 違反 = P0 故障，零容忍）

### R1. `/home/admin` 就是 production — 在 Pi 改檔案 = 改線上

```
/home/admin/backend/  ← nginx 直接 serve 的 document root
/home/admin/frontend/ ← npm run deploy 後 copy 到 backend/public/
```

- **只要 cwd 在 `/home/admin` production working tree，任何分支修改既有 .php/.vue/config 檔 = 即時影響 production**
- 在 Pi 上 `git checkout -b` 不會隔離 working tree；WSL2 `~/alltrue` feature branch 才是安全開發路徑
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
   3. PR merge → deploy.yml 自動部署（有 deployable diff 才跑）
   4. AI 自己監控 CI / deploy / health check

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

### R17. 家長/學生資料 API：先檢查 ownership，再檢查狀態

- 家長端若先回 `pending/approved/voided` 狀態錯誤，再檢查 `StudentID` 歸屬，攻擊者可用猜測 ID 從 `403/409` 差異推測別人評量狀態。
- **正確順序**：解析 session → 找 record context → `StudentID === session.StudentID` → 才判斷 `Status/VoidedAt`。
- **測試必補**：同一個外部 token 對「別人的 pending record」也必須回 `403`，不可回 `409`。（PR #51，2026-04-25）

---

### R18. GitHub Actions SSH Secrets 三組值必須同步維護，且格式嚴格

**事故日期**：2026-04-26（今天，由前一個 session 的 debug 操作造成）

**根本錯誤**：
1. `PI_USER` 被設成 `admin@pi.lifenet.com.tw`（完整連線字串）而非 `admin`（純帳號）→ sshd 收到 username=`admin@admin`，Invalid user
2. `PI_SSH_KEY` 被換成新生成的 private key，但對應 public key 未加入 Pi `authorized_keys` → Permission denied

**診斷關鍵**：`sudo journalctl -u ssh` 的 `Invalid user admin@admin` 比 SSH verbose log 更快指出真正問題。

**強制規則**：
- `PI_USER` / `PI_SSH_USER`：只能存**純帳號**（`admin`），不可含 `@hostname`
- `PI_HOST` / `PI_SSH_HOST`：只能存**純主機名**（`pi.lifenet.com.tw`），不可含 `user@`
- `PI_SSH_KEY`：換 key 前必須同步在 Pi `~/.ssh/authorized_keys` 加入對應公鑰，並先以 pi-health.yml 驗證連線再換
- 原始 deploy key pair 放在 Pi `~/.ssh/rpi_actions_deploy`（私）/ `rpi_actions_deploy.pub`（公），公鑰指紋 `SHA256:B/tQBH...95g`，永遠保留在 authorized_keys 第一行

**修復 SOP**（SSH deploy 失敗時）：
1. `sudo journalctl -u ssh` 看 sshd 實際收到的 username → 若含 `@` 就是 Secret 格式錯
2. 看 CI log `debug1: Offering public key` 的指紋 → 對照 Pi `authorized_keys` 的公鑰指紋
3. 用 `rpi_actions_deploy` 私鑰重設 `PI_SSH_KEY`，確認 `PI_USER`/`PI_HOST` 純格式

---

### R19. 家長回饋 mark-read 不可更新 `updated_at`

- `learning_record_feedbacks.updated_at` 代表家長最後送出/修改回饋的時間，未讀判斷用 `last_read_by_*_at < updated_at`。
- 若 staff mark-read 用 Eloquent `save()`，會同步更新 `updated_at`，造成「讀完刷新又變未讀」。
- **強制規則**：mark-read 只能更新 `last_read_by_teacher_at` / `last_read_by_director_at`，不可碰 `updated_at`；測試必斷言 `updated_at` 不變。

---

### R20. 課程結案不可只改 `StudentClass.Stop`

- 歷史課程狀態（`Stop=1` / `closed_reason`）若只改主檔，未來 `ClassSession.Status='scheduled'` 會殘留，老師/主任仍可能看到待上或待填項目。
- **強制規則**：任何入口把課程改為 inactive / settled / completed，都必須共用「取消未來 scheduled 堂次」邏輯；不可只更新 `StudentClass`。
- **測試必補**：直接 `PUT /student-classes/{id}` with `status=inactive` 與 `/pause` endpoint 都要驗證 future scheduled 變 `cancelled`，歷史 attended 不變。

---

### R21. 堂數制加購是新批次，不是追加原課程

- `POST /student-classes/{id}/purchase-batch` 會新建一筆未繳 `StudentClass` 與對應 `ClassSession`，原課程 `SessionCount` / `RemainingSessions` 不會被改寫。
- **強制規則**：加購 UI、README、操作提示都必須說「新批次課程」；成功後要用 response 的 `new_course.id` 引導主任查看新批次詳情，不可暗示原課程詳情會追加上課日期。
- **測試必補**：`purchase-batch` response 必須保留 `new_course.id`、`created_sessions`、`first_session_date`、`last_session_date`，讓前端能定位與說明新增批次。

---

### R22. 月結詳情不可只依賴已存在 `ClassSession`

- Legacy 月結課可能 `ScheduleMode='date'` 且有 `week/time` 固定時段，但 `EndDate` 或 `monthly_sessions` 缺失，只看已存在 `ClassSession` 會漏顯該月固定週課。
- **強制規則**：課程詳情與 `student-classes/session-dates` 對月結課必須以 `week/time` 契約推算查詢月份日期，再與既有 `ClassSession` / `schedules` 合併；不可因已有部分實體堂次就跳過推算。
- **測試必補**：模擬 legacy 月結 `EndDate=NULL` 且只有部分 `ClassSession`，API 仍須回傳當月所有固定星期日期。

---

### R23. 推算日期不可成為 dead-end chip

- 課程詳情若顯示 `_synthetic` 推算堂次但沒有 `ClassSession.id`，主任會看得到日期卻無法做單堂編輯。
- **強制規則**：任何顯示在「上課日期」區塊的 chip 都必須可操作；若是推算資料，點擊時應先經由受權限保護的 endpoint 冪等建立實體 `ClassSession`，再進既有編輯流程。
- **測試必補**：同一推算日期重複 materialize 不可建立重複 `ClassSession`；已結案課程不可建立新堂次。

---

### R24. 多科固定時段優先走一般課程

- 一般課程建立已支援每個固定時段覆寫科目與老師；若再把「多科共用方案」放在日常新建入口，主任容易誤以為多科排課必須建立共享付款池。
- **強制規則**：多科共用方案只作為 legacy / 歷史維護能力保留；新建課程 UI 應優先導向一般課程，不可與一般課程並列推薦。
- **變更限制**：不可刪除既有 `CoursePackage` API、ledger 或財務歷史；若要資料轉換，需另開 PRD 與 migration plan。

---

### R25. 智慧行事曆請假優先於 scheduled 例外

- 同一課程同日可能同時存在 `schedules.status='leave'` 與歷史調課留下的 `scheduled` 例外；若 scheduled 例外先吃掉基底格，請假卡與「假」角標會整格消失（張正樂 4/29）。
- **強制規則**：`SmartCalendar` 合併例外時，同 course/date 有 `leave` 必須讓請假基底卡優先；`scheduled` 例外不可再 suppress 同時段 base slot，也不可另渲染成正常課。
- **測試必補**：新增或修改智慧行事曆例外合併邏輯時，必須覆蓋 leave + scheduled 同 course/date 的衝突案例。

---

### R26. 月結續報與堂數額度不可混在同一語意

- 月結續報若延長原 `StudentClass`，舊期已繳與新期待繳會混在同一課程，主任無法判斷哪一期已結算。
- 堂數制若直接列出所有有效 `ClassSession`，購買 8 堂也可能看到第 9 堂，造成家長對帳與少收費風險。
- **強制規則**：月結續報必須建立新一期課程並結算舊期；堂數 chip 序號只給購買額度內堂次，`IsContractException=1` 顯示為例外堂，超出 `SessionCount` 的非例外堂必須顯示為超排異常。
- **強制規則**：月結提醒若已有未結清逐期 `Invoice`，必須用該 Invoice 的 `DueDate`/`billing_period` 當真實應繳日，不可用今天月份重新推導。
- **測試必補**：`renew-monthly` 必須驗證新舊 `StudentClass` 分離與舊期 future scheduled 取消；`class-sessions` 必須回傳例外旗標供前端分流；未來期月結 Invoice 不可被當成本月逾期。

---

### R27. 課程編輯新增同日多時段必須重排未來堂次

- 編輯課程把正班老師改掉並新增同一天第二個固定時段時，若後端只依「星期是否相同」判斷同步，會把所有未來堂次留在第一個時段，看起來像一週只能有一段。
- 編輯課程選了多個星期但 `day_time_slots` 暫時只含原本星期時，若前端 parent sync 或後端 mapping 只信 `day_time_slots`，新勾選的星期會被吃掉（例：週三+週日只存週三）。
- 只靠上方 weekday chips 推導時段會讓使用者不知道如何新增週日；固定時段應以「每列可選星期/時間/時長」為主，chips 只能當輔助顯示。
- 後端在儲存排課契約後若再用既有未來 `ClassSession` 反寫 `week/time`，舊堂次仍只有週三時會把剛新增的週日洗掉；改正式老師時若剩餘未來堂次很少也會重現。
- **強制規則**：`StudentClassController::syncFutureScheduledSessionTimes` 除了偵測星期新增/移除，也要偵測同一星期的固定時段數是否增加；增加時必須用 `buildSessionsForCount` cadence 重排未來未上堂次。
- **強制規則**：課程編輯 payload 必須以 `days_of_week` 補齊缺漏的 `day_time_slots`；前端開啟編輯時不可讓既有 slot 覆蓋 parent 傳入的 selected days。
- **強制規則**：`CourseEditForm` 的時段列 weekday select 必須列出週一到週日，不可只列已勾選星期；新增/改列星期時必須同步更新 `days_of_week`。
- **強制規則**：本次 `PUT` 明確帶排課欄位時，`ClassSession` 只能被同步到新契約，不可再反向覆蓋 `StudentClass.week/time` 契約欄位；`force_partial_rebuild` 也不可反寫主檔契約。
- **測試必補**：課程已有歷史出勤、未來 scheduled 從週六 13:00 改成週六 13:00+17:00 時，未來堂次必須分布成同日兩段；`days_of_week=[3,7]` 但 slots 只有週三時，主檔仍必須保存週日；若開課日 mismatch 或只改正式老師且舊未來堂次只有週三，`week1` 仍必須是 7。

---

### R28. 已繳課程不可再次核帳建立新付款

- 主任核帳若找不到未繳 Invoice 就自動新建 Invoice/Payment，會讓同一筆課被重複入帳，畫面出現多筆繳費。
- **強制規則**：`PaymentReportController::directorRecord` 在課程已標記 `Paid=1` 且無未繳 Invoice 時，必須回 422，要求先作廢原收款或指定未繳帳單。
- **強制規則**：`PaymentReportController::confirm` 必須套用同一個已繳防重 guard；家長回報 pending report 不可繞過 `directorRecord` 的防護而新建第二筆帳。
- **強制規則**：帳單畫面顯示付款筆數時，只能計算正向有效付款；`Method='void'` 或負數沖銷不可算成「繳費次數」。
- **強制規則**：歷史錯帳更正不可刪除 `Payment` 或手改金額；必須從收款/收據紀錄逐筆撤銷，建立負值沖銷並保存 `void_reason` 稽核。
- **強制規則**：帳務中心的「收款流水筆數」不可被解讀成「已繳課程數」；畫面必須分開顯示有效收款流水、對應課程數、同課程多筆收款。
- **測試必補**：已繳課程重複呼叫 `directorRecord` 或 `confirm` 不得新增第二張 Invoice 或第二筆 Payment；invoice API 必須排除 void payment count 並回傳付款/沖銷明細供稽核。

---

### R29. 請假入口不可 fallback 直接寫 `schedules`

- `CourseLeaveCascadeService` 才是堂數制請假順延的唯一權威路徑；只寫 `schedules.status='leave'` 會產生「行事曆有請假、`ClassSession` 沒順延、課程管理少一堂」的半套資料。
- **強制規則**：任何請假 UI 的 API 失敗都必須明確報錯並停止，不可 fallback 到 Supabase-style `schedules` insert。
- **修復/補救**：既有 `ClassSession.Status='leave'` 但有效堂次少於購買堂數的資料，應透過受權限保護的 `leave-by-session` / cascade 路徑冪等修復，不可手動直接改 production DB。
- **測試必補**：新增或修改請假流程時，必須覆蓋「已存在 leave 但尚未 cascade」重跑後只補一次尾堂，且有效堂次數等於購買堂數。

---

### R30. 帳務入口必須能 drill down 到同一份 AR ledger

- 帳務中心「待收與核帳」是提醒/催繳 queue，「收款與收據紀錄」是 receipt/payment 流水，課程管理帳單是單一課程 Invoice；三者若各自顯示不同口徑且不能互相 drill down，主任會無法對齊同一學生的帳。
- **強制規則**：所有帳務入口若呈現應收、已收、收據或帳單狀態，必須能連到同一份以 `Invoice` 為中心的學生 AR ledger，並同時顯示 `PaymentReport` 收據、`Payment` 套用、void/reversal 與未結清金額。
- **強制規則**：歷史錯帳不可用「畫面看起來一致」掩蓋；ledger 必須標示同帳單多筆正向收款、收據未套帳單、收據缺 Payment、Payment 缺收據、帳單狀態與付款流水不一致等異常。
- **測試必補**：至少一個 regression case 驗證同一學生/課程可從 `student_class_id` 打開 ledger，且 Invoice、Payment、PaymentReport 的 receipt no 與異常標籤能對齊；跨分校 ledger 必須 403。

---

### R31. Ledger 不可把溢收顯示成同一帳單還要再繳

- 同一張 Invoice 可能因歷史錯帳有多筆正向 Payment；若畫面把每筆都用 `+金額` 顯示且全部加進已套用，主任會解讀成「同一課程要繳三次」。
- **強制規則**：AR ledger 必須用 cash application 口徑：一張 Invoice 的已套用最多等於 `TotalAmount`，超過部分要進 `overpaid_amount` 並標示「溢收/待沖銷」，不可算進未結清或已套用。
- **強制規則**：Payment 明細必須顯示套用狀態（已套用、部分套用、溢收/待沖銷、已沖銷），並使用正式業務編號（如 `INV-*`、`RCPT-*`、`COURSE-*`）作為主要顯示，不可只顯示 DB id。
- **強制規則**：「待收/待核」是工作 queue，不可拿來當完整已繳查詢；已結清課程必須由 Invoice/Payment/Receipt 與課程主檔彙整，堂數制已繳且剩餘堂數充足也要查得到。
- **強制規則**：Ledger 的例外帳不可只顯示警告；可處理的 confirmed receipt 必須提供撤銷/沖銷入口，不能自動處理的 legacy/payment 關聯缺口才標示需人工修復。
- **測試必補**：同一 Invoice 三筆收款且合計超過應收時，API 必須回傳已套用等於應收、溢收等於超額、第三筆為 `overpayment_pending_review`。

---

## 模組對照索引（改特定模組前讀 Archive 對應條目）

| 模組 | 必讀條目（在 Archive） |
|------|----------|
| 堂數 / 扣堂 | §2026-04-17 繳費日期、§單堂費用固定 |
| 繳費 / 學收 | §繳費狀態 paid_at、§歷史課程漏算、§催繳名單六狀態、§幽靈課程、§R30（帳務入口共用 AR ledger） |
| 薪資 / 併堂 | §兼職薪資 concurrency、§同層級併堂 v1.4、§契約時長為準 |
| 代課 / 調課 | §代課Undo通知、§合併Undo還原時間、§雙層防護重複行、§atomic transaction、§R13（補課 schedule 不建 ClassSession） |
| 評量 / 家長回饋 | §同天多堂課 buildEvents、§請假後不填評量、§R17（ownership 先於狀態判斷）、§R19（mark-read 不可更新 updated_at） |
| 課表回報 | §2026-04-17 回報系統（14 條禁止項） |
| 排課 | §start_time 格式、§智慧排課誤標取消、§R25（請假優先於 scheduled 例外）、§R29（請假不可 fallback 只寫 schedules） |
| 出缺勤 / 分校隔離 | §SEC-001、§分校隔離後端強制、§R12（查詢日期寫死今天）、§R14（submitQuickAttend 缺 StudentID）、§R15（出勤頁預設只顯示今天，歷史到班紀錄不可見）、§R16（`script setup` const TDZ 初始化順序 → 整頁空白）|
| 月結制 / 加購 / 多科固定時段 | §b3 inactive 歷史、§b4 加購分流、§R21（堂數制加購是新批次）、§R22（月結詳情不可只依賴 ClassSession）、§R23（推算日期不可成為 dead-end chip）、§R24（多科固定時段優先走一般課程）、§R26（月結續報與堂數額度不可混在同一語意） |
| routes/api.php | §AI 靜默回退路由（改前必讀完整檔案 + route:list） |
| 備份 / nightly | §nightly 覆蓋修正、§備份還原演練 |
| Bug 回報 / 附件存檔 | §R11 storage symlink（Archive） |

---

> 新增事故：請直接寫到 [AI_REGRESSION_LESSONS_ARCHIVE.md](AI_REGRESSION_LESSONS_ARCHIVE.md)，並更新上方黃/紅線（若升級為通用規則）。

### R18. 家長入口 sibling 偵測必須驗證 LINE user ID 格式

**觸發情境**：2026-04-25 家長登入看到 7 個不相關學生可切換

**根因**：2026-04-16 backfill migration 把 `Student.LineID` 直接複製進 `student_line_bindings`，
未驗證是否為有效 LINE user ID 格式（U + 32 hex）。多個不同分校的學生共享同一 LineID（舊系統錯誤資料），
造成跨家庭 PII 洩漏。

**強制規則**：
- `StudentLineBinding` 查詢用於 sibling 偵測前，必須 `.filter(fn($id) => isValidLineUserId($id))`
- backfill migration 複製 `LineID` 欄位時，必須加 `AND LineID REGEXP '^U[0-9a-f]{32}$'` 條件
- 同一 `line_user_id` 出現在多個不同 `CampusID` 的學生 = 資料錯誤，不得作為 sibling 群組

**修復**：PR #74 (code guard) + PR #75 (data cleanup migration)
