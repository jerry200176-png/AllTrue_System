---
owner: jerry (CEO)
review_cycle: quarterly
last_reviewed: 2026-06-06
---

# AI／工程師防再犯紀錄（必讀）

本檔記錄**已發生過的產品／實作缺口**，避免下次改壞或改漏。  
**任何 AI Agent 或新進開發者**：請與 `AGENTS.md` 的 First-read 順序一併閱讀；修改下列模組前**先對照本檔**。

> 詳細事故記錄（33 條）→ [AI_REGRESSION_LESSONS_ARCHIVE.md](archive/AI_REGRESSION_LESSONS_ARCHIVE.md)
>
> **🔁 高復發檢討**：改排課/扣堂/月結/行事曆/停用課程前，先讀本檔 **§復發家族（Recurring Defect Families）** 認領 F1～F6，對照不變式並補回歸測試 —— 否則點修會再復發。

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

- 測試只可在 WSL2 本地 `AllTrue_test` 或 GitHub Actions CI 跑；Pi production 絕對不可跑
- debug CI 失敗 → 先用 `./scripts/presubmit-local.sh` 排除本地可重現問題，再改檔案 → push → 看 CI log
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

### R8. Pi 部署禁止 `composer install --no-dev`（含 deploy.yml 與手動 incident deploy）

```bash
# ❌ 造成 "Class NunoMaduro\Collision not found" → health check 500
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# ✅ 正確：Pi 已有 dev 套件，--no-dev 無法乾淨移除，保留 dev 套件不影響生產
composer install --no-interaction --prefer-dist --optimize-autoloader
```

- 原因：Pi 本機 vendor 有舊 dev 安裝，`--no-dev` 雖移除 Collision 的檔案，但 `php artisan optimize` bootstrap 時仍讀到舊 `packages.php` 中的 provider 登記，導致 class not found
- 結果：health check 失敗 → 自動 rollback 觸發 → 服務短暫中斷（2026-04-24 事故）
- 2026-04-30 再犯：手動部署 PR #222 時誤用 `composer install --no-dev`，Telegram health 監控與 Sentry 同時收到短暫 500；即使是手動 emergency deploy 也必須用不含 `--no-dev` 的 composer 指令

### R8b. Actions minutes 用完時的緊急手動「前端」部署（2026-06-27, in-app #174）

依 `OPERATIONS_RUNBOOK.md` §139：Actions minutes 用完且 deploy workflow 不可用時，**純前端**修復可走緊急手動前端部署。安全作法（本次實證 OK）：

- **不要在 Pi 做 git 操作**（Pi working tree 可能停在舊 feature branch 且有 runtime storage 改動；`git reset --hard` 會清掉 runtime 上傳的 avatar/附件）。
- 流程：本機 `npm run build`（綠＝CI 替代）→ `rsync dist_build` 到 Pi `/home/admin/frontend/dist_build` → Pi 跑既有 `node scripts/copy-to-backend.cjs`（內含 `verifyIndexHtmlReferencesAssets` 一致性 guard + OPcache flush），**只覆蓋 `backend/public` 前端 bundle**。
- 先備份現有 `backend/public/{index.html,version.json,assets}` 到 `backups/emergency/pre*_TS`（rollback 用）。
- 驗證：health ok、`version.json` 更新、`index.html` 引用的 `assets/*.js` 皆 200 `text/javascript`（非 `text/html`＝避免事故 D 白屏）、served chunk 含修正碼。
- 事後補：CHANGELOG + 本檔記錄例外，Actions 恢復後補 PR 回 main（否則下次 `git reset --hard origin/main` 會把熱修還原）。

**契約教訓**：後端新增錯誤碼/契約（如 #805 `overlapping_active_course` 409）時，**前端對應分支要一起加**；本案前端只認 `duplicate_active_course`，新碼落到原生 `alert()` → 使用者被叫去勾不存在的「強制建立」＝死路。GitHub #931。

### R10. 家長入口登入：必須同時讀 `parent_phone` 與 `Phone`

```
UI「家長手機」欄 → 儲存到 Student.parent_phone
登入驗證         → 原本只讀 Student.Phone → 永遠不符 → 401
```

- **禁止**：只用 `$s->Phone` 驗證家長身份
- **正確**：`resolveContactPhone()` 優先 `parent_phone`，空才 fallback `Phone`
- **LINE OA 綁定**（2026-06-28）：`LineWebhookController` 的「綁定 姓名 手機」也必須走同一邏輯（`StudentContactPhone`），不可只查 `Phone`
- 修復：PR #38，2026-04-24；LINE bind 對齊 PR #1037

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

### Y2b. 測試用 `addDays(1)` 跨週 → 週日執行時次日落入下週，`weeklyScheduled` 少一

```php
// ❌ 危險：today=週日，addDays(1)=週一 → Carbon::now()->endOfWeek() 是週日 → 下週 session 不計入
ClassSession::create([..., 'SessionDate' => $today->copy()->addDays(1)->toDateString(), 'Status' => 'scheduled']);
$this->assertGreaterThanOrEqual(2, $weeklyScheduled); // CI 週日跑 → 1 < 2 → FAIL

// ✅ 正確：兩個 session 都在 today（永遠在當週），future session 用 23:00 符合 Y2 規則
ClassSession::create([..., 'SessionDate' => $today->toDateString(), 'StartTime' => '08:00', 'Status' => 'attended']);
ClassSession::create([..., 'SessionDate' => $today->toDateString(), 'StartTime' => '23:00', 'Status' => 'scheduled']);
```

**強制規則**：測試中需要「當週 N 筆」時，所有 session 日期用 `$today` 或 `Carbon::now()->startOfWeek()->addDays(X)` + 確認 `addDays(X)` 不超出週末（X ≤ 6）。

### Y3. PhpSpreadsheet sheet 名稱不能為空

- 動態 sheet name 必須 guard 空字串，fallback 到 `"Sheet"` 或 `"老師{$id}"`

### Y4. 前端改了必須 build 才生效

- `npm run deploy` 只能在 **main branch + PR merged 後** 執行

### Y5. DB::table() raw queries 不受 Eloquent global scope 保護

```php
// ❌ campus 過濾漏掉 → 跨分校資料洩漏
// ✅ 使用 resolveEffectiveCampusIds() 統一驗證，空 auth_campus_ids 非 super_admin → 403
```

### Y6. 多 agent／多 session 並行 → 用 git worktree 隔離，勿在主 working tree `~/alltrue` 共改

- 本專案常多個 AI agent 並行（#692／#699／maturity／ops…）。**共用同一個 `~/alltrue` git working tree 會 race**：別的 agent 一 `git checkout`／切分支，就把你**尚未 commit 的改動還原成 HEAD**。症狀：`git status` 顯示乾淨、但你的編輯不見了；branch ref 在不同 commit 間跳動。
- ✅ 正確：每個任務在**獨立 worktree** 做（已有 `alltrue-maturity-docs`、`alltrue-ops-split` 範例）：

```bash
git worktree add /tmp/<task> origin/main -b <type>/<slug>
cd /tmp/<task>   # 在此改 / commit / push / 開 PR，不受主 working tree checkout 影響
# 完成後：git worktree remove /tmp/<task> --force
```

- `gh pr create` 要從 worktree 內跑，或加 `--head <branch>`，否則會誤用主 working tree 當前分支（曾誤抓到並行 agent 的 `feat/699`）。
- 教訓來源：2026-06-06 docs 治理在主 working tree 改，被並行 #692 agent 反覆沖掉、重做兩次後改用 worktree 才完成。

---

# 🔁 復發家族（Recurring Defect Families）— 高復發檢討

> **為什麼一再復發？**（2026-06-06 批次檢討，8 件回報中 7 件有前例）
> 過去多是**點修**：只改觸發那一個畫面/那一筆資料，沒有把「狀態變更後其衍生資料要對齊」這條**不變式**補成回歸測試。
> 下次改下列模組，**先認領家族 → 對照不變式 → 補該家族的回歸測試**，不要只修單一 symptom。
> 追蹤 Epic：見 GitHub `[Epic] 復發家族根治`。各家族成員 issue 與 production 佐證寫在該 Epic。

| 家族 | 共同根因（不變式被違反） | 前例 | 必補回歸測試守門 |
|------|--------------------------|------|------------------|
| **F1 狀態收尾缺口** | 主檔狀態變更（`Stop=1` / 老師 `suspended` / 月結結算）後，**未對齊未來 `ClassSession.scheduled` / `schedules` / 老師名額**，殘留堂次續顯示 | #151、#427、#99、行290、§R32、§R59 | 停用/結算課程或老師後，未來 scheduled 堂次不得再出現在行事曆/名額；已上堂次須保留 |
| **F2 月結續期語意** | 續期未依**當期實際堂數**重算金額/堂次；收據未綁 `billing_period` | #149、§R22、§R26、#554、#594 | 續期＝新一期+結算舊期；收據金額=當期堂數×費率、含結算月 |
| **F3 排課堂次生成** | 建課後未依 `week/time` 契約**推算/補齊完整未來堂次**（只生成片段） | #148、#497、#539、#424、§R22、§R23、§R64（週日 slot 全滅→0 元月結） | 建課後即依契約生成完整未來 ClassSession；預排日不得反白/dead-end；weekday 比對先 `isoWeekday()` 正規化 |
| **F4 共用堂數（一對三）** | `Charge` 未計算（=0）；**購買堂數 vs 實體 ClassSession 數**呈現混淆 | #147、#553、#430、#448、#440、§R21 | 共用堂數金額/堂數有單一權威來源，購買 vs 已用 vs 課表數一致 |
| **F5 行事曆合併** | week 檢視 merge/去重/過濾**排除有效堂次**（含歷史已上） | #152、§R47、§R49、§R50、行544、§G-007 | 唯一走 `calendarOccurrenceMerge.js`；`npm run test:calendar`；歷史已上堂次仍顯示 |
| **F7 繳費金額/狀態雙真相** | `Charge` 與 `Rate×數量` 的差額、`StudentClass.Paid` 與 Invoice/Payment 各有兩套真相；點修單邊會「改了又跳回」 | #112、#425、#509、#798、#799、§G-009 | Charge 差額必須可追溯到 `session_charge` 調整；有效收款紀錄存在時課程不得被改為未繳費（解鈴走帳單作廢），任何降級路徑都要明確回饋不得靜默 |
| **F6 輸入邊界 collation** | utf8mb3 文字欄遇 **4-byte 字元（emoji）** → `like` collation 1267 crash（**首發，無前例**） | #657 | 含 emoji 關鍵字搜尋學生姓名不得 500（查詢前濾 4-byte 字元） |

**通用防再犯規則（跨家族）：**
1. 任何「**狀態變更**」（停用、結束、結算、續期、調課）寫主檔時，必須在**同一交易內**決定其衍生 `ClassSession`/`schedules`/名額/金額如何對齊，並寫測試覆蓋「變更後衍生資料正確」。
2. 任何「**列表/行事曆/收據**」呈現課程資料時，先確認資料來源是否涵蓋 **歷史/停用/未來/月結推算** 四種狀態，缺一即為潛在 F1/F2/F5 復發。
3. 修任一家族成員，PR 必須引用本節家族代號（F1～F6）並附「**revert 後會 fail**」的回歸測試；否則視為點修，會再復發。
4. DB 文字欄若為 `utf8mb3`，所有以使用者輸入做 `like` 的查詢，**先濾掉非 BMP（4-byte）字元**（F6）。

**度量與工具（業界對齊，2026-06-06）：**
- **復發率＝主指標**：同根因 6 個月內再現的比例（業界 postmortem 通用 KPI）。本節家族成員再現即計入；目標逐季下降。
- **修 bug 前流程**：見 `.cursor/rules/bug-fix-plan.mdc` §B0（查 closed issues + 認領家族 + MemPalace + Sentry regression）。
- **Sentry**：crash 類請用 Sentry「Resolve」——resolved 後再現會**自動標 regression** 並通知；issue 連回對應 GitHub issue 保留脈絡（this failed before, see #N）。
- **本節即本專案的 known-issues registry**（OSS 無獨立工具）；新家族成員修完務必回寫本節，讓下一個 AI 不重學。

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
- **雙向回覆延伸（2026-05-31）**：`learning_record_feedback_replies` 上線後，未讀語意分三條，勿混淆：
  - 員工回覆（teacher/director）：**只**標記該角色 `last_read_by_*_at`，**不可** touch `updated_at`（否則另一員工角色會被假未讀）。
  - 家長追問（author_role=parent）：**必須** touch `updated_at`（重新觸發員工未讀，沿用 `me/unread-feedback-count`）。
  - 家長端「有新回覆」紅點：用「員工回覆 `created_at` > `last_read_by_parent_at`」判定，**不可**沿用 `updated_at`（因員工回覆刻意不 touch `updated_at`）。`parentShow` 須先組回應再標記家長已讀。

---

### R20. 課程結案不可只改 `StudentClass.Stop`

- 歷史課程狀態（`Stop=1` / `closed_reason`）若只改主檔，未來 `ClassSession.Status='scheduled'` 會殘留，老師/主任仍可能看到待上或待填項目。
- **快速診斷**：今日點名總表學生重複，但個人紀錄只有一次時，先查同一學生同日 `ClassSession` 是否同時存在 active 與 `Stop=1` 舊 `StudentClass` 的 `scheduled` 堂次；例：2026-04-29 大直周宏謙，舊課 `StudentClass#527 Stop=1` 殘留 `ClassSession#6239 scheduled`，與新課 `#902/#7959` 重疊。
- **資料修復守則**：修單筆殘留堂次前先備份 DB + code；UPDATE 必須鎖定 `ClassSession.id`、`StudentClassID`、日期、時間與 `Status='scheduled'`，只改成 `cancelled` 並保留 Note 稽核，不可刪 row。
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
- **強制規則**：一般多科固定時段仍優先走一般課程；多科共用方案可作為「進階選項」使用，但文案必須明確說明「多科共享同一個堂數池、續報加購加到方案總堂數」。
- **加購規則**：`PackageID` 課程不可再走單科 `student-classes/{id}/purchase-batch`；必須改走 package 層 `PUT /course-packages/{id}` 更新 `total_sessions`，避免共用池被拆回單科新契約。
- **舊課程綁定規則**：`bind-courses` 必須擋跨學生、跨分校、月結、停用、已屬其他 package 的課程；dry-run 發現任何 blocked course 時不可寫入。
- **變更限制**：不可刪除既有 `CoursePackage` API、ledger 或財務歷史；若要資料轉換，需另開 PRD 與 migration plan。

---

### R25. 智慧行事曆請假優先於 scheduled 例外

- 同一課程同日可能同時存在 `schedules.status='leave'` 與歷史調課留下的 `scheduled` 例外；若 scheduled 例外先吃掉基底格，請假卡與「假」角標會整格消失（張正樂 4/29）。
- **強制規則**：`SmartCalendar` 合併例外時，同 course/date 有 `leave` 必須讓請假基底卡優先；`scheduled` 例外不可再 suppress 同時段 base slot，也不可另渲染成正常課。
- **測試必補**：新增或修改智慧行事曆例外合併邏輯時，必須覆蓋 leave + scheduled 同 course/date 的衝突案例。

---

### R25b. 智慧行事曆不可再用分散 if 合併三個資料源

- `StudentClass`（常態規則）、`ClassSession`（實際堂次）、`schedules`（請假/調課/代課例外）若在 Vue component 內分段合併，容易出現「base 先跳過、exception 又跳過」導致課消失，或同一堂同時掛兩位老師（吳艾潼 SC#382 / 2026-05-10）。
- **強制規則**：週檢視必須經由 `calendarOccurrenceMerge` 產生單一 occurrence list；同一 `ClassSession.id` 只能輸出一張卡，`scheduled` 例外若匹配同一堂只能 overlay 老師/時段，不可另渲染第二張。
- **測試必補**：任何修改 calendar merge 行為，都必須先跑 `npm run test:calendar`，並覆蓋「不重複、不消失、leave 不被遮蔽」三種 fixture。

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
- **強制規則**：手機版 modal 必須蓋過 `.mobile-bottom-nav`，且底部要包含 `safe-area-inset-bottom`；不可讓 iPhone mini 等小螢幕的底部導覽擋住「儲存」。
- **測試必補**：課程已有歷史出勤、未來 scheduled 從週六 13:00 改成週六 13:00+17:00 時，未來堂次必須分布成同日兩段；`days_of_week=[3,7]` 但 slots 只有週三時，主檔仍必須保存週日；若開課日 mismatch 或只改正式老師且舊未來堂次只有週三，`week1` 仍必須是 7。

---

### R28. 已繳課程不可再次核帳建立新付款

- 主任核帳若找不到未繳 Invoice 就自動新建 Invoice/Payment，會讓同一筆課被重複入帳，畫面出現多筆繳費。
- 已繳課程底下若殘留 unpaid Invoice，不能把該 Invoice 當成「可核帳目標」；這通常代表歷史錯帳或續報/帳單建立時期的殘留資料，應先作廢或修補資料。
- **強制規則**：`PaymentReportController::directorRecord` 在課程已標記 `Paid=1` 或已有 paid Invoice 時，必須回 422；即使 request 指定了 unpaid `invoice_id` 也不可建立第二筆 Payment/PaymentReport。
- **強制規則**：`PaymentReportController::confirm` 必須套用同一個已繳防重 guard；家長回報 pending report 不可繞過 `directorRecord` 的防護而新建第二筆帳。
- **強制規則**：帳單畫面顯示付款筆數時，只能計算正向有效付款；`Method='void'` 或負數沖銷不可算成「繳費次數」。
- **強制規則**：歷史錯帳更正不可刪除 `Payment` 或手改金額；必須從收款/收據紀錄逐筆撤銷，建立負值沖銷並保存 `void_reason` 稽核。
- **強制規則**：帳務中心的「收款流水筆數」不可被解讀成「已繳課程數」；畫面必須分開顯示有效收款流水、對應課程數、同課程多筆收款。
- **強制規則**：歷史錯帳只能作廢，不可刪除；`Invoice.Status='void'` 不得進入家長應收、課程帳單列表、主任催繳/未結清加總。
- **強制規則**：系統內 Invoice 作廢入口只能處理未收款錯帳（非 paid/partial、`PaidAmount=0`、無正向 Payment），必填原因並記錄操作者；已收款帳單必須走收款撤銷/沖銷，不可直接 void Invoice。
- **強制規則**：已收款錯帳若需作廢，必須建立負值 `Payment` 沖銷（`Method='void'`）並保留原始正向 Payment/收據；UI 必須顯示 ledger 派生狀態（例如「已收足額 · 狀態待修復」），不可只照 `Invoice.Status` 顯示成未繳。
- **強制規則**：對帳視窗若列出可處理的 Invoice，就必須同頁提供正確操作；一般作廢只看 `can_direct_void`，沖銷作廢只看 `can_exception_void`，不可用 anomaly 標籤自行推導按鈕。
- **強制規則**：未收款錯帳不可因使用者按到沖銷作廢而卡住；後端若判斷淨收款與 `PaidAmount` 都是 0，必須安全降級為一般作廢。
- **測試必補**：已繳課程重複呼叫 `directorRecord` 或 `confirm` 不得新增第二張 Invoice 或第二筆 Payment；已繳課程殘留 unpaid Invoice 時仍必須拒絕；invoice API 必須排除 void payment count 與 void invoice，並回傳付款/沖銷明細供稽核；Invoice 作廢 API 必須覆蓋 paid/partial/cross-campus/teacher forbidden。

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

### R32. 已上課歷史評量不可因課程後來停用而消失

- `StudentClass.Stop=1` 用來關閉未來待上／待填事項，但不能遮掉已出勤（`ClassSession.Status in attended/late/absent`）的歷史待填或待審評量；既有 `completed` 停用課程 pending 仍維持隱藏。
- **強制規則**：評量列表可排除停用課程的未上課 pending，但必須保留已上課堂次；`ensure-past` 也必須能替已上課的停用歷史課補建 `LearningRecord`。
- **測試必補**：停用課程 + 已上課堂次的 pending LR 仍出現在列表；無 LR 時 `ensure-past` 會補建且不處理停用課程的未上課堂次。

---

### R33. 老師每分校 RFID 不可被學生 RFID 靜默吃掉

- `SwipeRfidController` 若先查 `Student.RFID`，同一張卡同時綁到學生與 `UserCampus.RFID` 時，老師本人刷卡會被寫成 `StudentSingIn`，不會建立 `TeacherSingIn`，主任「老師打卡」列表看不到。
- **強制規則**：同分校 `UserCampus.RFID` 明確命中有效老師時，必須優先走老師打卡；`Teacher.RFID` 已由 `UserCampus.RFID` 完全取代，runtime 不可再 fallback 到 `Teacher` table。
- **強制規則**：`StudentClass.TeacherID`、`StudentSingIn.TeacherID`、`TeacherSingIn.TeacherID`、`schedules.teacher_id` 一律存 `User.id`。老師姓名/手機/LINE 取 `User`，老師分校/RFID 取 `UserCampus`；`Teacher` table 只可作 migration backfill/legacy archive 來源。
- **補救規則**：修復前已被吃掉的歷史資料不可手工直接改 DB；先跑 `teacher-signin:recover-rfid-collisions --date=YYYY-MM-DD --teacher-id=<id>` dry-run，確認候選後才可在備份後加 `--apply`，且工具只新增 `TeacherSingIn`，不刪除原始 `StudentSingIn`。
- **測試必補**：RFID 同時存在於同分校學生與老師 `UserCampus.RFID` 時，API 回 `type=teacher` 且只建立 `TeacherSingIn`。

---

### R34. 備份健康檢查不可只看 mtime

- `pi-health.yml` 若只用 `stat -c %Y` 判斷最新 sixhour 備份，檔案被同步或 touch 後會顯示「0 小時」，但檔名時間可能已是多天前，造成備份假綠。
- **強制規則**：sixhour 備份新鮮度必須優先從 `alltrue_6h_YYYY-MM-DD_HHMM.sql.gz` 檔名解析；還原驗證也應以檔名排序選最新備份。
- **測試/驗證必補**：手動觸發 backup restore workflow 時，log 中的「使用備份」檔名日期必須合理接近當前時間；Pi health 不得只用 mtime 當唯一依據。

---

### R35. 註冊密碼規則不可前端 4 碼、後端 8 碼

- `AuthController::register`、`ProfileController::store`、`DirectorAccountController::register` 皆要求 `password min:8`；若前端提示或預檢仍寫 4 碼，使用者會以為可送出但後端回 422。
- **快速診斷**：「老師自行註冊失敗／主任新增老師失敗」先查前端 `Register.vue`、`TeachersList.vue`、`DirectorRegister.vue` 的密碼提示與送出前檢查是否仍為 4；臨時 workaround 是密碼用 8 碼以上（如 `teacher123`）。
- **強制規則**：改任一註冊/建帳入口的密碼 policy，三個前端入口與三個後端 validator 必須同步；測試或 build 後需檢查 production bundle/version 是否已上線。

---

### R36. 個別資料有課但老師今日名單缺漏，要分清「契約推算」與 `ClassSession`

- 課程詳情可由 `student-classes/session-dates` 按契約推算 `_synthetic` 堂次；老師工作台與出缺勤待點名只吃 `GET /api/v1/class-sessions` 的實體 `ClassSession`。
- **快速診斷**：遇到「學生個別資料有課、老師今日名單沒有」時，先用老師身分查 `/api/v1/class-sessions?start=<today>&end=<today>`。若 API 有回，優先懷疑前端快取/日期/分校/登入帳號；若 API 沒回，再查是否缺實體堂次或被 `cancelled/leave` 排除。
- **反例記錄**：2026-04-29 大直鄭宇婷 15:00-17:00 林宇芹/簡采柔，production DB 與老師身分 API 均正常回傳 `scheduled`，因此不應做 DB 修復，應先要求老師端重整與確認登入/分校。

---

### R37. 學生 CSV/XLSX 匯入 0 筆時先查 `ImportJob.ErrorLog`

- `ImportController::students` 會把每次匯入寫入 `ImportJob`；使用者說「CSV 有資料但匯入 0 筆」時，不要先猜編碼或資料庫問題，先查最新 `ImportJob.Status/Summary/ErrorLog`。
- **快速診斷**：若 `ErrorLog` 是「找不到學生/姓名欄位」，通常是檔案前面有校名/匯出資訊列，或姓名欄叫「學生姓名」而非嚴格的「學生/姓名」；匯入器必須能掃前幾列找真正標題。
- **強制規則**：匯入解析失敗不可回 HTTP 200 偽裝成功；前端必須顯示 `ErrorLog`/`error`，不可讓主任看到「新增 0 / 更新 0 / 略過 0」而無法自救。
- **測試必補**：新增或修改學生匯入時，必須覆蓋「前兩列是標題說明、第三列才是學生姓名欄」與「缺姓名欄回 422」兩個案例。

---

### R38. 家長端繳費提醒不可直接套主任端續課提醒

- 主任端 `alerts/tuition` 的堂數制低堂數（`RemainingSessions <= 2`）是營運續課提醒；家長端「繳費提醒」是付款/確認動作，兩者語意不同。
- 月結課程顯示月份必須用課程帳期／服務月份（優先 `Invoice.billing_period`，fallback `StudentClass.StartDate`），不可用瀏覽當天月份推導，否則 5 月課在 4 月底會顯示「4月已上」。
- `Paid=1` 與 `Stop=1` 必須分層顯示：付款狀態是「已繳費」，課程生命週期可輔助顯示「課程已結束」；不可用紅色「已停課」覆蓋已繳費語意。
- **強制規則**：家長端 `payment_alerts` 只放需要家長付款/確認的項目；已繳費但剩 1～2 堂的舊堂數課不可出現在家長待繳費區。若要提示家長，只能另用「課程即將結束／櫃台會協助安排」等非繳費文案。
- **測試必補**：家長 dashboard 必須覆蓋未來月份月結課、`Stop=1 + Paid=1`、`Paid=1 + remaining=1` 不列入家長待繳費、`Paid=0 + remaining=1` 仍列入。

---

### R39. 代課老師評量權限不可只用課程 + 日期判定

- 同一個 `StudentClass` 同一天可能有多個 `ClassSession`；若 `LearningRecord` 列表或儲存權限只用 `student_course_id + schedule_date` 找代課老師，會讓代課老師看到非自己時段的評量，或儲存時遇到 `Forbidden`。
- `schedules` 的單堂代課記錄必須以 `student_course_id + schedule_date + start_time` 精準對齊 `ClassSession.StudentClassID + SessionDate + StartTime`；時間格式要用 `SUBSTRING(...,1,5)` 同時支援 `HH:MM` 與 `HH:MM:SS`。
- **強制規則**：改 `LearningRecordController`、`SubstituteScheduleService`、老師評量頁、代課/調課流程時，凡是查代課歸屬都要帶入 `ClassSession.StartTime`；只有無 ClassSession context 的舊查詢才可退回 date-only。
- **測試必補**：新增或修改代課評量權限時，必須覆蓋「同一課程同一天 15:00 正班 + 20:00 代課」案例：代課老師只看得到/可編輯 20:00，不可看到或修改 15:00。

---

### R40. 點名扣堂不可只用 `ClassSessionID` 防重

- 歷史補建或例外流程可能讓同一個 `StudentClass` 同一天同開始時間出現兩筆不同 `ClassSession.id`；若點名只檢查 `ClassSessionID`，前端會列兩堂，批次點名會扣兩堂。
- **強制規則**：出缺勤建立與扣堂前，必須以 `StudentClassID + SessionDate + StartTime` 做時段級防重；`ClassSessionID` 只能當其中一層識別，不可作為唯一防線。
- **前端規則**：待點名列表顯示同一天課表時，應以同課程/日期/開始時間去重，避免全選批次送出重複堂。
- **測試必補**：新增或修改點名/補點名/批次點名時，必須覆蓋「兩筆不同 ClassSession id 但同課程同日期同開始時間」只能成功一筆且只扣一堂。

---

### R41. 補請假不可只用課程 + 日期找堂次

- 同一個 `StudentClass` 同一天可能有兩堂不同時間的課；若補請假只用 `student_course_id + session_date` 找第一筆 `ClassSession`，會誤把較早堂次改成補請假，實際要處理的晚堂仍顯示已上。
- **強制規則**：從出缺勤頁或任何已知堂次 context 發起補請假時，必須傳入並優先使用 `ClassSessionID`；日期只能做相容舊入口的 fallback，不可作為唯一定位。
- **測試必補**：修改 `retroLeave`、出缺勤狀態修改或同日多堂流程時，必須覆蓋「同一課程同日 18:30 與 20:00 兩堂，補請假 20:00 不可誤改 18:30」。

---

### R42. 行事曆堂次顯示老師不可被舊評量老師覆蓋

- `ClassSession` 列表同時回傳堂次顯示老師與 `learning_record_teacher_id`；若 `teacher_name` 優先取 `LearningRecord.TeacherID`，課程主檔改老師後，行事曆會出現 `teacher_id` 是新老師但名稱仍是舊老師的錯覺。
- **強制規則**：`GET /class-sessions` 給行事曆/點名的顯示老師必須與 `teacher_id` 一致，優先順序為「代課老師 > 現任課程老師」；評量歷史歸屬只放在 `learning_record_teacher_id` 或評量頁專用欄位。
- **測試必補**：修改 `ClassSessionController::index`、行事曆堂次顯示或課程改老師流程時，必須覆蓋「評量仍屬舊老師，但 StudentClass.TeacherID 已改新老師，class-sessions 應顯示新老師」。

---

### R43. 調課目標 `scheduled` 例外必須以 anchor 去重

- 拖曳移動課表時，前端會先寫 `schedules.status='rescheduled'` 原堂 marker，再寫 `scheduled` 目標堂；若同一 anchor 因重試、同日改時段或 stale POST 留下多筆 `scheduled`，行事曆會多畫一堂。
- **強制規則**：同步 `reschedule-session` 時，同一 `student_course_id + original_schedule_id` 在目標日期只能保留一筆 `scheduled`；跨日期與同日改時段都要清除 stale duplicates。
- **前端規則**：`SmartCalendar` 渲染 scheduled exceptions 時，必須以 `student_course_id + schedule_date + start_time + original_schedule_id` 做顯示去重，避免歷史髒資料直接放大成畫面 bug。
- **測試必補**：修改拖曳調課、`syncSchedulesAfterReschedule` 或行事曆例外合併時，必須覆蓋「同日改時段已有重複 scheduled row，修正後只剩一筆且保留代課老師」。

---

### R44. 代課顯示不可讓原老師 stale row 搶贏

- 同一堂代課可能因重試或歷史流程留下兩筆 `schedules.status='scheduled'`：一筆 `teacher_id` 是原課程老師，一筆才是真正代課老師；若 `ClassSessionController::index` 單純取 `MAX(id)`，較新的原老師 stale row 會讓課表仍掛在原老師欄。
- **強制規則**：查詢代課顯示老師時，`scheduled` 例外必須優先選 `teacher_id != StudentClass.TeacherID` 的紀錄；找不到不同老師時才退回課程老師。
- **前端規則**：`SmartCalendar` 同日同時段例外排序時，必須讓「不同於課程老師」的 scheduled exception 先渲染，避免顯示去重保留錯誤那筆。
- **測試必補**：修改 `ClassSessionController::index`、代課流程或行事曆例外合併時，必須覆蓋「同一堂同時存在原老師 stale scheduled row 與代課 scheduled row，API/畫面仍顯示代課老師」。

---

### R46. 主任端評量「授課老師」不可只信 LearningRecord.TeacherID

- **觸發情境**：單堂已指派代課，主任在學習評量表仍見正班老師，與行事曆／代課認知不一致。
- **根因（與過往同族）**：「這一堂誰授課」存在多個寫入來源——合約在 `StudentClass.TeacherID`、單堂代課在 `schedules`（見 §R39、§R44）、評量列上又有物化的 `LearningRecord.TeacherID`（報表／審核用）。若列表／表單只 join `LearningRecord.TeacherID`，就會在代課列已寫入但 LR 尚未自癒時顯示錯誤。**歷史類似紀錄**：`CHANGELOG`／archive 曾載代課與 `schedules` 不同步時「畫面仍顯示原老師」；§R42 處理的是 **行事曆** `GET /class-sessions` 不可被舊評量姓名蓋掉顯示老師，本條處理 **評量 API／主任列表與編輯表單** 必須與代課列對齊。
- **業界對齊（一句）**：多來源 domain 常見做法是選定 **權威事件或權威表**（此處單堂代課以 `schedules` 為準），物化欄位與其短暫不一致時，在 **讀取路徑做 reconciliation**（回應時重算）或 **交易內／背景作業同步**；本專案評量列表採與 `SubstituteScheduleService::effectiveInstructorUserId` 一致的 read reconciliation（`effective_teacher_id` + `teacher_name`），並保留 `ensure-past`／`syncRecordWithClassSession` 寫回自癒 DB。
- **強制規則**：`LearningRecordController::decorateRecords`／`hydrateRecordForResponse` 之 `teacher_name` 與 `effective_teacher_id` 必須來自上述 effective 解析；前端開啟評量／更換授課老師預設以 `effective_teacher_id` 為準。禁止改回「主任列表只顯示 `LearningRecord.TeacherID` 對應姓名」。
- **測試必補**：`SubstituteTeacherTest::test_director_learning_records_list_shows_substitute_when_lr_teacher_id_drifts`；任何代課或評量 hydrate 路徑變更須維持「LR.TeacherID 刻意漂移時列表仍顯示代課老師」。

---

### R47. 行事曆 ClassSession 路徑不可因同日 `rescheduled` 幽靈 marker 整格跳過

- **觸發情境**：課程管理／`ClassSession` 有某日（例 5/17 週日）排課，智慧行事曆週檢視該格空白；超排幽靈問題已因換週 refetch 等其他修正緩解後仍可能單獨出現。
- **根因**：`mergeWeekCalendarOccurrences` 在具 `courseSessionSet`（ClassSession API 已補齊日期）時，若 `schedules` 上仍残留 `status=rescheduled` 於**同一 course/同日**，舊邏輯無條件 `continue`，無視同日是否仍有 `scheduled` 之 `ClassSession`。**與 §R43（調課目標多重 scheduled）、§R39 同族：多寫入來源導致標記與物化堂次短暫不一致。**
- **強制規則**：僅當該日 **`liveRowsForDate`（非 cancelled）為空** 時，才可因 `hasReschedule` 略過該日之 base 合成；仍有堂次列時必須繼續輸出（時段匹配、`leave`、`scheduled` overlay 等規則仍適用）。
- **測試必補**：`calendarOccurrenceMerge.test.js` 覆蓋「同日 rescheduled + scheduled ClassSession」；`npm run test:calendar`。

---

### R48. 代課點名權限必須以時段級 effective teacher 為準

- **觸發情境**：單堂已指定代課老師後，原老師的行事曆仍顯示待點名，且手動點名 API 仍可由原老師送出。
- **根因**：前端老師週曆只抓本老師 `schedules`，原老師視角看不到「已交給代課老師」的 scheduled 例外，合併器無法移除原老師底卡；後端 `AttendanceController::store` 以「合約老師 OR 代課老師」放行，未在有代課時排除合約老師。
- **強制規則**：點名寫入權限必須採用時段級 effective teacher：`schedules.status='scheduled' + original_schedule_id` 且 `student_course_id + schedule_date + start_time` 命中時，只允許該 `teacher_id`；無代課才允許 `StudentClass.TeacherID`。
- **前端規則**：`SmartCalendar` teacher 週檢視必須載入足夠的同校區代課 exception 供 `mergeWeekCalendarOccurrences()` 合併後再依 `teacherScopeId` 過濾；不可先用 teacher_id 把「別人代課的例外」濾掉。
- **測試必補**：`calendarOccurrenceMerge.test.js` 覆蓋原老師被濾掉/代課老師可見；`AttendanceEndedSessionsSubstituteTest` 覆蓋原老師 403、代課老師成功、同日非代課堂原老師仍可點名。

---

### R49. 智慧行事曆同學生同時段去重不可用 StudentClassID 當唯一 key

- **觸發情境**：木柵今日行事曆同一學生同時段顯示兩張卡；API 查 `ClassSession` 沒重複，但 active legacy 月結契約與新堂次制實體堂次並存。
- **根因**：`calendarOccurrenceMerge.dedupeByStudentSlot()` 舊 key 優先使用 `student_course_id`，不同 `StudentClass` 的同學生同日同開始時間不會互相去重。
- **強制規則**：週檢視 occurrence 去重的學生時段 key 必須用學生識別 + 日期/星期 + 開始時間；`class_session_id` backed occurrence 優先於 exception/base/synthetic contract。
- **測試必補**：`calendarOccurrenceMerge.test.js` 覆蓋 legacy active contract + current `ClassSession` 同學生同時段只留實體堂次。

---

### R67. deploy SSH script 內關鍵步驟失敗必須讓 run 標紅（migration 失敗曾被吞成綠燈）

- **觸發情境**：#957 D1 unique index migration 在 production 依設計 fail-closed 拋錯（#1118），但 `deploy.yml` SSH 區塊無 `set -e`，`php artisan migrate --force` 失敗後照印「✅ Migration 完成」、deploy run 綠燈——與 R62「綠燈 ≠ 已出貨」同族。
- **根因**：heredoc SSH script 預設不因中途指令失敗而中止；成功訊息寫在指令後面而非以 exit code 分支。
- **強制規則**：deploy SSH script 內任何關鍵步驟（fetch/reset、composer、migrate、build）必須以 exit code 分支處理；失敗要嘛立即 `exit 1`，要嘛記 flag 並於結尾標紅。禁止在指令之後無條件印「✅ 完成」。migration 失敗時 code 部署可繼續（migration 一律 expand/contract 前向相容），但 run 必須紅。
- **測試必補**：`scripts/control-plane-lint.mjs` 或 deploy contract 檢查 migrate 呼叫必須在 `if` 分支內。

---

### R65. 新增 session 狀態值必須同步全部消費端（`leave_requested` 兩畫面認定分歧）

- **觸發情境**：家長入口送出請假、主任未審核期間（`ClassSession.Status='leave_requested'`，**無** `StudentSingIn` 列）：出缺勤管理把整列過濾掉（看起來已請假），課表與評量／今日待填卻列為待填評量（in-app #194／GitHub #1099，陳品承 7/4 週六 15-17 案例）。
- **根因**：請假審核流（#690）新增 `leave_requested` 狀態時，只改了寫入端與審核端；讀取端各自維護狀態白名單——`AttendancePage` 的 skip 集合有它、`sessionConsistency.NON_FILLABLE_LEARNING_STATUSES` 沒有它、`LearningRecord::scopeExcludeLeaveSessionPendingReview` 只認 sign-in 列的 `leave/excused`（此流程根本不建 sign-in 列）→ 各消費端對同一狀態的語意解讀不一致。
- **強制規則**：
  1. 任何人新增/擴充 `ClassSession.Status` 或 `StudentSingIn.Status` 枚舉值時，必須 grep 全部狀態白名單消費端並逐一決策：`sessionConsistency.js`（NON_FILLABLE + 兩個 label fn + classifyAttendanceSessionRows）、`LearningRecord` scopes、`AttendancePage`、`TeacherHomePage`、`LearningRecordsPage`、`ClassSessionController` 各 index filter。
  2. 「請假家族」語意集合 = `leave` / `leave_requested` / `leave_adjusted` / `excused`；判斷「這堂要不要點名/填評量」一律用集合，禁止散落硬寫單值。
  3. 只有 approve 後才寫 `StudentSingIn`；任何以「有無 sign-in 列」推斷請假狀態的邏輯，必須同時檢查 `ClassSession.Status`。
- **測試必補**：`sessionConsistency.test.js`（leave_requested 鎖填寫 + attendance 可見不待點名）＋ `LearningRecordLeaveExclusionTest::test_pending_lr_on_leave_requested_session_is_excluded`。

---

### R64. 星期欄位有兩套慣例並存，比對前必先正規化（ISO 7=週日 vs JS 0=週日）

- **觸發情境**：新店月結課（週日 10-12／13-15）續約後繳費通知金額顯示 0 元（in-app #190／GitHub #1096）。`StudentClass` 鏈 1695/1696→2026/2027 連續兩期 `SessionCount=0、Charge=0`，Invoice `TotalAmount=0`；主任被迫手動核帳又登進 0 元。
- **根因**：`buildSessionsFromWeeklySchedule` 以 Carbon `dayOfWeek`（0=日…6=六）比對 slot weekday，但所有活躍呼叫端（`resolveScheduleSlotsForRebuild` 讀 `week` 欄、`day_time_slots`、EnrollmentService `days_of_week`）都傳 ISO（1=一…7=日）。週一～六兩套慣例數值恰好相同 → 平日全部正常、**只有週日**永不匹配，錯誤潛伏到有人排週日月結課才爆。
- **強制規則**：
  1. DB `week/week1-6` 欄位與 `day_time_slots.day` 一律存 **ISO 1-7（7=週日），不可存 0**（production 無 `week=0` 資料）。
  2. 任何 weekday 比對前先過 `StudentClassController::isoWeekday()`（0→7），再與 `dayOfWeekIso` 比；禁止裸用 `->dayOfWeek` 對 slot。
  3. 新增/修改排課生成邏輯時，測試必含**週日 slot** 案例（兩套慣例只在週日分歧，平日測試永遠測不出）。
- **測試必補**：`WeeklyScheduleSundayBuilderTest`（ISO 7、legacy 0、平日不變、混合）＋ `MonthlyRenewTest::test_renew_monthly_sunday_course_computes_sessions_and_charge`（invoice 金額不為 0）。


---

### R66. session-dates projected 不可因排除 leave materialized 而在同日合成幽靈時段

- **觸發情境**：週日 10-12 堂次制課程登記請假後，課程詳情「上課日期」除正確的 10-12 請假 chip 外，又多出半透明 16-18 請假（in-app #196／GitHub #1101，劉芯岑 SC2653）；DB 查無 16-18 ClassSession。
- **根因**：`SessionProjectionReadService::collectMaterializedFromRows` 把 `leave` 排除 → 請假日無 materialized → `buildProjectedFromEffectiveDates` 仍從契約日期合成 projected；POST `session-dates` 的 `bodyClasses` select 缺 `time`/`SessionDuration` → `resolveSlotTimesForCourseDate` fallback 全域預設 16:00。
- **強制規則**：
  1. `leave`/`leave_requested`/`leave_adjusted`/`excused` 等有實體 ClassSession 的狀態必須進 **materialized** bucket（只排除 `cancelled`）。
  2. 任何 `StudentClass::select()` 用於 slot time 解析時，必須含 `time`/`time1-6` + `SessionDuration`/`duration1-6`。
  3. 修改 projected 邏輯時，測試必含「請假日只有真實 leave 列、projected 為空、無 16:00 fallback」。
- **測試必補**：`SessionProjectionLeaveGhostTest::test_leave_session_materialized_and_no_phantom_projected_slot`。

---

### R63. 未合併分支的 migration 絕不可先在 production 執行（schema drift = 全域隱形地雷）

- **觸發情境**：2026-06-30 某 session 在未合併分支 `815ad275`（借用舊分支名 `fix/branch-list-trust-api` push）直接於 production 執行 `drop_student_class_room_id_legacy` 等 2 個 migration（batch 107/108），`StudentClass.RoomID` 欄位被移除；分支其後從未 merge。2026-07-08 才發現 main 程式碼仍在讀寫 RoomID：課程匯出（明確 SELECT）必 500、多個寫入路徑靠 fillable 靜默丟棄或 `createStudentClassResilient` 的 retry 掩蓋。CI 測試 DB 與 production schema 不一致長達 8 天。
- **根因**：Actions minutes 凍結時期的手動部署便宜行事：migration 跟著工作分支上了 production，但 code 沒有跟著走完 PR→merge，違反 R5（migrate 只能在 PR merge 後由 deploy.yml 執行）。復原時只 `git reset --hard origin/main` 還原 code，卻無法還原「migration 已執行」的事實。
- **強制規則**：
  1. migration 檔案在 production 執行的唯一合法前提＝**該檔案已在 `origin/main`**。
  2. 發現 schema drift 時，修復方向優先「把 migration + 配套 code port 回 main」，不是回滾 production schema（資料已依新 schema 寫入）。
  3. 手動緊急部署（minutes 凍結）也必須：merge 進 main 之後才跑 migrate —— 見 `.cursor/plans/urgent_login_attendance_leave_handoff_2026-06-20.md` §0 的授權流程。
  4. 接手任何 session 前先 `git log origin/main..<分支>` 檢查有無「已在 prod 生效但未合併」的 migration。
- **測試必補**：`StudentClassRoomIdSchemaDriftTest` 鎖 `Schema::hasColumn('StudentClass','RoomID') === false`＋Export SELECT/headings 對齊；未來刪欄位一律加同型 drift 測試。

---

### R50. 智慧行事曆載入不可 REST 成功後再跑 legacy fallback

- **觸發情境**：SmartCalendar 初次載入或切週體感偏慢，多個分校老師反映等待時間過長。
- **根因**：`SmartCalendar.loadCourses()` 已從 Laravel REST 取得 `student-classes` / `schedules` 後，仍執行 legacy Supabase fallback；同時週資料窗口為 ±42 天，導致 `/class-sessions` payload 過大。
- **強制規則**：Laravel REST 成功時不可再查 legacy Supabase fallback；週檢視 `ClassSession` / `schedules` prefetch buffer 預設為 ±21 天，若要放寬必須有回歸測試與實測理由。
- **測試必補**：`calendarLoadPerformance.test.js` 覆蓋 fetch window 與 fallback gating；修改 SmartCalendar 載入路徑時必跑 `npm run test:calendar`。

---

### R51. AI 處理 in-app bug 回報必須先讀 `bug_report_attachments` 與 reporter 全部歷史

- **觸發情境**：2026-05-17 AI 處理 in-app bug #107 時只看 `bug_reports.description`，未查 `bug_report_attachments`，導致回覆裡叫使用者「請補一張截圖」——但其實 reporter 提交時就已經附了 2 張截圖（attachment id 75/76）。使用者必須再提醒 AI 一次，浪費往返。
- **根因**：AI 預設用最少欄位推論，`bug_report_attachments` 是另一張表（不在 `bug_reports` 主 row 上），需要 JOIN 才看得到。同時也忘記檢查 reporter 的歷史回報（同性質的 #101 兩小時前才剛 resolved）與跨分校狀態（reporter 切分校會看不到舊單，#106 的根因）。
- **強制規則**：處理任何 in-app bug 回報前一定要 SQL 撈：
  1. `bug_report_attachments WHERE bug_report_id = ?` — 有附件就 SCP 下來看
  2. `bug_reports WHERE reporter_user_id = ? ORDER BY id DESC` — 看是不是跨分校或同主題回歸
  3. `bug_report_comments` / `bug_report_status_logs` 全部歷史 — 看之前怎麼回過、PR 修了什麼
- **動作流程**：撈完資料 → 用 SQL 驗證假設 → 再留言／開 GitHub issue。**禁止**只憑程式碼推論就回覆使用者。
- **強制更新**：如果發現附件，GitHub issue 要寫進 attachment id 與內容描述，方便下個 AI 不必重撈也能讀懂。
- **詳細 SOP**：見 `docs/CHAT_BUG_SYSTEM.md` §3.6–§3.7。

---

### R53. 修完 in-app bug 上線後必須回寫 Bug 回報系統（不可只關 GitHub）

- **觸發情境**：2026-05-23 分診流程已建立「開 issue + in-app 回覆」，但 AI 容易在 PR merge 後只更新 GitHub／CHANGELOG，忘記在 App 內留言請回報者驗收，老師以為沒下文。
- **強制規則**：
  1. **分診**（未改 code）：`triaged` + **公開**留言（含 GitHub 連結、已看附件 id）— 見 `CHAT_BUG_SYSTEM.md` §3.7 Phase A。
  2. **上線後**（有 deployable 修復）：`resolved` + **公開**留言（請按「確認已修好／問題仍存在」）— 見 §3.7 Phase C；等 `reporter-verify` 才視為結案。
  3. 禁止只 `Closes #nnn` 而不動 in-app 狀態／留言。
- **詳細 SOP**：`docs/CHAT_BUG_SYSTEM.md` §3.7（Claude／Cursor 同適用）。

---

### R59. 共用 `useScrollLock` 的 lock/unlock 必須在元件卸載時平衡（否則整頁灰白遮罩、無法點選）

- **觸發情境**：2026-05-31 in-app #143 / GitHub #600：主任先展開／聚焦某學生課程後切換頁面，整頁變灰白、無法點選也無法捲動。
- **根因**：`useScrollLock` 是**模組級 reference count**，`lockScroll()` 對 `body` 套 `position:fixed; overflow:hidden`。`CourseManagement.vue` 聚焦學生時 `lockScroll()`，但 `focusedStudentKey` watcher 只在 `key→null` 解鎖；使用者在「聚焦中」直接換頁 → `onUnmounted` 不觸發 watcher → count 不歸零、body 永久凍結，且因 count 是跨元件共用，之後每一頁都被凍結。
- **強制規則**：
  - 任何呼叫 `lockScroll()` 的元件／composable，必須保證**所有離開路徑**（含 `onUnmounted`、錯誤 early-return、route 切換）都有對應的 `unlockScroll()`；不要假設 watcher 一定會在卸載時觸發。
  - App 層換頁（`watch(active)`）應呼叫 `forceUnlockScroll()` 作為防護網，確保任何頁洩漏的鎖都不會殘留到下一頁。
  - 開啟 modal/overlay 用 `body position:fixed` 鎖捲動時，務必 lock 與 unlock 成對；多個 overlay 疊加靠 reference count，但「洩漏一次」就會永久卡死。
- **測試必補**：`frontend/src/lib/useScrollLock.test.js`：基本平衡、nested count（兩 lock 需兩 unlock）、`forceUnlockScroll` 能清除洩漏；納入 `build` 測試鏈於 CI。
- **大廠對齊**：body-scroll-lock 類函式庫（如 `body-scroll-lock`、Radix `RemoveScroll`）都以 reference count + 明確 `enable/disable` 成對使用為前提；元件卸載必清，否則 SPA 換頁後鎖殘留是已知陷阱。

---

### R61. UI 去 AI 化大量 codemod／逐頁治理的踩坑合輯（2026-06-14 #687 系列）

一輪治理 ~25 個前端檔 + 多功能 PR 時反覆踩到，記錄防再犯：

- **design hex guard 會把註解裡的 `#NNN` issue 引用當成色票**：3–4 位十六進制的 issue 編號（如 `// #765`、`#702`、`#708`）會被 `scripts/check-no-raw-hex.sh` 計為新增 raw hex。在**已治理到 0 hex 的檔案**新增註解時，一律寫「issue 765」不要寫「#765」。
- **codemod 必須限定區域**：自動把 hex→`var(--ds-*)` 的 codemod**只能作用於 `<style>` 區塊 + inline `style=""`/`:style` 綁定**；絕不可全檔替換，否則會改到 (a) 註解／正文的 `#NNN` issue 引用、(b) JS 功能色板（avatar/teacher/軍階識別色）、(c) chart canvas 色（canvas 不吃 CSS var）。功能性多態識別色（如 `TEACHER_AVATAR_PALETTE`、`RocRankBadge` 軍階色）刻意保留 raw hex，屬 TD-064 例外。
- **branch 命名**：presubmit CHECK 1 只允許 `feat|fix|hotfix|chore|exp` + `td-batch<N>-` + `dependabot/`。**`docs/`、`ci/` 都會被擋** → 文件/CI 改動用 `chore/`。
- **PR size**：presubmit CHECK 2 硬上限 **700 行**（含增刪，排除 lock/data）。3 頁合一的治理 PR 容易爆（曾 868 行被擋）→ 一頁/數個小元件一 PR。
- **single-line JSON baseline 衝突**：`docs/design-hex-baseline.json` 是單行；多個治理 PR 各自 relock 會在合併時衝突。**逐頁/批次 PR 不要各自帶 baseline**，全部 merge 後做**一次** `bash scripts/design-hex-count.sh > docs/design-hex-baseline.json` 統一 relock。
- **`backend/public/storage` symlink 會卡住 `git reset --hard` / `git merge`**（WSL/Windows 掛載：`Function not implemented` / `File exists`）→ 改用 `git reset --mixed` 移 HEAD（不寫工作樹）再清殘留；勿對 protected 路徑設 `assume-unchanged`（R58 + pre-commit hook 會擋）。
- **merge-train 稅**：strict required checks + 單一 self-hosted runner，每 merge 一個 PR 其餘變 `BEHIND` 需 `update-branch` 重跑 CI；大量小 PR 會排很久。**勿用 `gh pr merge --admin` 繞過**（CI 會抓真問題，如 cebed0c flaky）。耐心 merge-train，或盡量合批。
- **`backend/phpunit.xml` 以 `force="true"` 硬編 `DB_DATABASE=AllTrue_test`**：CI 測試 DB 名無法只靠 env 隔離；self-hosted runner 共用此 DB，多 run 並發時 `RefreshDatabase` 互相清表 → 偶發假失敗（自癒）。修法須動態 patch phpunit.xml（見 #732 註解）。

---

### R60. 新增 API 路由必須確認落在 `role` + `require_campus` 認證群組內（不可裸放在群組外）

- **觸發情境**：2026-05-31 開發家長回饋雙向回覆時審查發現，System B 的 `parent-feedback/{for-teacher,read,reply,replies}`（#409/#410）被加在所有 `role:`/`require_campus` 群組**之外**，只剩全域 `AttachAuthUser`（只附掛 user、不強制認證/授權）→ 等同未認證即可呼叫的端點。所幸前端 0 引用，未被利用。
- **根因**：`routes/api.php` 很長且巢狀多個 `Route::middleware([...])->group(...)`；在群組**結束後**（`});` 之外）接著寫新路由，會誤以為仍在群組內，實際已落到無認證區。
- **強制規則**：
  - 新增任何需登入的端點後，**必看它前後的 `});` 與縮排**，確認確實在預期的 `role`/`require_campus` 群組內；員工端最少 `role:...` + `require_campus`，家長端 parent token + ownership + `throttle`。
  - 寫權限/越權測試（403 跨師、403 跨校）才算完成；不要只測 happy path。
  - Code review 對 `routes/api.php` 的 diff 必須逐條確認所屬群組，不可只看路由字串對不對。
- **本次處置**：四個 System B 端點收斂進 `role:teacher,director,super_admin`+`require_campus`+`require_password_change`；per-row campus ownership 仍待補 → `TECH_DEBT` TD-056。新做的 System A 回覆端點一律放在既有 `role:teacher,director,super_admin`+`require_campus` 群組並做 per-row ownership（`authorizeStaffFeedback`）。
- **大廠對齊**：OWASP API Top 10 之 API1 BOLA / API5 Broken Function Level Authorization — 端點預設拒絕、明確授權；路由表應以「群組預設帶 auth」而非「逐條補 auth」設計，降低漏網。

---

### R57. `StudentClassController::sessionDates()` self-week fallback 不可用 array key 存取（merge 會 reindex）

- **觸發情境**：2026-05-23 in-app #126 / GitHub #497：施景媛 SC#1841 設定好的 24 堂課，課程管理只顯示已實體化的 ClassSession 日期，後續週期堂次全部消失。
- **根因 1**：`sessionDates()` body path 的 fallback 鏈只覆蓋 (a) request `days_of_week` (b) 同 package sibling 的 days，沒覆蓋 (c) 該課自身 `week, week1..week6`；月度課的 `bodyCourses[].days_of_week` 為空時整批掉空。
- **根因 2**：上方 `$bodyClasses = $bodyClasses->merge($packageSiblings)` 會走 `array_merge`，把整數鍵全部重新索引成 `[0, 1, 2, ...]`；新加的 fallback 若用 `$bodyClasses[(int) $cid]` 永遠拿不到該課（測試一直回 0）。
- **強制規則**：
  - sessionDates fallback 鏈必須包含 self-week (`week, week1..week6`) 這層；不能假設前端一定會帶 `days_of_week`。
  - 任何在 `merge()` 之後對 collection 的 key lookup，一律用 `firstWhere('ID', $id)` 或自己 `keyBy('ID')` 一次，**不可用** array key 存取整數 ID。
- **測試必補**：`SessionDatesSelfWeekFallbackTest` 三案：(1) self-week 24 堂全產出 (2) sibling fallback（#440 回歸）仍生效 (3) request `days_of_week` 仍 precedence 最高；fixture 必須 `ScheduleMode='count'`，否則 GET path 會用 ClassSession 覆寫成空。

---

### R56. 課程管理 / 行事曆不可顯示內部 `cancelled-duplicate-reschedule-placeholder`

- **觸發情境**：2026-05-23 in-app #124 / GitHub #496：調課完成後課程管理同時段多出一筆「取消」狀態的同課堂次，老師誤以為系統 double book。
- **根因**：調課流程會在 `ClassSession` 留下 `Status='cancelled'` + `Note='...cancelled-duplicate-reschedule-placeholder'` 的內部 bookkeeping row，但 `ClassSessionController::index()` 直接連這些 row 一起回給前端。
- **強制規則**：所有走 `GET /api/v1/class-sessions` 的列表（課程管理、行事曆、補課空檔）預設必須過濾掉 `Status='cancelled' AND Note LIKE '%cancelled-duplicate-reschedule-placeholder%'`；只有稽核需要時用顯式 `include_internal_placeholder=1` 帶回。
- **測試必補**：`ClassSessionPlaceholderHideTest`：(1) 預設不回傳 placeholder (2) 一般取消堂次仍可見 (3) 顯式 opt-in 才回 placeholder。

---

### R55. 學習評量 `VoidedAt` 必須與 `ClassSession.Status` 對齊（resurrect-on-write）

- **觸發情境**：2026-05-23 in-app #125 / GitHub #495：沈宇璿評量提交 409，原因是先前因「一般請假」自動連動 cascade void 了 LR，但同堂 ClassSession 後續又被改回 `attended/scheduled`，DB 留下 `VoidedAt!=NULL` 但堂次活著的孤兒 LR。
- **2026-05-31 延伸 in-app #146 / GitHub #618**：陳嘉軒 5/31 12:30-14:30（LR#7737, CS#9426）評量被作廢無法填寫。根因同類但 **VoidReason 不只「一般請假」**：`ClassSessionController` attended→scheduled 以 `voidAttendanceArtifacts('由已上調整狀態')` 作廢並沖回堂數；之後 scheduled→attended 走 generic 分支不還原（`restoreVoidedLearningRecord` 僅 `leave→attended` 觸發）。原 `=== '一般請假'` 字串相等判斷漏掉 `'由已上調整狀態'` → 老師永久 409。
- **強制規則**：`LearningRecordController::store()` 遇到既有 LR `VoidedAt!=NULL` 時，必須檢查 `VoidReason` 屬 **`SYSTEM_RESURRECTABLE_VOID_REASONS` 白名單**（`一般請假`/`由已上調整狀態`/`補請假：已上課改請假`/`單堂標記請假`，皆為系統 cascade 作廢且作廢當下已沖回堂數或該堂未扣堂）且 `ClassSession.Status` 屬於 fillable（`attended/scheduled/completed/late`）→ 自動 resurrect（清 void 欄位、轉 `pending`、用新 payload 覆寫）；其他情境（手動作廢、真實取消 / leave）維持 409 拒絕。**新增系統作廢原因時，務必同步加入此白名單**（用 `in_array` 不要再寫死單一字串）。
- **測試必補**：`LearningRecordVoidedResurrectTest`：(1) cascade voided（一般請假）+ 堂次 attended → 200 + LR 復活 (2) 手動作廢（VoidReason 非白名單）仍 409 (3) 堂次仍 leave/cancelled 仍 409 (4) #146：`由已上調整狀態` + 堂次 attended → 200 + 復活 + `SessionDeducted=false`。
- **副作用提醒**：resurrect 後不可自動再扣 `RemainingSessions`（扣堂仍走 `LearningRecord approved → AttendanceEffectsService` 路徑）；本規則只是把卡關打開，不改業務語意。白名單原因在作廢時都已沖回堂數，resurrect→pending→核准會 net-correct 扣 1 堂。

---

### R58. 禁止對 tracked 原始碼使用 `assume-unchanged` / `skip-worktree`（2026-05-24 #527 延伸）

- **觸發情境**：`CourseManagement.vue`、`branch-hygiene.sh` 被設 `assume-unchanged`（`git ls-files -v` 顯示 `h`），working tree 已改但 `git status` 乾淨 → #527 主任端 UI 未進 PR #530，本地 WIP 被 AI 誤判「已上線」。
- **根因**：`git update-index --assume-unchanged` 原設計給**本機暫時**加速大 repo diff，不是 team 工作流；GitHub／Google 等皆靠 **PR + CI** 看 diff，不靠隱藏 index。
- **強制規則**：
  - ⛔ 禁止對 `backend/`、`frontend/`、`scripts/`、`.github/`、`docs/` 下任何 tracked 檔設 `--assume-unchanged` 或 `--skip-worktree`。
  - 開工前／§B5 週例：`./scripts/git-index-audit.sh`（或 `git ls-files -v | grep '^[hs]'` 必須為空）。
  - AI 若 `git show HEAD:path` 與磁碟 `path` 不一致但 `git status` 無該檔 → **先** `git update-index --no-assume-unchanged path` 再繼續。
- **大廠對齊**：可見 diff + required checks（§B5）；本地藏檔 = 繞過 review gate，等同未審即改 production 路徑。

---

### R54. Bug 回報 `reporter-verify` 必須與 `show()` 共用跨分校授權（#378 延伸）

- **觸發情境**：2026-05-23 回報者對 `resolved` 單按「確認已修好」出現 HTTP 404；列表／詳情可開，但 `POST reporter-verify?branch_id=` 當前分校與回報分校不同時誤拒。
- **強制規則**：`reporter-verify`、`addComment`（回報者）等寫入路徑必須走與 `show()` 相同的 `canAccessBug`／`belongsToCampusForReporter`；不可只用 `resolveCampusIds` + `belongsToCampus`。
- **測試必補**：`BugReportApiTest::test_reporter_can_verify_resolved_bug_from_another_branch_with_branch_id`。

---

### R52. 代課／調課例外必須保留原 occurrence anchor（不可讓 `scheduled.original_schedule_id` 為 NULL）

- **觸發情境**：2026-05-17 in-app bug #108：吳艾潼 5/17 10:00 化學一對二已先調課、再追加代課給鄭翔祐；`schedules` 留下 `scheduled.original_schedule_id=NULL` 的代課 row，導致行事曆仍掛在鄒宇旻欄，但代課候選又把鄭翔祐算成已滿／衝堂。
- **根因**：單堂 exception 沒有穩定 anchor。Google Calendar / Microsoft Graph / CalDAV 都用 `recurringEventId + originalStartTime`、`seriesMasterId + originalStartTime` 或 `UID + RECURRENCE-ID` 對齊「原本那一堂」；AllTrue 對應欄位就是 `schedules.original_schedule_id`。
- **強制規則**：任何 `schedules.status='scheduled'` 且 `teacher_id != StudentClass.TeacherID` 的代課例外，都必須有非 NULL `original_schedule_id` 指向同課程同日的 `rescheduled` anchor；寫入路徑必須吸收並修補歷史 NULL anchor row，不可新增第二筆幽靈代課 row。
- **資料修復規則**：歷史資料只可用 `schedules:backfill-substitute-anchors --dry-run` 先列清單，再備份 `schedules` 表後 `--apply`；此 command 只可更新 `schedules.original_schedule_id` 與刪除同 anchor 的 ghost `rescheduled` row，不可碰 `ClassSession`、堂數或評量。
- **測試必補**：修改 `ClassSessionController::substitute`、`SubstituteService::collectTeacherBusySlotsWithCapacity` 或行事曆代課顯示時，必須覆蓋「先 pure reschedule、再 substitute」的 chained path，驗證不 409、不重複、不留下 NULL anchor。

---

### R45. 家長入口版本公告必須分眾（不可套用教職員全量 CHANGELOG 卡）

**觸發情境**：2026-05-10 家長反映「版本更新太長、與家長無關」，且進度中心出現內部向文案。

**根因**：`notesForRole('parent')` 曾用「只要 `audience` 含 director/teacher 就給家長」，等於把教職員向產品說明全部洗到家長手機畫面。

**強制規則**：

- `frontend/src/lib/releaseNotes.js`：`role === 'parent'` 時**只允許** `note.audience?.includes('parent')`；禁止改回「複製 director/teacher 全集」。
- `scripts/changelog-to-release-notes.mjs`：依當日卡片內**白話條目**關鍵字（家長、繳費、課表、請假…等）決定是否把 `parent` 加進該卡的 `audience`；調整規則後跑 `npm run test:release-notes`。
- `docs/CHANGELOG.md` 異動後必須重新產生 `frontend/src/lib/releaseNotes.generated.js`（`cd frontend && npm run sync-release-notes`；`vite build` / CI 亦會觸發）。
- `ParentPortal.vue`：家長端最多兩則、用 `parentReleaseNoteTeaser()` 做短摘要；**不要**把 `interaction_statuses`／內部待辦用語當作家長首屏資訊。

### R59. 扣堂改分鐘制權威後，`RemainingSessions` 是 ROUND_HALF_UP 衍生顯示值（#613）

**觸發情境**：2026-05-31 #613 落地「補課部分時數比例扣堂」。

**根因 / 設計**：扣堂權威單位由「堂數」改為「分鐘」。權威來源＝`StudentClass.PurchasedMinutes/RemainingMinutes` + `session_deduction_ledger.minutes`；`RemainingSessions` 變成由 `ROUND_HALF_UP(RemainingMinutes / perSessionMinutes)` 衍生的整數**顯示值**（整數運算、無浮點）。

**強制規則（改扣堂/堂數顯示前必讀）**：
- **唯一權威扣堂路徑**＝`SessionDeductionService::recomputeCounters()`；分鐘換算 chokepoint＝`deductOnAttendance`（自載 ClassSession，`clamp(EndTime−StartTime, 0..perSession)`，完整時長傳 `null`＝整堂、byte-identical）。
- 比例扣堂**只**作用於 `schedules.type='extra'` 補課且時長 < 每堂分鐘；正常課堂、完整時長補課一律整堂。**禁止**把規則擴大到所有堂次。
- 任何讀取端**不可**用 count-based observed 值覆寫已有「部分時數」（fractional `RemainingMinutes`）課程的 `RemainingSessions`（`StudentClassController::index` 已加 `hasFractionalBalance` 守門）；要顯示精確值用 `remaining_minutes`。
- `reverseForSession` 必須沖回對應 deduct 的 `minutes`（否則淨值漂移）。
- ⚠️ 共用課程包池鏡像（`PackageDeductionService`）尚未分鐘感知（TD-059）；`recalculateSessionCounters` 為死碼勿誤用（TD-060）。

**測試**：`SessionDeductionMinutesEngineTest`、`PartialMakeupDeductionTest`（含列表端點不被覆寫）。

---

## 模組對照索引（改特定模組前讀 Archive 對應條目）

> 改下列模組前，**先回本檔 §復發家族** 認領對應 F1～F6（狀態收尾/月結續期/排課生成/共用堂數/行事曆合併/輸入邊界），再讀以下細項。

| 模組 | 必讀條目（在 Archive） |
|------|----------|
| 堂數 / 扣堂 | §2026-04-17 繳費日期、§單堂費用固定、**§R59（分鐘制權威：RemainingSessions 為 ROUND_HALF_UP 衍生值，讀取端勿用 count 覆寫 fractional）** |
| 繳費 / 學收 | §繳費狀態 paid_at、§歷史課程漏算、§催繳名單六狀態、§幽靈課程、§R30（帳務入口共用 AR ledger） |
| 薪資 / 併堂 | §兼職薪資 concurrency、§同層級併堂 v1.4、§契約時長為準 |
| 代課 / 調課 | §代課Undo通知、§合併Undo還原時間、§雙層防護重複行、§atomic transaction、§R13（補課 schedule 不建 ClassSession）、§R39（代課評量權限需匹配時段）、§R43（調課目標 scheduled 例外以 anchor 去重）、§R44（代課顯示不可讓原老師 stale row 搶贏）、§R46（主任評量列表授課老師須與 effective 代課一致）、§R48（代課點名權限必須以時段級 effective teacher 為準）、§R52（代課 scheduled 例外不可缺 original_schedule_id anchor） |
| 評量 / 家長回饋 | §同天多堂課 buildEvents、§請假後不填評量、§R17（ownership 先於狀態判斷）、§R19（mark-read 不可更新 updated_at）、§R32（停用課程已上課評量不可消失）、§R39（代課評量權限需匹配時段）、§R46（主任評量列表授課老師須與 effective 代課一致）、§R65（新增 session 狀態值必須同步全部消費端；leave 家族用集合判斷） |
| 家長入口 UI / `releaseNotes` | §R10、§R11、§R18、§R38、§R45（版本卡僅 `audience` 含 `parent` + `sync-release-notes`） |
| 課表回報 | §2026-04-17 回報系統（14 條禁止項） |
| 排課 | §start_time 格式、§智慧排課誤標取消、§R25（請假優先於 scheduled 例外）、§R29（請假不可 fallback 只寫 schedules）、§R43（調課目標 scheduled 例外以 anchor 去重）、§R44（代課顯示不可讓原老師 stale row 搶贏）、§R47（rescheduled 幽靈不可蓋掉同日 ClassSession）、§R49（同學生同時段去重不可用 StudentClassID 當唯一 key）、§R50（行事曆載入不可 REST 成功後再跑 fallback） |
| 出缺勤 / 分校隔離 | §SEC-001、§分校隔離後端強制、§R12（查詢日期寫死今天）、§R14（submitQuickAttend 缺 StudentID）、§R15（出勤頁預設只顯示今天，歷史到班紀錄不可見）、§R16（`script setup` const TDZ 初始化順序 → 整頁空白）、§R33（老師每分校 RFID 優先）、§R36（個別資料有課但老師今日名單缺漏）、§R40（點名扣堂不可只用 ClassSessionID 防重）、§R41（補請假不可只用課程+日期找堂次）、§R42（行事曆堂次顯示老師不可被舊評量老師覆蓋）、§R48（代課點名權限必須以時段級 effective teacher 為準）|
| 月結制 / 加購 / 多科固定時段 | §b3 inactive 歷史、§b4 加購分流、§R21（堂數制加購是新批次）、§R22（月結詳情不可只依賴 ClassSession）、§R23（推算日期不可成為 dead-end chip）、§R24（多科固定時段優先走一般課程）、§R26（月結續報與堂數額度不可混在同一語意）、§R38（家長端繳費提醒不可套主任續課提醒） |
| routes/api.php | §AI 靜默回退路由（改前必讀完整檔案 + route:list） |
| 備份 / nightly | §nightly 覆蓋修正、§備份還原演練、§R34（備份新鮮度不可只看 mtime） |
| Bug 回報 / 附件存檔 | §R11 storage symlink（Archive）、§R51（分診前必查 attachments + reporter 歷史 + 跨分校）、§R53（上線後必回 in-app）、`docs/CHAT_BUG_SYSTEM.md` §3.6–§3.7 |
| Git / PR 工作流 | §R58（禁止 assume-unchanged 藏檔）、`scripts/git-index-audit.sh`、Epic #535 Phase 0 |
| Migration / schema drift | §R63（未合併分支的 migration 禁上 production；drift 修復＝port 回 main＋drift 測試） |
| 部署 pipeline | §R62（deploy 必須 fetch fail-fast + reset 到 CI `head_sha` 並校驗 HEAD；禁止 `reset --hard origin/main` 靠 stale tracking ref 靜默出貨舊版；Pi repo config 出現 `http.sslbackend=schannel` = 已被 Windows 工具污染，先 unset）、§R67（SSH script 關鍵步驟失敗必須標紅；migration 失敗不得吞成綠燈） |

---

> 新增事故：請直接寫到 [AI_REGRESSION_LESSONS_ARCHIVE.md](archive/AI_REGRESSION_LESSONS_ARCHIVE.md)，並更新上方黃/紅線（若升級為通用規則）。

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
