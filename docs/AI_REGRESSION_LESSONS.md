# AI／工程師防再犯紀錄（必讀）

本檔記錄**已發生過的產品／實作缺口**，避免下次改壞或改漏。  
**任何 AI Agent 或新進開發者**：請與 `AGENTS.md` 的 First-read 順序一併閱讀；修改下列模組前**先對照本檔**。

---

# ⛔⛔⛔ 開工前必讀摘要（30 秒讀完）⛔⛔⛔

> **本檔超過 2000 行。你不需要全部讀完，但這一節必須讀完。**
> 下面是從 33 條歷史事故濃縮出的 **5 條紅線** 和 **3 條黃線**。
> 違反紅線 = P0 故障。違反黃線 = 高概率 CI 失敗浪費時間。

## 紅線（⛔ 違反 = P0 故障，零容忍）

### R1. `/home/admin` 就是 production — 改檔案 = 改線上

```
/home/admin/backend/  ← nginx 直接 serve 的 document root
/home/admin/frontend/ ← npm run deploy 後 copy 到 backend/public/
```

- **feature branch 上修改既有 .php/.vue 檔案 = 即時影響 production**
- git checkout -b 不會隔離 working tree
- 唯一安全的寫入：**新增** test file（`tests/` 目錄）、新增 Export class、新增 migration
- 事故：§P0-005（2026-04-23）、§事故F（2026-04-23）

### R2. 禁止在 Pi 上跑測試（已發生 3 次 DB 清空事故）

```
❌ cd /home/admin/backend && php artisan test     ← 會 DROP production DB
❌ cd /home/admin/backend && vendor/bin/phpunit   ← 同上
❌ cd /home/admin/backend && php artisan config:clear  ← 全站 401
```

- 測試只能在 GitHub Actions CI 跑
- debug CI 失敗 → 改檔案 → push → 看 CI log，不要本機跑
- **包括「只跑單一測試檔」也禁止** — `RefreshDatabase` trait 不管你跑幾個檔案都會 DROP 全部表
- 事故：§2026-04-22 P0 最高級（DB 清空）、§2026-04-22 config:clear（全站 401）、§2026-04-23 事故E（全站 500）、**§P0-006（2026-04-23 二次 DB 清空）**

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

- 事故：§P0-005、§事故D（.htaccess）、§事故F

### R4. 還原必須完全還原

```
❌ 部分還原（「看起來有問題的先拿掉，其他留著」）→ 二次故障
✅ git checkout HEAD -- <file>  完整還原
```

- 事故：§事故D（移除 CSP 保留 nosniff → 第二次破壞）

### R5. 禁止 git push --force / 禁止直接 push main

- 見 `.cursor/rules/p0-never-force-push-and-deploy.mdc`
- 事故：§2026-04-21 事故A（force push 覆蓋 production）

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
- 事故：§TEST-001（反覆出現 4+ 次）、§TEST-004

### Y2. 測試用 `now()` + `addHours(N)` 跨午夜 → CI 22:00+ TWN 之後失敗

```php
// ❌ 危險：now() + 2h 在 22:01 TWN = 00:01 次日，EndTime "00:01" < "22:01" → session 窗口失敗
$endTime = now()->addHours(2)->format('H:i:s');

// ✅ 正確：setUp() 固定 10:00 AM，EndTime 永遠落在中午
Carbon::setTestNow(Carbon::today()->setTime(10, 0)); // in setUp()
```

- 同理：`start_time='23:00'` + `duration_minutes=30` → EndTime=23:30；CI 在 23:31 跑時 session 已結束 → 計數差一
- **修法**：任何需要「當下時間」的測試，在 `setUp()` 加 `Carbon::setTestNow(Carbon::today()->setTime(10, 0))`；`tearDown()` 加 `Carbon::setTestNow()`
- 事故：§TEST-FLAKY-001（2026-04-23，PR #36，影響 7 個測試跨 4 個 class）

### Y3. PhpSpreadsheet sheet 名稱不能為空

- 動態 sheet name 必須 guard 空字串，fallback 到 `"Sheet"` 或 `"老師{$id}"`
- 事故：§EXPORT-001

### Y4. 前端改了必須 build 才生效

- `npm run deploy` 只能在 **main branch + PR merged 後** 執行
- 忘記 deploy ≠ 功能消失，只是前端還在用舊 JS bundle
- 事故：§P0-005（功能做完但使用者看不到）

---

## 模組對照索引（改特定模組前讀對應條目）

| 模組 | 必讀條目 |
|------|----------|
| 堂數 / 扣堂 | §2026-04-17 繳費日期、§單堂費用固定 |
| 繳費 / 學收 | §繳費狀態 paid_at、§歷史課程漏算、§催繳名單六狀態、§幽靈課程 |
| 薪資 / 併堂 | §兼職薪資 concurrency、§同層級併堂 v1.4、§契約時長為準 |
| 代課 / 調課 | §代課Undo通知、§合併Undo還原時間、§雙層防護重複行、§atomic transaction |
| 評量 | §同天多堂課 buildEvents、§請假後不填評量 |
| 課表回報 | §2026-04-17 回報系統（14 條禁止項） |
| 排課 | §start_time 格式、§智慧排課誤標取消 |
| 出缺勤 | §分校隔離後端強制、§老師端敏感遮蔽 |
| 月結制 | §b3 inactive 歷史、§b4 加購分流 |
| routes/api.php | §AI 靜默回退路由（改前必讀完整檔案 + route:list） |
| 備份 / nightly | §nightly 覆蓋修正、§備份還原演練 |
| 老師管理 / 聊天 | §AttachAuthUser teacher_branches |

---

# 以下為完整事故紀錄（33 條）

---

## §TEST-001 — AI 寫測試時遺漏 NOT NULL 欄位導致反覆 CI 失敗

### 問題模式

AI 在寫 Feature Test 時用 `DB::table()->insert()`、`Model::create()` 或 Factory 插入測試資料，但**漏填 NOT NULL 且無預設值的欄位**，導致：
- `SQLSTATE[HY000]: General error: 1364 Field '...' doesn't have a default value`
- 每修一個 CI 才能發現下一個缺漏欄位，造成多次「fix: add XXX to insert」的無效 commit

### 已知反覆出現的欄位缺漏（2026-04 統計）

| 表 | 必填欄位（無預設） | 常見最小值 |
|---|---|---|
| `Teacher` | `CampusID`, `T_Name`, `TelegramID` | `CampusID` 用 Campus.id；`TelegramID` 給 `''` |
| `StudentClass` | `StudentID`, `GradeID`, `SubjectID`, `TeacherID`, `by1`, `StartDate`, `RoomID` | `by1=1`, `RoomID='1'`, `StartDate=now()` |
| `Student` | `name`, `CampusID`, `ClassID`, `TelegramID` | `ClassID=1`, `TelegramID=''` |
| `ClassSession` | `StudentClassID`, `SessionDate`, `StartTime`, `EndTime` | 皆為業務欄位，無法給假值 |
| `LearningRecord` | `StudentClassID`, `ClassSessionID`, `TeacherID`, `Content` | `Content='test'` |
| `schedules` | `student_id`, `day_of_week`, `start_time`, `end_time`, `status`, `type`, `branch_id` | `status='scheduled'`, `type='normal'` |
| `User` | `LoginName`, `Name` | 給唯一 email |
| `UserCampus` | `CampusID`, `UserID` | 皆為關聯鍵 |
| `Campus` | `name`, `LineNotifyID`, `Client_ID`, `Client_Secret`, `LIFFID`, `LIFF_URL`, `URL`, `TelegramURL`, `TeachLIFFID`, `TeachLIFF_URL` | 如非測試 Campus 業務，用 Factory |

### 防再犯規則

1. **寫測試前先查 NOT NULL 欄位**：
   ```sql
   SELECT COLUMN_NAME, DATA_TYPE, COLUMN_DEFAULT
   FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA='AllTrue'
     AND TABLE_NAME='<表名>'
     AND IS_NULLABLE='NO'
     AND EXTRA NOT LIKE '%auto_increment%'
   ORDER BY ORDINAL_POSITION;
   ```

2. **禁止只填「看起來夠用的欄位」再 push CI 觀察錯誤**：
   - 每次插入新表之前，先確認所有 NOT NULL 且無預設的欄位都已填入
   - 看到 `SQLSTATE 1364` = 立刻往上查表結構，一次補齊所有缺欄，不要一次只補一個

3. **用 Eloquent Model::create() 時先確認 `$fillable`**：
   - `$fillable` 沒列的欄位 create 時會被忽略 → 若是 NOT NULL 會炸
   - 檢查 `$fillable` 清單 vs 表的 NOT NULL 欄位是否完全對齊

4. **TeacherID / teacher_id / CampusID 是最常漏的三個**：
   - 建立 `Teacher` row 必須含 `CampusID` + `TelegramID`（char not null）
   - 建立 `StudentClass` row 必須含 `by1=1`, `RoomID='1'`（含舊欄位）

5. **Campus 若非測試必要，用 `CampusFactory::new()->create()`**，不要 raw insert（Campus 有 10 個以上的 NOT NULL 欄位）。

### 根本記憶點

> **一筆 DB::table()->insert() 或 Model::create()，必須和 `SHOW COLUMNS FROM <表>` 對照，NOT NULL + 無預設 = 必填。沒有例外。**

---

## §TEST-002 — Factory 建出的 User 不一定有對應 Teacher/Director 表 row

### 問題模式

`UserFactory::teacher()->create()` 只設 `User.type='T'`，**不會**在 `Teacher` 表建行。  
任何依賴「`User.type='T'` → 必有對應 `Teacher` row」的查詢（例如 `whereNotExists` 排除 Teacher 的 SQL）在 Factory 資料下**不會觸發**，導致測試結果與 production 不符。

### 實際發生案例（Issue #6, 2026-04-22）

- `DirectorAccountController::pending()` 加了：
  ```php
  ->whereNotExists(fn($q) => $q->select(DB::raw(1))->from('Teacher')->whereColumn('Teacher.id','User.id'))
  ```
- 測試用 `UserFactory::teacher()->create()` 建了一個 `type=T` 的 pending teacher
- 因為 Factory 沒有在 `Teacher` 表建行，`whereNotExists` 沒有觸發 → pending teacher 仍出現在回應裡
- 測試繼續失敗，直到明確用 `DB::table('Teacher')->insert(...)` 補上 Teacher row

### 防再犯規則

1. **任何 Feature Test 中用 `User` Factory 建 teacher / director 帳號，必須同步確認對應的跨表 row 是否存在**：

   | UserFactory method | 需要同步建立 |
   |---|---|
   | `::teacher()->create()` | `Teacher` table（需 `id`, `CampusID`, `T_Name`, `TelegramID`） |
   | `::director()->create()` 或 `::admin()->create()` | `UserCampus` table（需 `UserID`, `CampusID`, `Approved`） |
   | `::student()->create()` | 依業務：`Student` table 或 `StudentClass` |

2. **寫涉及跨表 JOIN / whereNotExists 的查詢，測試資料必須真實反映 production 的資料格局**：
   - production 所有 `type=T` User 都有 Teacher row → 測試裡 type=T 的 User 也要有 Teacher row
   - production 所有 approved 的 director 都有 UserCampus row → 測試裡也要建

3. **AI 如果要加一個 `whereNotExists(teacher)` 的控制器改動，必須同步評估所有現有測試中的 type=T User 是否有 Teacher row，否則這些測試會靜默誤判**。

### 根本記憶點

> **Factory 只建 User 表的那一行。跨表的業務資料（Teacher、UserCampus、Student、StudentClass）一律要手動補建或用更完整的 Seeder。**

---

## §2026-04-22 — ⛔⛔⛔ P0 最高級事故：AI 在 production backend 跑 `php artisan test` 把生產 DB 清空

### 事故時間軸

- **00:40** AI 為了 debug GitHub Actions CI 失敗，在 `/home/admin/backend/` 執行 `php artisan test`
- **00:42** `RefreshDatabase` trait 對 `.env` 指向的 DB（= production `AllTrue`）執行 `migrate:fresh`
  - `DROP TABLE` 所有生產資料表
  - 重新 migrate + seed 測試 fixture
  - 產出 `testing.INFO: parent.login.success {"student_id":35}` 等 log
- **00:45** `db-alert` cron 偵測到 `ClassSession 暴跌：5446 → 0`、`StudentClass 633 → 0`、`Student 395 → 1` → 寫入 CRITICAL log
- **00:47** 使用者回報 401 登入失敗
- **00:48** AI 發現 DB 被清空，立即快照現狀 → 從 `sixhour/alltrue_6h_2026-04-21_2300.sql.gz` 還原
- **00:50** DB 還原完成，行數回到 395/633/5446，系統恢復
- **資料損失視窗**：2026-04-21 23:00 → 2026-04-22 00:42（約 1 小時 42 分鐘）

### 根本原因

AI 的 `backend/tests/bootstrap.php` 嘗試用 `putenv('APP_ENV=testing')` 強制環境為 testing，但**完全沒保護 `DB_DATABASE`**：

- `phpunit.xml` 的 `<env name="DB_DATABASE" value="AllTrue_test" force="true"/>` 確實生效
- **但是** Laravel 的 DB connection manager 在 bootstrap 階段可能已用 `.env` 的 `DB_DATABASE=AllTrue` 建立連線
- 更關鍵：**AI 在 production 檔案系統上執行 `php artisan test`，`.env` 就是 production `.env`**
- 任何防護層（`<env force="true">`、`bootstrap.php`）都是「軟防護」，只要一個環節失誤就直接打中 production DB

### 二次原因（AI 不該犯的判斷錯誤）

1. **違反了 2026-04-22 凌晨剛寫的 `config:clear` 禁令**：規則檔明明寫「要 debug Laravel 行為必須用獨立 clone」，AI 一小時後還是直接在 production 目錄跑 `php artisan test`
2. AI 把 `php artisan test` 誤認為「無害的本機測試」，忽略 `RefreshDatabase` = `DROP ALL TABLES` 的破壞性
3. AI 為了「讓 CI 通過」，連續多次修改 production 的 `phpunit.xml`、`tests/bootstrap.php`、`TestCase.php` — 每次修改都累積風險
4. **AI 當天已經炸兩次**：18:22 改 `.env` 刪 `DEPLOY_SECRET`、00:40 跑 `config:clear`，第三次直接清空 DB。應該在第一次事故後就停手

### 防再犯規則（⛔⛔⛔ 最高級，任何 AI 違反 = 立即停止）

1. **絕對禁止**在 `/home/admin/backend/` 執行 `php artisan test`、`phpunit`、`vendor/bin/phpunit` 或任何會載入 `RefreshDatabase` / `DatabaseTransactions` / `DatabaseMigrations` trait 的指令。**沒有例外**。
   
   **要跑測試 = 用獨立 clone + 獨立 `.env`**：
   ```bash
   # 安全 debug 流程
   cp -r /home/admin/backend /tmp/backend-test
   cd /tmp/backend-test
   cat > .env.test-override <<EOF
   APP_ENV=testing
   DB_DATABASE=AllTrue_test
   EOF
   # 用 .env.test-override 覆蓋 .env
   cp .env.test-override .env
   php artisan test
   # 測完整個 /tmp/backend-test 丟掉
   rm -rf /tmp/backend-test
   ```

2. **CI debug 一律在 GitHub Actions 端完成**：
   - 修 `.github/workflows/ci.yml` + push → 看 CI log
   - **不要嘗試「先本機跑通再 push」**，Pi 的檔案系統就是 production
   - CI runner 是一次性 Ubuntu，跑 `RefreshDatabase` 不會影響任何 production 資料

3. **任何一個 PHP 檔案在 production 目錄下運行時，必須視為 production 代碼**：
   - `phpunit.xml` 的 `<env>` 值只是 hint，不是 guarantee
   - `tests/bootstrap.php` 的 `putenv` 只是 hint，不是 guarantee
   - **唯一的 guarantee 是：不要在 production 目錄跑測試**

4. **每天發生 1 次 production 事故 = 立刻停止所有 production 操作**：
   - 告知使用者「我今天已經出過 X 次包，剩下的工作請明天做」
   - 不要試圖「補救」自己造成的問題 — 補救通常會造成第二個問題
   - 進入「只讀 + 告知」模式，除非使用者明確授權

### AI 執行指令前必答清單（硬性）

每次在 `/home/admin/backend/` 下執行 `php` / `php artisan` / `composer` 指令前，必須明確回答：

- [ ] 這個指令是否會讀 `.env`？→ 是 → **該指令一定作用在 production**
- [ ] 這個指令是否會寫入任何資料表？→ 是 → **停，除非部署或使用者明確授權**
- [ ] 這個指令是否會 DROP / TRUNCATE / migrate fresh？→ **絕對停**
- [ ] 這個指令是否是為了「我自己 debug」？→ 是 → **停，改用 `/tmp` clone**
- [ ] 使用者是否明確要求「現在就在 production 上跑這個指令」？→ 否 → **停**

### 事故代價

- 生產 DB 被清空 1 小時 42 分鐘的資料損失視窗（萬幸 sixhour 備份還在）
- 使用者當天遭遇 3 次 P0（`.env` 被改壞 + `config:clear` + DB 清空）
- AI 信任基本歸零
- 備份機制（sixhour + nightly）經過真實考驗 → 證明 2026-04-21 建立的 `gdrive-backup-sync.sh` + `sixhour` 備份確實救了這次

### 正面後果

- 備份機制經過真實災難驗證，恢復時間 < 10 分鐘
- 事故紀錄讓未來所有 AI 讀到這份檔案時會被強制擋住同類操作

---

## §2026-04-22 — P0 事故：AI 在 production backend 跑 `config:clear` 導致全站 5 分鐘 401

### 事故時間軸

- **00:40** AI 為了在本機 debug GitHub Actions CI 失敗，在 `/home/admin/backend/` 執行 `php artisan config:clear`
- **00:41** 使用者瀏覽器收到大量 401 錯誤（`/api/v1/auth/login`、`notifications/unread-count`、`chat/unread-count`、`bugs/unread-badge`、`schedule-discrepancies/summary`、`directors/pending`）
- **00:43** 使用者回報事故
- **00:43** AI 執行 `config:cache` + `route:cache` + OPcache flush，系統恢復
- **停機時間**：約 5 分鐘

### 根本原因

**AI 混淆了「本機 debug 環境」與「production backend」**。

Pi 的檔案系統上，`/home/admin/backend/` **就是**正在服務生產流量的 Laravel app；不是 clone 出來的測試副本。任何 `php artisan` 指令直接對生產環境生效：
- `config:clear` 清掉 `bootstrap/cache/config.php`
- Laravel 8 在 production 模式下依賴 config cache 才能正確解析 `session.driver`、`sanctum.stateful`、`auth.guards` 等設定
- cache 消失瞬間，登入流程的 session 解析出錯 → `/api/v1/auth/login` 變成 302 redirect（被路由到 web routes 而非 API routes），前端收到 HTML 無法解析 → 登入失敗 → 所有需要 token 的 endpoint 全變 401

### 二次原因（本次事故加重因素）

1. 今天稍早（2026-04-21）AI 已經在 `.env` 修改中「不明原因刪除 DEPLOY_SECRET 一行」被當場抓到（見 b7 事件後續對話）。當時未正式寫入此規則檔，是重要警訊被忽略。
2. AI 為了 debug CI 問題，已經在同一輪對話中對 production 檔案做過多次非必要修改（phpunit.xml、tests/bootstrap.php、TestCase.php），累積風險。
3. `config:clear` 被當作「無害的診斷指令」執行，未事先評估對 production 的影響。

### 防再犯規則（⛔ P0 強制）

1. **絕對禁止**在 `/home/admin/backend/` 執行任何會寫入 `bootstrap/cache/` 的 artisan 指令用於「debug 目的」。包括但不限於：
   - `php artisan config:clear`
   - `php artisan config:cache`（除非是部署流程的一部分）
   - `php artisan route:clear`
   - `php artisan route:cache`
   - `php artisan optimize:clear`
   - `php artisan cache:clear`
   
   這些指令只能在兩種情境下執行：
   - **部署流程**：deploy script 明確包含這些步驟作為完整部署序列
   - **事故恢復**：已經發生故障，清快取+重建快取作為恢復手段

2. **AI 要 debug Laravel 測試、config、route 行為時**，必須用獨立 clone：
   ```bash
   git clone /home/admin/backend /tmp/backend-debug
   cd /tmp/backend-debug
   cp /home/admin/backend/.env .env.debug  # 複製後改 DB_DATABASE=AllTrue_test
   # 在此環境測試
   rm -rf /tmp/backend-debug  # 完成後清理
   ```

3. **凡涉及 production config / cache / env 的操作**，AI 必須在執行前：
   - 明確宣告「這是 production 操作」
   - 說明這個操作的恢復方法
   - 詢問使用者是否授權（類似 P0 force-push 規則）

4. **CI debug 一律在 CI 端完成**：GitHub Actions 失敗時，透過修改 `.github/workflows/ci.yml` 和相關測試檔案 → push 觸發新 CI run → 看 CI log。**不在 Pi 上「先本機跑通再 push」**，因為 Pi 的 backend 狀態（config cache、session、env 實際值）與 GitHub Actions Ubuntu runner 根本不同。

### 檢查清單（AI 執行 artisan 指令前必問自己）

- [ ] 這個指令是否會寫入 `bootstrap/cache/`？→ 是 → **停，除非部署或事故恢復**
- [ ] 這個指令是否依賴 `.env` 當前狀態？→ 是 → **停，改用獨立 clone**
- [ ] 這個指令在生產流量中執行是否會造成瞬時不一致？→ 是 → **停，先評估**
- [ ] 使用者是否明確要求「現在就在 production 上跑」？→ 否 → **停**

### 事故代價

- 使用者對 AI 信任受損（當天已發生兩次 P0：.env 誤刪 DEPLOY_SECRET + 本次 config:clear）
- 5 分鐘停機（雖短但發生在晚間使用者工作時段）
- 需花費額外時間做事故恢復與 post-mortem

---

## §2026-04-21 — b3 月結制 inactive 課程的歷史判斷必須與堂數制對稱

### 根本原因

`effectiveClosedReason` 與 `isHistoryCourse` 等前端課程狀態推斷函式，過去只為「堂數制」（`isSessionMode` / `payment_type === 'session'`）添加了 inactive→completed 的 fallback 分支；月結制（`ScheduleMode='date'`）被靜默排除，`effectiveClosedReason` 永遠回傳 `null`，導致 `historyCourses`／`getHistoryStudentCourses` 過濾不到月結停用課。

### 防再犯規則

1. **任何課程狀態推斷函式（`effectiveClosedReason`、`isHistoryCourse` 等）新增或修改判斷分支時，必須同時驗証月結（`!isSessionMode`）與堂數（`isSessionMode`）兩個路徑均有預期回傳值。**
2. **後端停用（`togglePause` / 任何設 `Stop=1` 的路徑）月結課時，必須同步設定 `closed_reason`**（`'completed'` 或明確原因）。不得讓 `closed_reason IS NULL` 與 `Stop=1` 並存於月結課程。
3. **「月結 inactive = 永久結束」語意前提**：目前系統無月結臨時暫停→恢復語意。若未來需要此語意，必須以明確 `closed_reason = 'paused'` 標記，並同步更新前端推斷邏輯（不可繼續用 inactive 無條件 → completed）。

### 授權入口對照（課程歷史判斷）

| 課程模式 | 前端推斷 | DB 狀態 |
|---|---|---|
| 堂數制（`ScheduleMode='count'`） | `isSessionMode=true` + `RemainingSessions ≤ 0` 時 → `'completed'` | `closed_reason` 可為 null，前端 fallback 覆蓋 |
| 月結制（`ScheduleMode='date'`） | `!isSessionMode` + `status='inactive'` 時 → `'completed'` | 後端 `togglePause` 補寫 `'completed'`；歷史髒資料由前端 fallback 覆蓋 |

### 診斷查詢

```sql
-- 月結停用課有無 closed_reason 缺漏（應為 0）
SELECT COUNT(*) FROM StudentClass
WHERE ScheduleMode = 'date' AND Stop = 1 AND closed_reason IS NULL;
```

---

## §2026-04-21 — b4 月結制與堂數制的加購入口必須分流，不得共用 purchaseBatch

### 根本原因

「加購」是**堂數制語意**（新建一筆批次 `StudentClass`），對月結制課程（`ScheduleMode='date'`）呼叫時會產生錯誤的堂數制新課程，破壞計費模式且舊月結課不結案。

### 授權入口對照

| 課程模式 | 前端入口 | 後端路由 | 操作語意 |
|---|---|---|---|
| 堂數制（`ScheduleMode='count'`） | StudentsList「加購」/ CourseManagement「加購堂數」 | `POST /student-classes/{id}/purchase-batch` | 新建批次 `StudentClass`（INSERT） |
| 月結制（`ScheduleMode='date'`） | StudentsList「加購」分流 / CourseManagement「加購堂數」分流 → `RenewMonthlyModal` | `POST /student-classes/{id}/renew-monthly` | 延長原 `EndDate`（UPDATE），不新建記錄 |

### 防再犯規則

1. **新增任何「新建 `StudentClass` 批次」的後端路徑時，必須在方法首行 guard `ScheduleMode !== 'count'` → 422**（`purchaseBatch` 已示範）
2. **前端新增「加購／續約」入口時，必須先分流 `payment_type` / `ScheduleMode`**，不同模式送不同 API，不可共用 payload
3. **`renewMonthly` 不得建立任何 `ClassSession`**：月結排課依 `settlement_day`/`monthly_sessions` 動態生成，續約只更新 `EndDate`

### 診斷查詢

```sql
-- 若 > 0，表示月結課又被誤建為堂數制（同學生同科目同時有 date + count 兩筆 active）
SELECT StudentID, SubjectID, COUNT(*) AS n
FROM StudentClass
WHERE Stop = 0
GROUP BY StudentID, SubjectID
HAVING SUM(ScheduleMode='date') > 0 AND SUM(ScheduleMode='count') > 0;
```

---

## §2026-04-21 — 繳費狀態前端切換必須同步 paid_at：null，後端不得在 recomputeCounters 清零 Paid

### 根本原因

1. **前端切換 unpaid 未清 paid_at**：任何前端元件的「切換繳費狀態→未繳費」邏輯，payload 除了 `payment_status: 'unpaid'` 外，**必須附帶 `paid_at: null`**，否則後端 `mapFrontendPayload` 只設 `Paid=0` 而不清 `PayDate`，形成 `Paid=0 / PayDate IS NOT NULL` 矛盾記錄。

2. **recomputeCounters 不得異動 Paid**：`SessionDeductionService::recomputeCounters`（含 `syncCounters`）的職責僅為重算 `UsedSessions` 和 `RemainingSessions`。任何在此方法中寫入 `Paid` 或 `PayDate` 的邏輯均屬錯誤（歷史曾有 `RemainingSessions <= 2 → Paid=0` 的殘留，已於 2026-04-21 移除）。

### 授權寫入 Paid/PayDate 的唯三路徑

1. `POST /api/v1/class-sessions/batch`（EnrollmentService::store，新建課程時帶 paid_at）
2. `PUT /api/v1/student-classes/:id`（StudentClassController::mapFrontendPayload，編輯或切換）
3. `POST /api/v1/invoices/:id/payments`（Invoice 付款）

**以外任何路徑一律不得寫入 Paid / PayDate。**

### 診斷查詢

```sql
-- 應恆為 0；若 > 0 代表有新的 bug 產生髒資料
SELECT COUNT(*) FROM StudentClass WHERE Paid=0 AND PayDate IS NOT NULL;
```

### 防再犯規則

- 新增任何前端「切換繳費狀態→未繳費」邏輯時，強制 code review 確認 payload 含 `paid_at: null`
- 修改 `SessionDeductionService` 時，先 grep `Paid` 欄位，確認無新增寫入點
- `mapFrontendPayload` 已有後端保底：`payment_status=unpaid` 且未帶 `paid_at` 時強制清 PayDate，但前端仍應主動送 `paid_at: null`（Defense-in-Depth）

---

## §2026-04-21 — C1 schedules.start_time 格式不一致造成 join 失效

### 根本原因

`schedules.start_time` 為 VARCHAR 欄位，全系統**約定**以 HH:MM（len=5）儲存。`ClassSessionController::index` 的 `sub_sched` LEFT JOIN 條件之前寫為 `sub_sched.start_time = SUBSTRING(cs.StartTime, 1, 5)`（只對一側做 SUBSTRING）。若有任何寫入路徑繞過 `normalizeSessionTimeForSchedule()` 把 HH:MM:SS（len=8）寫入，join 會變成 `'18:30:00' = '18:30'` → 失敗 → `sub_sched` NULL → `COALESCE(...teacher_name)` 跌回契約老師，造成課程管理單堂檢視顯示錯師。

### 影響表現

- 課程管理「單堂檢視」Modal 顯示錯師，**但行事曆正確**（這是關鍵診斷訊號——行事曆走 `schedules.teacher_id → teacherNameMap`，不經過 COALESCE）
- 實際代課資料（`schedules.status='scheduled' + original_schedule_id + teacher_id=代課老師`）完全正確
- `class-sessions` API 的 `row.teacher_name` 與 `row.teacher_id` 回傳錯師（COALESCE 的 fallback）

### 防再犯規則

1. **VARCHAR 時間欄位跨表 join 必須兩側都 SUBSTRING(1,5)**  
   - 反例：`a.start_time = SUBSTRING(b.time, 1, 5)` — 單側截斷、格式不一致即失敗  
   - 正例：`SUBSTRING(a.start_time, 1, 5) = SUBSTRING(b.time, 1, 5)` — 永久防禦  
   - 這是「資料修正 + 程式碼防禦」雙保險的業界標準（MySQL docs 2026, StackOverflow time format mismatch）

2. **寫入 `schedules.start_time` / `end_time` 必須用 `normalizeSessionTimeForSchedule()`**  
   - 路徑：`ClassSessionController::normalizeSessionTimeForSchedule()` 會產出 HH:MM  
   - 若新增 `schedules` 寫入點（例如從前端 Supabase 直接 insert），務必先經此函式或等價 regex 轉換

3. **診斷「行事曆對、課管錯」類型的 bug 時，先查兩個資料路徑**  
   - 行事曆：`fetchClassSessions` 或 `schedules + teacherNameMap`  
   - 課管：`GET /api/v1/student-classes`（course-level teacher_name）+ `GET /api/v1/class-sessions`（session-level COALESCE teacher_name）  
   - 若只有某一路徑錯 → 先看該路徑特有的 join / COALESCE / aggregation

4. **搜尋關鍵字**  
   - `SUBSTRING(sub_sched.start_time, 1, 5)`（修正後的 join 條件）  
   - `normalizeSessionTimeForSchedule`（唯一合法的時間正規化函式）  
   - `ClassSessionsSubstituteStartTimeFormatTest`（對應測試檔）  
   - `normalize_schedules_start_time`（修正歷史壞資料 migration）

### 已修 + 測試覆蓋

- Commit： ClassSessionController 第 96 行 join 兩側都 `SUBSTRING(...,1,5)`  
- Migration：`2026_04_21_000002_normalize_schedules_start_time.php`  
- 測試：`ClassSessionsSubstituteStartTimeFormatTest`（3 tests：HH:MM:SS 命中 / HH:MM regression / 無代課不過度排除）

---

## §2026-04-21 — nightly auto-backup 覆蓋已 commit 修正（B1 代課可見性復發 incident）

### 根本原因

`scripts/nightly-backup.sh` 呼叫 `scripts/git-sync.sh` 時，git-sync 執行 `git add -A && git commit`，把當下 **working tree 的完整狀態**整批 commit 進去。若在某次 backup 跑之前，working tree 已被 rollback / 未更新到最新 HEAD（例如 incident recovery 流程把舊版 working tree 複製進來），則 backup commit 等同 revert 掉 HEAD 上最新的修正。

### 觸發情境（本案）

1. `01160fc`（16:57）：A+B 代課可見性修正正確 commit 進 main
2. incident recovery 流程（原因不明）把舊版 working tree 帶入
3. `532872a`（18:41）：nightly auto-backup 把舊版整批 commit，等同 revert controller 修正、刪除測試檔、刪除 migration 檔
4. Bug 重新出現，使用者回報

### 防再犯規則

1. **`scripts/git-sync.sh` 已加入 CODE_REVERT_GUARD**（2026-04-21）  
   - 偵測 `backend/app/Http/Controllers/`、`backend/database/migrations/`、`backend/tests/` 路徑下的**檔案刪除**或**單一檔案淨刪除行數 ≥ 30 行**時，預設 exit 1 拒絕 commit  
   - 繞過方式：`ALLOW_CODE_REVERT=1 ./scripts/git-sync.sh "message"`（需明確 export）  
   - 觸發事件自動寫入 `backups/code-revert-guard.log`

2. **任何 incident recovery 流程都不應直接覆蓋 working tree 再 commit**  
   - 正確做法：先 `git pull --rebase`（或 `git fetch && git reset --hard origin/main`）讓 working tree 對齊最新 HEAD，再處理個別差異  
   - 若 incident 需要真實回退程式碼（例如 hotfix rollback），必須用 `git revert <hash>` 建立明確的 revert commit，並加 `ALLOW_CODE_REVERT=1` 旗標

3. **修改 `ClassSessionController::index` 或 `AttendanceController::endedSessions` 前必讀**  
   - teacher 分支的代課過濾語意：`(sub_sched.teacher_id IS NULL AND sc.TeacherID = ?) OR sub_sched.teacher_id = ?`  
   - endedSessions 的堂次級守衛：`whereExists`（命中代課老師）/ `orWhere + whereNotExists + whereExists`（無代課走契約老師）  
   - 任何簡化（改回 `sc.TeacherID = ? OR sub_sched.teacher_id = ?`）都會觸發已知 bug

4. **搜尋關鍵字**  
   - `CODE_REVERT_GUARD`（git-sync.sh）  
   - `idx_sched_course_date_time_status`（schedules 複合索引）  
   - `ClassSessionsTeacherVisibilityAfterSubstituteTest`、`AttendanceEndedSessionsSubstituteTest`（對應測試檔）

### QA 必跑

- 修改上述任一 controller 後：`./vendor/bin/phpunit tests/Feature/ClassSessionsTeacherVisibilityAfterSubstituteTest.php tests/Feature/AttendanceEndedSessionsSubstituteTest.php` → `OK (8 tests, 25 assertions)`, 0 failures

---

## §2026-04-21 — 備份還原演練（monthly-restore-drill.sh）

### 背景
系統原本只有「備份」沒有「還原驗證」。新增 [`scripts/monthly-restore-drill.sh`](scripts/monthly-restore-drill.sh)，每月 1 日 02:00 跑，還原到 `AllTrue_test` 並發 Telegram。

### 禁止回歸項

1. **還原目標資料庫必須是 `AllTrue_test`，絕不可指向 `AllTrue`**  
   - 腳本中 `TEST_DB="AllTrue_test"` 為常數，DROP / CREATE / 還原皆使用此變數；若有人為了「省資源」改指 `AllTrue` 會直接砍掉生產資料。
   - 修改前請確認：`grep 'DROP DATABASE' scripts/monthly-restore-drill.sh` 只針對 `AllTrue_test`。

2. **row count 差異 ≠ 備份壞掉**  
   - 比對的是 live `AllTrue` vs point-in-time dump，時間差 1 分鐘就可能多 1 列 `ClassSession`；diff 小於 schema row 總數 1% 屬正常漂移。
   - **紅色警訊**：`Student` 或 `Campus` 出現 diff（這兩表變動極少）、或 diff 方向是 `test > prod`（代表生產被誤刪）。

3. **修改備份檔命名規則要同步更新此腳本**  
   - 腳本寫死 `alltrue_monthly_*.sql.gz` 與 `alltrue_nightly_*.sql.gz` 兩個 glob；`nightly-backup.sh` / `sixhour-backup.sh` 改名稱時務必 grep `alltrue_` 確保此處一致。

4. **Telegram 憑證放在 `/home/admin/.env.monitor`，與 `backend/.env` 分離**  
   - 不要把 `TELEGRAM_BOT_TOKEN` 加到 `backend/.env`（會被 Laravel env dump、Sentry breadcrumb 意外外洩）。

### QA 必跑
- 手動執行 `bash /home/admin/scripts/monthly-restore-drill.sh`，確認 Telegram 收到訊息且 `AllTrue_test` row count > 0。
- 驗證 crontab：`crontab -l | grep monthly-restore-drill` 回傳 `0 2 1 * *` 條目。

---

## §2026-04-18 — 老師評量表開啟錯誤：同天同學生多堂課（PRD 3baa154f）

### 根本原因（兩個連環 bug）

1. **`LearningRecordsPage.vue::buildEvents` 的 recordId fallback 錯誤**
   - 三層 fallback：`cs:<id>` → `classId|date|startTime` → `classId|date`。當同天有多堂課（同一 `StudentClass`）時，第三層 `classId|date` key 會把已存在的第五堂 LR 錯配給未填的第六堂。
   - 第六堂 API 回傳 `learning_record_id = null`，但 fallback 仍讓前端以為「有評量」，走入編輯分支打開了第五堂的記錄。

2. **watch 覆蓋 openFromSchedule 設定的 form.StartTime / ClassSessionID**
   - `openFromSchedule` 正確設定第六堂時段後，`watch([form.StudentID, ...])` 觸發 `applyTeacherFormDefaults`，該函式以「同日最早一堂」為預設，覆蓋為 15:00。
   - 第五堂（已有 LR）走 `editRecord` → `isEditing=true`，watch 早 return 所以不受影響；只有「新增」路徑受害。

### 禁止回歸項

1. **`buildEvents` 的 `recordId` 必須嚴格依照 API 回傳 `learning_record_id`**
   - 程式碼位置：[`frontend/src/pages/LearningRecordsPage.vue::buildEvents`](frontend/src/pages/LearningRecordsPage.vue)
   - 標準寫法：`rawSession?.learning_record_id != null ? Number(rawSession.learning_record_id) : null`
   - **禁止**加入 `record?.id` 或 `recordLookup.get('classId|date')` 等 fallback；`record` 變數只能用於 `formStatus` 顯示（讀 `record?.Status`），不得回流到 `recordId`。
   - 理由：後端 `ClassSessionController` 已為每堂 session 精確 JOIN 最新未作廢 LR，前端再做模糊比對只會踩到這個 bug。

2. **老師課表點選開啟評量的 flag guard 必須存在**
   - `_openedFromScheduleSession` ref 必須在 `openFromSchedule` 設為 `ev.classSessionId || -1`。
   - `watch([form.StudentID, form.SessionDate, form.Subject, form.TeacherID])` 的 teacher 分支內必須有：
     ```js
     if (_openedFromScheduleSession.value !== 0) {
       _openedFromScheduleSession.value = 0;
       return;
     }
     ```
   - `closeModal` 必須重置 `_openedFromScheduleSession.value = 0`，防止 modal 被直接關閉而旗標殘留。
   - **禁止**直接改用 `isEditing` 偷懶：新增路徑本來就是 `isEditing = false`，誤把它設成 true 會破壞 modal 標題、save 行為。

3. **修改 `buildEvents` 或 `applyTeacherFormDefaults` 前必跑的 QA 情境**
   - 同學生當天 2+ 堂：每堂 `recordId` 對應正確、時段不被覆蓋
   - 儲存第一堂後不重整、立刻點第二堂，仍正確開啟第二堂空白
   - 代課堂次（`isSubstituted`）：`recordId` 仍應強制 null（`events.push` 處的三元判斷守護）
   - 請假／取消堂次：`recordId` 強制 null，點擊不開 modal（2026-04-17 LEAVE_STATUSES 守護線不退化）

### 反面教材

- 不要以為「API 沒給 recordId，就從 lookup map 拿最近的補上」—— 那只會讓不同堂次互相汙染。
- 不要以為「form 欄位被 watch 覆蓋是設計如此」—— 這個 watch 本意是老師「手動換學生」時自動填入時段，不是「從課表點選」時也強制套用。區分進入路徑是必要的。
- 不要只看 15:00 那堂（有 LR）正常運作就以為都沒事 —— 必須測 17:00 那堂（無 LR）才會觸發兩個 bug。

---

## §2026-04-18 — 兼職薪資 concurrency 偵測必須考量 start time 容忍度（PRD 1b8d93cc）

### 根本原因

`FinanceController::buildConcurrencyBonusMap()` v1.4 以「契約時長區間重疊」判斷同步教學。當同一老師同一天有兩堂 session 開始時間錯開 30~60 分鐘（如 09:30 + 10:00，各 120 min 契約時長），契約區間會重疊 90 分鐘，系統誤把它當成真正的 group class，把 non-primary 的 `base_rate × 重疊時長` 扣掉。興隆主任回報「只有 Ruth 蔣算對」，其實是 Ruth 的 group class 恰好都是**完全相同 start time**，其他老師才會踩到這個 bug。

### 禁止回歸項

1. **`buildConcurrencyBonusMap` 的 concurrent 集合判定必須保留 start time 容忍度檢查**
   - 門檻由 class constant `FinanceController::CONCURRENCY_START_TOLERANCE_MINUTES` 控制（現值 15）。
   - 檢查位置在「收集 $concurrent 陣列」的內迴圈，`abs($other['start'] - $iv['start']) <= 容忍度` 是必要條件；移除這層等同放回 bug。
   - 容忍度**不得**硬編碼分散在多處；只能透過 class constant 調整。

2. **「同 start time 但部分重疊」仍須走 v1.4 tie-break**
   - 例如 10:00 + 10:10（差 10 min 在容忍度內）仍視為同一 group class；`test_staggered_10min_still_concurrent` 是守護線。
   - 例如 10:00 + 10:00（差 0 min）完全重疊仍走 v1.4 tie-break；Ruth 蔣 n=3 / n=2 案例由 `PayrollConcurrencyTest` 8 條原有測試守護。

3. **修改 concurrency 公式前必須執行完整 payroll 守護套件**
   - `./vendor/bin/phpunit --filter='ParttimePayroll|PayrollConcurrency|PayrollRules|PayrollTeacherOverride'`（59 tests / 173 assertions）全綠才能 merge。

### 反面教材

- 不要以為「時間重疊 = 同時段教學」；必須配合「開始時間接近」才成立。
- 不要用「任一 session 端點落在重疊段」作為 concurrent 判斷，那正是 v1.4 的原始 bug。

---

## §2026-04-18 — 課程管理合成 session chip 點擊不得靜默失敗（PRD 1b8d93cc）

### 根本原因

`CourseManagement.vue` 的 session chip 由 `allSessionUnits(course)` 渲染，該函式在 `classSessionsByCourse` 尚未載入完成時會回傳合成物件（`_synthetic: true`，**無 `id`**）。過去所有 chip 都綁定 `@click="openSessionEdit(...)"`，而 `openSessionEdit` 內部 `if (!row) return;` → 點擊合成 chip 完全沒反應。主任回報「調課按鈕不能按」，實際是看似可按但點了靜默結束。

### 禁止回歸項

1. **合成 chip 必須以視覺區分「不可操作」**
   - `CourseManagement.vue` session chip 的 class binding 必須包含 `!u._synthetic && 'date-chip-clickable'` 與 `u._synthetic && 'date-chip-synthetic'`。
   - `.date-chip-synthetic` 樣式必須維持 `opacity: 0.45; cursor: default;` 且 `:hover` 無 transform/box-shadow（避免看起來像可按）。

2. **合成 chip 的 @click 必須在 template 內 short-circuit**
   - 標準寫法：`@click="!u._synthetic && openSessionEdit(...)"`；不可僅依賴 `openSessionEdit` 內部 guard（合成 chip 的 tooltip 也必須指向「重新整理」）。

3. **`openSessionEdit` 找不到 row 時不得 silent return**
   - 必須 alert / toast 明確提示；`useSessionEditFlow.js::openSessionEdit` 內的 `if (!row) { alert(...); return; }` 是守護線，刪掉等於讓其他呼叫路徑（SmartCalendar、action menu、URL deep-link）重新回到 silent failure。

### 反面教材

- 不要讓「資料尚未載入」的 UI 元素看起來和「可操作」完全一樣。
- 不要在「按鈕沒反應」的情境回傳 void 而不給使用者任何回饋；最低限度也要 `alert`。

---

## §2026-04-18 — 合併「代課 + 換時間」Undo 必須同時還原 ClassSession 時間（PRD f0cce4d5）

### 根本原因

PRD f0cce4d5 把「代課」與「換時間」合併到 `POST /api/v1/class-sessions/{id}/substitute` 同一次請求（選填 `new_date` / `new_start_time` / `new_end_time`）。合併路徑在同一 DB transaction 內遷移 `ClassSession.{SessionDate,StartTime,EndTime}`、同步 `LearningRecord` 時間、遷移 `schedules` 列、再套用代課老師。若 Undo 只回復代課老師與 schedules 卻未同時還原 `ClassSession` 與 `LearningRecord` 的時間欄位，課表會停留在**已換的新時段 + 回復的正班老師**的幽靈狀態，造成家長接送錯亂與稽核金流錯位。

### 禁止回歸項

1. **`ClassSessionController::substitute` 的「三欄同填同省」驗證不可放寬**
   - `new_date` / `new_start_time` / `new_end_time` 必須是「三個都填」或「三個都不填」；只填一半回 422。
   - `new_date` 不可為過去（基於 `App\Support\TimeHelper::now()->startOfDay()`）。
   - 只要有填，**跨分校與同分校衝堂檢查必須以新時段為準**；不得使用原時段做檢查後才換時間（否則衝堂盲區會回歸）。

2. **合併路徑必須在同一 DB transaction 內完成全部寫入**
   - 順序：更新 `ClassSession.{SessionDate,StartTime,EndTime}` → 更新 `LearningRecord.{SessionDate,StartTime,EndTime}` → 遷移既有 `schedules`（rescheduled + scheduled 從原日期搬到新日期與新時段）→ 建立/更新代課 schedules → 建立/更新家長通知。
   - 禁止拆為兩次 HTTP 呼叫（舊「先代課、再調課」路徑已含 FR-004 衝堂盲區）。

3. **`SubstituteController::undo` 遇到合併操作必須同時還原時間**
   - 從 `Notification.Payload` 讀 `operation_type`、`original_session_date`、`original_start_time`、`original_end_time`。
   - `operation_type === 'substitute_with_reschedule'` 時，在同一交易還原 `ClassSession` 與 `LearningRecord` 的時間欄位；同時遷移 schedules 回原日期。
   - 純代課的 Undo 路徑（`operation_type === 'substitute'` 或 Payload 無此欄位）**不可受影響**；回歸測試由 `SubstituteUxV2Test` + `SubstituteTeacherTest` 覆蓋。

4. **`SubstituteService::createParentNotification` 的 Payload 必須保留原始時間**
   - `operation_type` / `original_session_date` / `original_start_time` / `original_end_time` 四欄必須寫入 Payload，否則 Undo 無法還原。
   - 合併模式的 Title 為「{學生} {科目} 課程異動通知」、Body 為「原定 {old_date} {old_start}~{old_end} 的課程已調整至 {new_date} {new_start}~{new_end}，由 {new_teacher} 代課。」；純代課 Title/Body 格式**不可改動**（回歸測試固定比對字串）。
   - 冪等策略：既有通知 → 更新 Title/Body/Payload（不再 INSERT）；確保主任先純代課後又補加換時的資訊一致。

5. **`SubstituteController::recent` 必須回傳 `operation_type` 與原時段**
   - 儀表板「含換時」chip 的唯一資料來源；缺欄位會讓 UI 直接回歸為「看不出是合併操作」。

### 覆蓋測試
- `backend/tests/Feature/SubstituteWithRescheduleTest.php` 8 組（合併成功 / 新時段跨分校衝堂 422 / 新時段同分校衝堂 409 / Undo 還原時間 / 純代課回歸 / 半填欄位 422 / 過去日期 422 / recent 含 operation_type）。
- `backend/tests/Feature/SubstituteUxV2Test.php` + `SubstituteTeacherTest.php` 必須同步通過（純代課無 new_date 時的既有行為不變）。

---

## §2026-04-18 — 代課 Undo 必須同時 voided 家長通知，禁止回歸（PRD 9c058f19）

### 根本原因

代課流程 v2（PRD 9c058f19）於 `ClassSessionController::substitute` 的同一交易中建立 `Notifications.Type=substitute` 家長站內通知（FR-010），並於 5 分鐘內允許主任 Undo。若 Undo 只回復 `schedules` + `LearningRecord.TeacherID` 卻未作廢通知，家長 App 會繼續顯示「已取消的代課」→ 造成接送誤解與客訴（FR-011 明列此為 P0）。

### 禁止回歸項

0. **Undo 時間窗必須以 `SystemSetting::get('substitute.undo_window_seconds')` 為單一事實來源**
   - 僅允許 `5 / 10 / 20 / 30` 秒（業界對齊 Gmail Undo Send）；寫入限 `super_admin`。
   - 伺服器實際放行視窗 = UI 秒數 **+ 60 秒 grace**（容忍時鐘/網路），由 `SubstituteController::resolveServerUndoWindow()` 提供。
   - 禁止在 `SubstituteController` 或 `ClassSessionController::substitute` 中重新 hard-code Undo 視窗；所有對外回應的 `undo_window_seconds` 與 `undo_deadline_ms` 必須衍生自該設定。
   - 前端 `ToastWithUndo` 必須從 API 回應 `undo_window_seconds` 驅動倒數；禁止 hard-code `durationMs: 5000`。

1. **`SubstituteController::undo` 交易必須同時執行以下四件事**（缺一即回歸）：
   - 刪除代課 `scheduled` row
   - 刪除 `rescheduled` anchor row
   - 將 `LearningRecord.TeacherID` 回復為正班老師 id（若有 LR）
   - 把最近一筆 `Notifications.Type=substitute AND SourceID=$classSessionId` 的 `ResolvedAt` 設為 `now()`，並於 `Payload.voided=true / voided_by / voided_at_ms`

2. **`SubstituteService::createParentNotification` 必須保持冪等**
   - 以 `SourceKey='substitute:'.$classSessionId` 為冪等鍵；同 session 第二次代課必須回現有紀錄而非 INSERT（`SourceKey` 是 unique index）。
   - 禁止改為 `Notification::create(...)` 無防護呼叫；會讓 idempotency 測試 + 第二次代課立即 500。

3. **跨分校（物理不可分身）衝堂檢查只能攔「其他分校」**
   - `ClassSessionController::substitute` 呼叫 `detectCrossCampusConflict()` 後必須 filter out `campus_id === 當前 session 所屬 campus_id`；同分校衝堂維持由 `ScheduleGuardService` 走 409，否則舊 `SubstituteTeacherTest::test_substitute_future_session_still_blocked_on_capacity_conflict` 會從 409 變 422 失敗。

4. **Availability / Recent API 不得洩漏他校敏感欄位**
   - `GET /api/v1/teachers/{id}/availability` 回傳 `busy_slots[]` **只能**含 `start_time`、`end_time`、`campus_id` 三個鍵；禁止夾帶學生姓名、科目、教室（PRD 第 9 節 Info Disclosure）。
   - `GET /api/v1/substitutes/recent` 必須以 `auth_campus_ids` 過濾 `Notifications.CampusID`；`branch_id` 參數必須再做一次 `in_array($branchId, $managedCampusIds)` 檢查。

5. **批次代課必須整批原子（預設 `atomic=true`）**
   - `TeacherLeaveController::batchSubstitute` 先做全部預檢，任何一堂 `!ok` 且 `atomic=true` → 回 422 不進入交易；若進交易中途失敗則整批 `throw` 回滾。
   - 禁止改為「邊跑邊 commit」；會導致主任看到部分成功部分失敗的不一致狀態，且家長通知無法一起回滾。

### 覆蓋測試
- `backend/tests/Feature/SubstituteUxV2Test.php` 8 組（單堂成功/跨分校衝堂/Undo voided 通知/Preview/Batch 成功/Batch 衝堂回滾/Availability schema/Recent 分校隔離）。
- `backend/tests/Feature/SubstituteTeacherTest.php` 14 組必須持續通過（確保本次變更未破壞舊行為）。

---

## §2026-04-18 — 代課 + 調課組合操作必須雙層防護，嚴禁重複 scheduled 行（禁止回歸項）

### 根本原因

當一堂課在短時間內發生「代課」與「調課」的任意組合（最典型情境：老師請假 → 學生既要換老師也要換時間），`SmartCalendar.submitReschedule` 與 `LearningRecordController::syncSchedulesForRescheduledSession` 之間缺少「同一 anchor 的 scheduled 行至多一筆」約束：

1. **前端雙寫**：`submitReschedule` 偵測到 `alreadyRescheduled = true`（代表 `schedules` 已有 `rescheduled` 錨點行）時仍無條件 `INSERT` 第二筆 `schedules{ status: scheduled, teacher_id: 原任老師, original_schedule_id: anchor }`，與既有代課 scheduled 行產生重複。
2. **後端 sync 僅更新不去重**：`syncSchedulesForRescheduledSession` 把既有代課行的 `schedule_date/start_time/end_time` 改為新值，但從未清除同 `(student_course_id, new_date, original_schedule_id)` 下的其他重複 scheduled 行。
3. **`ClassSessionController::index` 子查詢以 `MAX(id)` 挑代課老師**：前端後寫入的行 id 較大 → 被子查詢選中 → `teacher_name` 解析為原任老師，課程管理 tooltip 授課老師欄顯示錯誤。

### 禁止回歸項

1. **`SmartCalendar.submitReschedule` 必須偵測既有代課 scheduled 行，符合則跳過 payload2 的 Supabase insert**
   - 判斷條件固定為：`originalId !== null && exceptions.value.some(ex => ex.status === 'scheduled' && ex.original_schedule_id != null && String(ex.original_schedule_id) === String(originalId) && String(ex.student_course_id) === String(rescheduleForm.value.course_id))`
   - 禁止回退為「無條件插入 payload2」以簡化邏輯；任何回退都會立刻重現此 bug
   - Fallback 行為：`exceptions` 未載入或缺 `original_schedule_id` 欄位時，允許插入；靠後端 sync 去重收尾

2. **`syncSchedulesForRescheduledSession` 去重刪除 scope 必須維持五重條件**
   - （1）`student_course_id = $classId`（2）`schedule_date = $newDateOnly`（3）`status = 'scheduled'`（4）`original_schedule_id IN $anchorIds`（5）`id NOT IN $substituteIds`
   - **任一項缺失**都可能誤刪他課、他 anchor、正在更新的目標行；尤其第 5 項（`whereNotIn`）是最容易被 refactor 掉的保險栓
   - 禁止以「先刪光再重建」的模式實作；必須先 update 再 pluck deleted_ids 記錄後刪除

3. **`$anchorIds` 只能來自 `Schedule::whereIn('id', $substituteIds)->pluck('original_schedule_id')`**
   - 禁止改用 `request('anchor_id')` 或其他外部輸入——會成為 IDOR
   - 禁止直接以 `original_schedule_id` 做 where 條件而不經 $substituteIds 回推

4. **刪除操作必須寫 audit log**
   - 格式：`Log::info('reschedule_session.duplicate_scheduled_rows_deleted', ['student_class_id', 'new_date', 'anchor_ids', 'deleted_ids'])`
   - 事後稽核用；排除「有刪但看不出來」的黑箱狀態

5. **`ClassSessionController::index` 的 MAX(id) 子查詢本次刻意不動**
   - 治本策略是「防止重複行產生」而非「改查詢適應重複行」
   - 若未來為其他理由需改此查詢（例如改為 `ORDER BY updated_at DESC LIMIT 1`），必須先重讀本節並確認雙層防護仍有效

### 關聯檔案

- `backend/app/Http/Controllers/LearningRecordController.php`（`syncSchedulesForRescheduledSession` lines 1207–1310）
- `frontend/src/pages/SmartCalendar.vue`（`submitReschedule` lines 3027–3150）
- `backend/app/Http/Controllers/ClassSessionController.php`（`index` 的 `sub_sched` 子查詢 lines 87–97，本次不動）
- `backend/tests/Feature/SubstituteReschedulesCombinationTest.php`（4 個 case 覆蓋所有組合）

### 資料熱修正 SOP（若後續又發現類似殘留）

```sql
-- 1) 查詢同 anchor 重複的 scheduled 行
SELECT id, teacher_id, status, schedule_date, start_time, original_schedule_id
FROM schedules
WHERE student_course_id = :course_id
  AND DATE(schedule_date) = :new_date
  AND status = 'scheduled'
  AND original_schedule_id IS NOT NULL
ORDER BY original_schedule_id, id;

-- 2) 確認 ClassSessionController::index 子查詢挑出的 row
SELECT sub_inner.*
FROM schedules sub_inner
INNER JOIN (
  SELECT student_course_id, DATE(schedule_date) sd, start_time, MAX(id) mid
  FROM schedules
  WHERE status = 'scheduled' AND original_schedule_id IS NOT NULL
  GROUP BY student_course_id, sd, start_time
) x ON sub_inner.id = x.mid
WHERE sub_inner.student_course_id = :course_id
  AND DATE(sub_inner.schedule_date) = :new_date;

-- 3) 若 MAX(id) 選到原任老師 → 刪除該行（保留代課老師行）
DELETE FROM schedules WHERE id = :stale_id;
```

---

## §2026-04-18 — schedule-discrepancies 分校隔離必須在後端強制（禁止回歸項）

### 根本原因

`ScheduleDiscrepancyController::resolveCampusScope` 原本對 director 角色在未帶 `branch_id` 時 `return $campusIds`（聚合該 director 所有指派分校），依賴前端每次記得帶 `branch_id` 才能限制視野。兩個缺口：

1. **跨校資料曝光**：A/B 兩校都掛名的主任，在 A 校主操作時若前端漏掉 `branch_id` query，系統直接回傳兩校聚合列表——包含 B 校老師私下回報的學生姓名、時段爭議。此為非必要跨校曝光，違反 PDPA 最小必要原則。
2. **後端非強制**：DevTools 直接呼叫 `GET /api/v1/schedule-discrepancies` 即可繞過前端分校選擇，輕易取得所有指派分校資料。

### 禁止回歸項

1. **director 無 `branch_id` 且多校時必須 422**
   - `resolveCampusScope($request, requireBranch: true)` 是 `index()` 的唯一合法呼叫路徑
   - 禁止再回歸為「聚合 auth_campus_ids」或「fallback 到 auth_campus_ids[0]」的 silent widen
   - 單校 director（`count(auth_campus_ids) === 1`）可 fallback 為單校以保向下相容
   - super_admin 保留無 `branch_id` 即跨校視野，但**非 super_admin** 一律強制

2. **跨校 `branch_id` 必須 403**
   - director 指定不屬於自己 `auth_campus_ids` 的 branch_id → `abort(403)`
   - 禁止「靜默過濾為空列表」——必須明確拒絕，讓攻擊行為留下 audit 痕跡

3. **分校隔離只能在後端**
   - `ScheduleDiscrepancyPage.vue` 的 `hasBranch` 前端 guard 僅為 UX（避免打空 API）；即便前端被繞過，後端 422/403 仍必須生效
   - 禁止把分校過濾邏輯搬到前端 `filter()`

4. **`corrected_time_range` 屬使用者輸入，儲存前必須 normalize**
   - 使用 `ScheduleDiscrepancyController::normalizeCorrectedTimeRange` 或同等嚴格驗證：regex `/^\d{1,2}:\d{2}-\d{1,2}:\d{2}$/`、trim、whitespace strip、start < end、長度 ≤ 32
   - 禁止直接 `$request->input('corrected_time_range')` 就寫 DB
   - 顯示端 Vue 預設 escape，但絕不可加任何 `v-html` 渲染此欄位

5. **migration 必須含 `Schema::hasColumn` 冪等 guard**
   - 同模組先前已有「schedule-discrepancies 路由靜默回退」事件；本檔的 `2026_04_19_100000_add_corrected_time_range_*` migration 必須具備 `Schema::hasTable` + `Schema::hasColumn` 雙守門才 add column / drop column

### 對應回歸測試

- `tests/Feature/ScheduleDiscrepancyBranchIsolationTest.php`（6 cases，分校隔離完整覆蓋）
- `tests/Feature/ScheduleDiscrepancyCorrectedTimeTest.php`（9 cases，正確時間驗證 + Notifier 推播）
- `tests/Feature/ScheduleDiscrepancyApiTest::test_cross_campus_director_cannot_view_other_campus_reports`

---

## §2026-04-18 — 調課＋代課需 atomic transaction 同步 schedules start_time（禁止回歸項）

### 根本原因

`LearningRecordController::rescheduleSession` 原本存在兩個嚴重缺口：

1. **session 定位不精確**：以 `whereDate('SessionDate', $old_date)->first()` 單鍵定位 ClassSession，同一天若有多個時段（例：早 10:00–12:00 與晚 15:00–17:00）會誤選第一個 row，導致使用者看見「調課成功」但實際上另一個時段被改動或原時段完全沒變。
2. **schedules 代課行未同步**：ClassSession 時間更新後，`schedules` 表內的代課 row（`status='scheduled'` + `original_schedule_id IS NOT NULL`）`start_time/end_time/schedule_date` 停留在原時段。`ClassSessionController::index` JOIN schedules 時，代課老師週課表讀到的格子仍在舊位置，SmartCalendar 出現「舊格有卡片但無點名 badge、新格完全空白」的鬼影格。

前端三處（`useSessionEditFlow.js`、`useRescheduleAndMakeup.js`、`SmartCalendar.vue`）又以 `.catch(() => {})` 靜默吞掉 API 失敗，使用者端僅看到綠色「調課成功」toast，完全無從察覺 422/500。

### 禁止回歸項

1. **`rescheduleSession` 必須以 `(student_class_id, old_date, old_start_time)` 三鍵定位**
   - 提供 `old_start_time` 時若找不到完全匹配 ClassSession 必須回 422；禁止 silent fallback 到 `first()`
   - 禁止任何「猜測哪個 session 是對的」邏輯（`orderBy()->first()` 僅允許在 `old_start_time` 未傳時作向下相容 fallback）

2. **ClassSession 更新與 schedules 代課行同步必須在同一 `DB::transaction` 內**
   - `LearningRecordController::syncSchedulesForRescheduledSession` 為唯一合法路徑
   - Scope 嚴格限於 `status='scheduled'` AND `original_schedule_id IS NOT NULL`（代課行）；**禁止**觸動 `rescheduled` 錨點行（代表原時段歷史紀錄，downstream 報表依賴其位置）
   - 失敗必須整批 rollback；禁止「先更新 ClassSession 成功、schedules 同步失敗就靜默 log」的部分成功模式

3. **前端 reschedule 呼叫禁止使用 `.catch(() => {})`**
   - `useSessionEditFlow.js`、`useRescheduleAndMakeup.js`、`SmartCalendar.vue` 三處 reschedule-session fetch 必須顯式處理失敗（toast/alert 顯示後端 `message`）
   - 請求 payload 必須包含 `old_start_time`（form 既有 `form.start_time` / `form.original_start`）

4. **`SmartCalendar::findSessionRowForCell` 修改時禁止打破非例外匹配**
   - `is_exception` fallback 僅在 exception 路徑生效；非例外 cell 的精準 date + start_time 匹配行為必須完全保留

5. **回歸守護測試固定位置**
   - `backend/tests/Feature/RescheduleSessionPrecisionTest.php`（5 tests）：
     - `test_same_day_two_slots_precise_match_moves_only_the_targeted_session`
     - `test_old_start_time_mismatch_returns_422`
     - `test_omitting_old_start_time_falls_back_to_first_by_date`
     - `test_reschedule_syncs_substitute_schedules_row_in_same_transaction`
     - `test_reschedule_without_substitute_is_unaffected`
   - `backend/tests/Feature/SubstituteRescheduleRegressionTest.php`（6 tests）：特別確認 `rescheduled` 錨點行不被 sync helper 位移
   - 禁止刪除或弱化上述任一測試；新增代課流程前必須先通過

### 關聯檔案

- `backend/app/Http/Controllers/LearningRecordController.php::rescheduleSession` / `syncSchedulesForRescheduledSession`
- `frontend/src/composables/course-management/useSessionEditFlow.js`
- `frontend/src/composables/course-management/useRescheduleAndMakeup.js`
- `frontend/src/pages/SmartCalendar.vue::findSessionRowForCell`

---

## §2026-04-18 — 老師端科目數敏感資訊遮蔽（禁止回歸項）

### 根本原因

`FinanceController::subjectUnits` 對所有角色回傳完整 `teachers[].{one_on_one_hours,one_on_two_hours,share_pct,subject_count_*,level_breakdown}` 與分校 `totals` / `level_breakdown_totals`。老師登入後在「科目數統計」頁面看得到同事的絕對數字與分校彙總，屬公司內部財務敏感資訊。前端若只靠 `v-if` 遮蔽是可以透過 DevTools / 直接打 API 繞過的。

### 禁止回歸項

1. **敏感資訊遮蔽必須在後端執行**
   - `FinanceController::subjectUnits` 尾端對 `auth_role='teacher'` 執行 redaction：他人 row 的 `one_on_one_hours/one_on_two_hours/one_on_three_hours/tutoring_hours/total_hours/subject_count_with/subject_count_without/share_pct` 設 `null`；`level_breakdown` 清空為 `[]`
   - `totals` / `level_breakdown_totals` 從 response 刪除（`unset`），而非僅前端隱藏
   - 禁止僅以前端 `v-if` 或 `computed` 代替後端 redaction

2. **`is_self` / `rank` / `total_teachers` 三個欄位必須存在**
   - 每筆 teacher row 需有 `is_self: bool`、`rank: int（1-based）`
   - Response 頂層需有 `total_teachers: int`
   - 前端依 `is_self` 決定高亮與「(你)」tag；依 `rank` 顯示排名序號；依 `total_teachers` 顯示「共 Y 名」

3. **主任 / super admin 視野不受影響**
   - `auth_role` 為 `director` / `super_admin` 時 response 保持完整 totals 與所有數字
   - 禁止以「統一簡化」為由把 director 視野也一併遮蔽

4. **回歸守護測試固定位置**
   - `backend/tests/Feature/FinanceSubjectUnitsTest.php::test_teacher_sees_branch_wide_subject_units`：驗證 teacher 視角他人數字為 null、無 totals、rank 正確
   - `FinanceSubjectUnitsTest::test_director_view_is_unaffected_by_teacher_redaction`：驗證主任視角 totals + 所有數字完整
   - 禁止刪除或合併上述兩測試

### 關聯檔案

- `backend/app/Http/Controllers/FinanceController.php::subjectUnits`（redaction 區塊）
- `backend/app/Http/Middleware/AttachAuthUser.php`（`auth_role` / `auth_teacher_id` 來源）
- `frontend/src/pages/SubjectUnitsPage.vue`（前端僅做呈現，不做權限決策）

---

## §2026-04-17 — 建立課程繳費日期失效（禁止回歸項）

### 根本原因

`SessionDeductionService::recomputeCounters` 原本在 `RemainingSessions <= 2` 時把 `StudentClass.Paid` 強制設為 0：

```php
if ($sc->RemainingSessions <= 2) {
    $sc->Paid = 0;
}
```

此服務每次課程建立、簽到、請假、LR 核准、補登後都會被呼叫。主任在 `UniversalClassScheduler` 填入「繳費日期」（`paid_at`）建立課程後，`EnrollmentService::store` 正確寫入 `Paid=1` / `PayDate`，但 `syncCounters` 立刻把 `Paid` 覆蓋回 0，前端 `payment_status` 顯示為「未繳費」。

對短期課程（1–2 堂）或任何讓剩餘堂數降至 ≤2 的扣點路徑，這個 bug 每次都會重現。

### 禁止回歸項

1. **`SessionDeductionService::recomputeCounters` 僅能異動 `UsedSessions` / `RemainingSessions`**
   - 禁止讀寫 `Paid` / `PayDate`；任何 `$sc->Paid = ...` 或 `$sc->PayDate = ...` 出現在此方法，一律視為回歸
   - 禁止以「剩餘堂數」衍生任何財務決策（「快用完自動改未繳費」是錯誤的心智模型）

2. **`Paid` / `PayDate` 只能由以下三條路徑寫入**
   - `POST /api/v1/class-sessions/batch`：`EnrollmentService::store` 依 `paid_at` 決定 `Paid` 與 `PayDate`
   - `PUT /api/v1/student-classes/{id}`：`StudentClassController::mapFrontendPayload` 處理 `paid_at` / `payment_status`
   - Invoice 付款：`POST /api/v1/invoices/{id}/payments` 同步 `StudentClass.Paid`
   - 其他位置（service、Job、command）新增 `Paid` 寫入前必須 PR review 並更新本條

3. **回歸守護測試固定位置**
   - `backend/tests/Feature/StudentClassPaidStatusTest.php::test_sync_counters_does_not_clear_paid_when_remaining_lte_2`
   - `StudentClassPaidStatusTest::test_sync_counters_does_not_mutate_paid_for_unpaid_course`
   - `backend/tests/Feature/EnrollmentApiTest.php::test_create_course_with_paid_at_shows_paid_status`
   - `EnrollmentApiTest::test_create_short_course_with_paid_at_shows_paid`（直接覆蓋 `RemainingSessions=1` 觸發路徑）
   - `EnrollmentApiTest::test_create_course_without_paid_at_stays_unpaid`（確保沒有相反副作用）

4. **業務原則**
   - 「剩餘堂數」屬教務統計，「繳費狀態」屬財務事實；兩者不得互相推導
   - 「Paid 自動清零 / 自動抬升」的行為只存在於 Invoice 付款同步路徑
   - 若未來業務要求「堂數用完自動提醒續費」，那是催繳提醒（`AlertController::tuition`）功能，不是改 Paid 欄位

---

## §2026-04-17 — 兼職老師薪資以「契約排定時長」為準（禁止回歸項）

### 根本原因
`FinanceController::calcHours` 原本優先讀取 `LearningRecord.StartTime`/`EndTime`，只在兩者皆缺才 fallback 到 `StudentClass.SessionDuration`。同時 `buildConcurrencyBonusMap` 用實際 `StartTime`/`EndTime` 作為重疊 interval 的端點。當主任在「備註 / 調整時段」把 20:00 改成 19:30，老師本月薪資就被縮短（`350 × 1.5 = 525`，而非 `350 × 2 = 700`），與「時段微調不影響薪酬」的業務共識相悖。另一方向的問題（例如實際 17:00-20:00 但契約僅 2h）則會讓老師被多付 1 小時。

### 禁止回歸項

1. **`calcHours` 的優先順序不得翻回「actual time first」**
   - 新順序：`resolveSessionDurationForWeekday($sessionDate->isoWeekday())` → `StudentClass.SessionDuration` → 實際 `StartTime`/`EndTime`（fallback） → `2.0h` default
   - 簽名必須保留 `$sessionDate` 參數，讓 per-weekday duration 正確解析
   - **禁止**再用 `actual_minutes / SessionDuration` 類的比例縮放邏輯，這會讓「備註調整」被誤解為薪資輸入
   - 這與學收端「單堂費用固定不隨時長縮放」為同一根原則的薪資側（見前一條 §）

2. **`buildConcurrencyBonusMap` 的 interval end 必須用契約時長計算**
   - `effectiveEnd = startMin + contractedDurationMinutes($lr)`；僅在契約時長 < 30min 才 fallback 到 actual `$endMin`
   - 禁止以 `StartTime`/`EndTime` 建構 interval，否則「18:00-19:00（實際 1h，契約 2h）」的併堂加給會被錯算（實際上應對齊整個契約時段）

3. **`contractedDurationMinutes` helper 不得省略 studentClass 檢查**
   - 必須 graceful handle：缺 `studentClass` 關聯、缺 `SessionDuration`、或 `<30min` 垃圾值都要回 0，由呼叫端 fallback
   - 回值 < 30 視為「契約不可用」；避免把錯誤資料放大進重疊計算

4. **三個 `calcHours` 呼叫端必須同步傳 `$sessionDate`**
   - `FinanceController.php` 中三處：主薪資彙總（`parttimePayroll`）、按老師 session（`parttimePayrollSessions`）、sessionRow builder。漏傳會導致 per-weekday duration 無法解析，只能 fallback 到 `SessionDuration`

5. **回歸守護測試固定位置**
   - `backend/tests/Feature/ParttimePayrollTest.php::test_contracted_duration_used_when_actual_shorter`（契約 2h / 實際 1.5h → 800）
   - `ParttimePayrollTest::test_concurrency_bonus_two_hour_overlap`（A 實際 3h / 契約 2h → 1250，不是 1300）
   - `ParttimePayrollTest::test_concurrency_bonus_three_sessions_overlap`（3 × 實際 1h / 契約 2h → 1000，不是 500）
   - 這三個數字如果又回到「實際時長為準」的舊值，代表改回歸。必須先跑 `phpunit --filter 'ParttimePayrollTest|PayrollConcurrencyTest'`（34 tests 全綠）

6. **業務原則（寫進 PRD 與 CHANGELOG）**
   - 薪資 = **契約排定時長** × 時薪。「備註 / 調整時段」是記錄事實、讓主任追蹤實際到離時間，不是薪資輸入
   - 與學收端「session 費用固定不隨時長」完全對稱：兩側都遵守「契約為準，實際為記錄」
   - 若未來引入「超時加給」或「提早下課扣錢」，必須做成獨立規則而不是修改 `calcHours` 的 fallback 順序

---

## §2026-04-17 — 單堂費用固定不隨時長縮放（禁止回歸項）

### 根本原因
`ClassSessionController::syncSessionChargeForTimeChange` 對 `rate_unit='session'`（按堂計費）與 `rate_unit='hour'`（按時計費）一律套用比例縮放公式 `Rate × actual_minutes / SessionDuration`，違反「按堂計費＝固定堂費」的業界慣例與本系統產品定義。前端 `SessionEditModal.chargePreview` 與 `SmartCalendar.currentSessionChargeDisplay` 也都跟著時間比例邏輯，連帶把錯誤金額寫入 `ClassSession.session_charge` 與擾動 `StudentClass.Charge`。

### 禁止回歸項

1. **`syncSessionChargeForTimeChange` 的 session / hour 分支不得合併或互換公式**
   - session mode：`session_charge = round(Rate)`（固定），`Charge` delta 依 baseline 只修正舊值偏差，不因時段調整產生新 delta
   - hour mode：`session_charge = round(Rate × actual_minutes / 60)`（按時比例）
   - 禁止再出現 `Rate × actual_minutes / SessionDuration` 這種「把按堂也縮放」的公式

2. **`SessionEditModal.chargePreview` 必須依 `contract_rate_unit` 分支**
   - session mode 強制 `value = rate`，且同時關閉 delta chip 與 `onSaveClick` 偏離警告
   - 任何新的計費預覽分支必須同時更新前後端；單邊動會引起顯示與寫入不一致

3. **`SmartCalendar.currentSessionChargeDisplay` 必須走 Single Source of Truth**
   - session mode：直接取 `rate_per_30min`（= DB `Rate`），**不得**讀 `session_charge`
   - hour mode：`session_charge` 優先；fallback 用 `rate × duration_hours`，禁止回退成舊的 `rate30 * (durationHours * 60 / 30)` 雙倍公式
   - `mapCourse` 必須保留 `rate_unit` 欄位，否則分支判斷會退化

4. **Backfill Migration 必須在應用程式碼之前執行**
   - 部署順序：`php artisan migrate` → 後端 OPcache 清除 → `npm run deploy`
   - 若先上線程式再跑 backfill，SmartCalendar（hour mode）會顯示還沒修正的 `session_charge` 舊值
   - `session_charge_backfill_log` 為稽核與回滾的基礎，禁止在部署期間 drop 或 truncate

5. **回歸守護測試固定位置**
   - `backend/tests/Feature/ClassSessionChargeTest.php`（10 個：session 固定費、hour 比例計費、Charge delta 行為）
   - `backend/tests/Feature/SessionChargeBackfillTest.php`（2 個：backfill 正確性、冪等性）
   - 任何計費邏輯變更必須先更新這兩個檔案

6. **不得假設「只要有 session_charge 就是權威值」**
   - session mode 的 `session_charge` 是衍生值（derived），展示層應以契約 `Rate` 為準
   - 任何新增的費用顯示位置（帳單、薪資、報表）在 session mode 下必須走 `getPerSessionFee(course)` 或等效公式，不可盲從 `session_charge`

---

## §2026-04-17 — 歷史課程漏算當月學收（禁止回歸項）

### 根本原因
`FinanceController::branchMonthlyTuition` 與 `::branchMonthlyTuitionExport` 查詢 `StudentClass` 時以 `->where('Stop', 0)` 過濾，導致已結案課程（Stop=1）即使當月有 attended 堂次也完全被排除。這屬於「**以當前狀態快照過濾歷史月份的事實**」典型 Bug 模式：報表查的是歷史月份，但判斷條件用的是當前時刻的旗標。

### 禁止回歸項

1. **`FinanceController::branchMonthlyTuition` 不得恢復 `->where('Stop', 0)` 過濾**
   - 學收口徑以「當月實際出席堂次」為事實依據；`Stop = 1` 僅代表課程當前已結案，不代表當月沒有收入
   - `INNER JOIN sc_counts` 已保證「只返回當月有堂次的課程」，零堂次的歷史課程自動排除
   - `branchMonthlyTuitionExport`（CSV 匯出）必須與 `branchMonthlyTuition` 同步，二者查詢條件不得分歧

2. **API response 必須保留 `is_stopped: bool` 欄位**
   - 前端 `TuitionReportPage.vue` 依此欄位顯示灰色「已結課」badge 與降低 opacity
   - 移除此欄位會讓主任無法識別報表中的歷史課程行，引發對帳疑問

3. **CSV 匯出最右側「課程狀態」欄位不得移除**
   - 值為「進行中／已結課／月結方案」三選一
   - 主任可能以 CSV 對帳，沒有此欄位時無法辨識行意義

4. **前端 `TuitionReportPage.vue` 的視覺區分必須保留**
   - `is_stopped=true` → `.tr-stopped-row` class + `stopped-badge` span
   - CSS：`opacity: 0.65`（hover 提升至 0.85）+ 灰色背景 `#6B7280`
   - 不得改為只顯示 badge 而不降低 opacity，或反之（兩者合併才構成足夠識別度）

5. **`alerts/tuition` 的 `Stop = 0` 條件不可比照移除**
   - 催繳語意為「進行中課程才需要被提醒繳費」，與學收試算口徑不同
   - 已結案的課程不應再出現在催繳清單
   - 若誤把兩處當作同一 Bug 一併改動，會讓已結案課程出現在催繳名單造成騷擾

6. **回歸守護測試固定位置**
   - `backend/tests/Feature/BranchMonthlyTuitionHistoricalTest.php`
   - 7 個案例：Stop=1 有堂次應出現、Stop=1 無堂次不應出現、Stop=1 堂次跨月不應出現、Stop=0 `is_stopped=false`、summary 正確加總、CSV 匯出、分校隔離
   - 修改 `FinanceController::branchMonthlyTuition*` 前務必先跑此測試

### 一般化 Bug 模式（值得推廣到其他報表）

> **「快照過濾歷史事實」反模式**：任何以「當月／歷史期間」為維度的統計報表，禁止用「當前時刻的旗標（Stop / enabled / deleted_at IS NULL 等）」作為主要過濾條件。正確做法是以「事實來源（ClassSession / Invoice / Payment 等有時間戳的表）在期間內存在」為主條件，當前狀態僅作為**輔助標示**（如 badge），而非過濾。

若未來設計新的 period-based 報表，審查時必檢查：
- 過濾條件是否以「期間內事實」為主？
- 「當前旗標」是否只用來標示（不過濾）？
- 測試是否有「旗標=1 但期間內有事實」的案例覆蓋？

---

## §2026-04-17 — 請假後不再要求填評量（禁止回歸項）

### 根本原因
老師點名請假後，`CourseLeaveCascadeService` 作廢 LR（`VoidedAt`）並將 `ClassSession.Status` 設為 `leave`。`GET /api/v1/class-sessions` LEFT JOIN 因 `VoidedAt IS NULL` 帶不回 LR → `learning_record_status` 預設為 `'missing'`。`LearningRecordsPage::buildEvents` 未像 `SmartCalendar::evalBadge` 用 `LEAVE_STATUSES` 過濾堂次狀態，導致請假堂次被誤顯示為「未填評量」。

### 禁止回歸項

1. **前端 `LearningRecordsPage.vue::buildEvents` 必須依 `rawSession.status` 判斷請假類狀態**
   - `LEAVE_STATUSES = new Set(['leave', 'leave_adjusted', 'excused'])` 命中時：`formStatus = 'leave'`、`fillLocked = true`、`recordId = null`（防止進入 `canEdit` 分支）
   - `cancelled`：`formStatus = 'cancelled'`、`fillLocked = true`
   - **嚴禁**回到「只看 `rowStatus || record?.Status || 'missing'`」的舊邏輯——會讓請假堂次再度出現「未填」提示與可填評量按鈕
   - 保留 `event.isLeave` / `event.isCancelled` flag 供模板樣式使用；`openFromScheduleMaybe` 必須對兩個 flag 提前 `return`，不可打開 modal

2. **前端 `scheduleStatusLabel` 必須涵蓋請假／取消分支**
   - `leave` / `leave_adjusted` / `excused` → `請假`
   - `cancelled` → `取消`
   - 移除分支會使狀態 chip 顯示為「未填」，UX 誤導同第 1 項

3. **後端 `LearningRecord::scopeExcludeLeaveSessionPendingReview` 必須涵蓋 `leave` + `leave_adjusted` + `excused`**
   - 2026-04-17 補上 `excused`，與前端 `LEAVE_STATUSES` 對齊
   - **嚴禁**移除 `excused` 或新增請假類 status 時未同步更新此 scope
   - 影響：`LearningRecordController::index`、`NotificationSyncService::buildLearningNotifications`、`tuition*` 待審／通知相關列表

4. **前後端 `LEAVE_STATUSES` 必須同步**
   - 出現在：`frontend/src/pages/SmartCalendar.vue`（`LEAVE_STATUSES` + `SESSION_STATUS_PRIORITY`）、`frontend/src/pages/LearningRecordsPage.vue`（`LEAVE_STATUSES`）、`backend/app/Models/LearningRecord.php`（`scopeExcludeLeaveSessionPendingReview`）
   - 新增請假類 `ClassSession.Status` 時**四個位置都必須同步更新**；否則會出現「主任列表不顯示但老師評量頁仍要填」之類的語意漂移

5. **回歸守護測試固定位置**
   - `backend/tests/Feature/LearningRecordLeaveExclusionTest.php`
   - 5 個案例：`leave` / `leave_adjusted` / `excused` pending 皆不出現 + `leave+approved` 仍可查 + `attended` pending 正常顯示
   - 修改 `scopeExcludeLeaveSessionPendingReview` 或 `buildEvents` 前務必先跑此測試

6. **`leave → attended` 恢復路徑不得被本次修改誤攔截**
   - `rawSession.status = 'attended'` 時 `LEAVE_STATUSES.has()` 回 false，不進入本次攔截 → 不受影響
   - 若未來擴增 `LEAVE_STATUSES`，必須驗證此恢復路徑仍可顯示評量（回歸守護：`LearningRecordLeaveExclusionTest::test_pending_lr_on_attended_session_remains_visible_regression`）

7. **`deduplicateSessionsBySlot` 不得因本次邏輯而被移除或改順序**
   - 本次僅在其輸出後附加狀態判斷；順序改動會連動 AI_REGRESSION_LESSONS §2026-04-15（同格重複卡片）回歸

---

## §2026-04-17 — 兼職老師薪資同層級併堂重複計算（PRD §4.3 v1.4，禁止回歸項）

### 根本原因
`FinanceController::buildConcurrencyBonusMap` 在 v1.3 對「同層級時間重疊」的 LR 採「每筆各得全額基礎薪 + 各得 `(n-1)*50*Δt` 加給」，缺少不同層級已有的「重疊段扣基本費」保護。結果當一位老師同時段教 3 名學生（同層級），基礎薪被計 3 次（Ruth蔣 04-03 實際應 ≈900 卻算成 2,400）。

### 禁止回歸項

1. **`buildConcurrencyBonusMap` 必須採「層級優先 + lr_id tie-break」統一規則**
   - 取每個重疊段的 `concurrent` 集合中 `level_weight` 最大者的 `lr_id` 最小者為 `$primaryLrId`
   - **主導者（高層且 lr_id 最小）**：`lrNet += (n-1) * bonus * dt`
   - **非主導者（同層非 primary 或低層 dominated）**：`lrNet -= base_rate * dt`
   - **嚴禁**回到「同層級每筆 LR 各保留基礎薪 + 各得加給」的 v1.3 行為——這會讓一位老師的同一個物理授課時段被計 N 份基礎薪。

2. **tie-break 必須以 `lr_id` 決定，不得改用 `created_at` 或業務欄位**
   - `lr_id` 是 DB 自增 PK，前端無法操控、無時區問題
   - 業務上等值（同層級 = 相同時薪），tie-break 取法不影響老師總收入（`test_tie_break_swap_preserves_total_salary` 守護）
   - 若未來需加入 tier-2 tie-break（例如「一對三優先」），可以 `class_type` 權重作為次序，但 tier-1 必須保留 `lr_id`

3. **`buildSessionRow` 的 `max(0, $baseSalary + $concurrencyBonus)` 保底不得移除**
   - 非主導 LR 的 `lrNet` 可為負值（例如 `-base_rate × dt`），靠 `max(0, ...)` 保證單堂薪資最低為 0
   - 移除保底會出現負薪資、違反財務直覺

4. **不同層級行為必須與 v1.3 完全一致（回歸守護）**
   - 測試：`PayrollConcurrencyTest::test_different_level_regression_unchanged`（高中+國中 2h 完全重疊 → 900）
   - 測試：`ParttimePayrollTest::test_concurrency_bonus_tutoring_plus_regular`（輔導被國小 dominated → 850）
   - 測試：`ParttimePayrollTest::test_level_dominance_junior_over_tutoring`（國中+輔導 → 800）
   - 任何改動若導致這三個測試的金額變動，代表誤改了回歸範圍。

5. **回歸守護測試位置固定**
   - 新功能規則：`backend/tests/Feature/PayrollConcurrencyTest.php`（8 tests）
   - 舊測試更新：`backend/tests/Feature/ParttimePayrollTest.php` 中 `test_concurrency_bonus_*` 與 `test_same_level_tie_break_by_lr_id`、`test_one_on_two_both_students_present_concurrency`
   - 修改 `buildConcurrencyBonusMap` 前務必先跑 `phpunit --testsuite Feature --filter 'Payroll|Parttime'`，必須 57 tests 全綠。

6. **規格文件的黃金路徑是 `docs/PRD_PARTTIME_TEACHER_PAYROLL.md §4.3`**
   - 任何計算邏輯異動必須同步 bump PRD 版本（v1.3 → v1.4 → ...）並在 CHANGELOG 留條目
   - 參考業界最佳實踐（Gusto / Stripe）：薪資規則靜默改動是糾紛主要來源，改規則 = 改 PRD + 改測試 + 寫 CHANGELOG

7. **修改 PHP 檔案後必須清 PHP-FPM opcache，否則 Web 請求仍執行舊 bytecode**
   - PHP-FPM 預設 `opcache.enable=1`，**CLI 與 FPM 的 opcache 是分開的 shared memory**
   - `php artisan config:cache` / `route:cache` **不會**清 FPM opcache，PHPUnit 測試通過也不代表生產環境生效
   - 正確做法（三選一）：
     - 跑完整 `./deploy.sh`（已含 php-fpm restart）
     - `sudo service php8.2-fpm restart`
     - `cd backend && php artisan opcache:reset`（本專案自製指令，不需 sudo，透過 HTTPS loopback 觸發 `opcache_reset()`）
   - 本次 v1.4 部署踩過此坑：程式碼已合入 + 測試全綠，但使用者看到的薪資數字仍是 v1.3 的結果（截圖 1350 而非 2025），根因是部署時未清 FPM opcache
   - 若 `sudo` 需要互動密碼無法跑時，務必 fallback 到 `php artisan opcache:reset`

---

## §2026-04-17 — 內部聊天強化：AttachAuthUser 需同時查 teacher_branches（禁止回歸項）

### 根本原因
`AttachAuthUser` 原本只從 `UserCampus` 解析老師的校區清單。部分老師帳號只有 `teacher_branches` 記錄（無 `UserCampus`），導致 `auth_has_campus = false`，`require_campus` middleware 攔截並回傳 403，老師無法使用需要校區的任何 API（包含內部聊天聯絡人載入）。

### 禁止回歸項

1. **`AttachAuthUser` 是唯一合法的校區解析入口**
   - 所有 Controller 和 Service 必須使用 `auth_campus_ids` / `auth_has_campus` attribute，**禁止**在 Controller 層自行再查詢 `UserCampus` 或 `teacher_branches` 判斷校區歸屬。
   - 目前 `ProfileController` 有自行查 `teacher_branches` 的 fallback（為歷史遺留），這是**例外而非標準**，未來應移除。

2. **teacher_branches fallback 觸發條件必須同時滿足三個**
   ```php
   empty($campusIds) && $user->type === 'T' && Schema::hasTable('teacher_branches')
   ```
   - 只有 type=`T`（老師）才允許走 fallback
   - `UserCampus` 確實為空才查 fallback
   - 表不存在時不執行（避免測試環境 crash）

3. **無任何校區記錄的老師仍應收到 403**
   - 若 `UserCampus` 為空且 `teacher_branches` 也無記錄（兩表皆空），`auth_has_campus` 仍為 `false`，`require_campus` 正確回 403，Toast 應顯示「帳號尚未設定校區，請聯絡主任」。

4. **前端錯誤分類（`loadStaff`）禁止回歸為通用「請確認網路」**
   - HTTP 401 → 「登入逾期，請重新登入」
   - HTTP 403 + message 含 campus → 「您的帳號尚未設定校區，請聯絡主任」
   - HTTP 403 其他 → 「無存取權限，請聯絡管理員」
   - `!navigator.onLine` 或 `!status` → 「網路連線異常，請確認後重試」
   - 5xx → 「伺服器異常，請稍後重試」

5. **typing 端點 POST /api/v1/chat/threads/{id}/typing 必須驗證 thread 成員**
   - `ChatService::isMember($threadId, $userId)` 必須在廣播前執行
   - 非成員呼叫必須回 403，不得廣播至他人 thread

6. **`GET /api/v1/profiles` 必須保留在 `role:director,teacher` 群組，禁止在 director-only 群組重複註冊**
   - Laravel 相同 method+URI 後註冊的路由會**覆蓋**前者。若在 director-only 群組中重複 `Route::get('profiles', ...)`，老師一律被 `RequireRole` 擋成 403（message="Forbidden"），前端就算分類再精細也只能顯示「無存取權限」。
   - **director-only 群組只能放 profile 的寫入動作**：`POST /profiles`、`POST /profiles/bulk-teachers`、`POST /profiles/{id}/reset-password`、`PUT /profiles/{id}`、`DELETE /profiles/{id}`，以及 `teacher_branches` 三支 CRUD。
   - 回歸守護測試：`ChatEnhancementTest::test_teacher_can_call_get_profiles_endpoint`（老師 `GET /profiles` 不得 403）+ `test_teacher_cannot_post_profiles`（老師 `POST /profiles` 必須 403）。修改 `backend/routes/api.php` 的 profiles/teacher_branches 區塊時請先跑這兩個測試。

---

## §2026-04-18 — AI 工具靜默回退 api.php 路由（禁止回歸項）

### 根本原因

Claude Code 在 2026-04-17 執行多工重構時，**靜默刪除了 `backend/routes/api.php` 中 7 條 `schedule-discrepancies` 路由**，導致主任開啟「課表回報管理」頁面時收到 HTTP 404，老師無法提交出入回報。Controller（`ScheduleDiscrepancyController`）與 Migration（`2026_04_17_200000_create_schedule_discrepancies_table`）均存在，純粹是路由注冊被移除。

這是一種**靜默回退（Silent Revert）**的 AI 工具風險模式：AI 改動某個大檔案時，未讀取完整差異，直接以「記憶中的舊版本」覆蓋檔案局部，讓已知有效的新增行消失，但不產生任何語法錯誤或測試失敗（路由只有在 HTTP 請求時才顯現）。

### 禁止回歸項

1. **任何 `api.php` 修改後必須執行 `php artisan route:list` 確認關鍵路由仍存在**
   - 至少核查：`schedule-discrepancies`（7 條）、`profiles`（`GET` 在 `director,teacher` 群組）
   - 靜態路由（`/my`、`/summary`、`/active-for-session`）必須在動態路由（`/{id}`）**之前**出現

2. **`RouteRegistrationTest` 是 api.php 的路由守護測試，不得刪除或弱化**
   - `backend/tests/Feature/RouteRegistrationTest.php`
   - 7 個存在性斷言 + 1 個靜態-先於-動態順序斷言
   - 任何 `api.php` 修改後必須先跑此測試（`phpunit tests/Feature/RouteRegistrationTest.php`，8 tests 全綠）

3. **AI 工具修改 `api.php` 前必須先閱讀完整檔案**
   - `api.php` 超過 400 行，AI 工具若只讀前半段就開始輸出，會把後半段（含 `schedule-discrepancies` 區塊）隱性截掉
   - Cursor Agent：改動 routes 前先 `Read api.php` 完整檔案
   - Claude Code：改動 routes 前在 `CLAUDE.md` 有此條提示（見下）

4. **`CHANGELOG.md` 有記錄 ≠ 程式碼中存在**
   - 本次事件的教訓：`CHANGELOG.md` 2026-04-17 已記載「補回 7 條路由」，但實際 `api.php` 中這些路由被 Claude Code 再次移除
   - **任何新增的路由、函數、欄位，部署前必須以 `grep` / `route:list` / `php artisan tinker` 實際驗證存在**，不能只信 CHANGELOG

5. **新增 CLAUDE.md 提示（已加入）**
   - `CLAUDE.md` 的「routes 修改注意」段落已加入：「改動 routes/api.php 前必須先跑 `php artisan route:list --path=schedule-discrepancies` 確認 7 條路由均存在，並在修改後重跑」

### 回歸守護測試

```bash
# 路由存在性 + 靜態先於動態
./vendor/bin/phpunit tests/Feature/RouteRegistrationTest.php
# 8 tests, 10 assertions — 全綠才允許合併
```

### 一般化教訓（給所有 AI Agent）

> **「AI 大檔案覆蓋反模式」**：AI 工具對超過 300 行的關鍵設定檔（`api.php`、`AppServiceProvider.php`、重要 Vue 元件）進行「重構/整理」類操作時，極易因 context window 截斷或記憶偏差而靜默移除後段定義。
>
> **標準防護流程**：
> 1. 改前 `Read` 完整檔案，確認改動範圍
> 2. 改後針對關鍵特徵字串 `grep` 驗證
> 3. 有對應測試的，改後立即執行測試
> 4. 無法一次確認時，偏好「小改多驗」而非「大改少驗」

---

**不同工具如何接到本檔：** **Cursor** 透過 `AGENTS.md` 與 `.cursorrules`；**Claude Code** 讀根目錄 **`CLAUDE.md`**；**GitHub Copilot**／在 GitHub 上工作的 AI 讀 **`.github/copilot-instructions.md`**；人類協作者請看 **`CONTRIBUTING.md`**（皆連回本檔與繳費規則）。

相關專項規格：

- 主任儀表板「繳費提醒」完整規則：`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`
- 內部聊天、Bug 回報、使用者頭像（**含禁止回歸項**）：**`docs/AI_HANDOFF_CHAT_BUG_AVATAR.md`**
- **手動排課日期＝已上完（過去日）**：**`docs/MANUAL_SCHEDULE_DATE_SEMANTICS.md`**（勿擅自改語意）
- **主任「繳費／續課提醒」**：**`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`**（堂數制低堂數含已繳、月結「小於 5 天」等；**改動前必問使用者**）
- **課程 Stop 語意與 `closed_reason`**：見下方 **§2026-04-13 — 課程 Stop 語意：closed_reason 區分暫停 vs 結算**（勿移除 `settled` 寫入；勿用 `closed_reason` 影響 alert 篩選；resume 務必清除）
- **催繳名單與繳費單圖**：見下方 **§2026-04-13 — 催繳名單、tuition-slip 與 PaymentSlipModal**（名單須與 `alerts/tuition` 同源；**已繳不產圖**；無 Invoice 用 `tuition-slip`，勿與帳單編號語意混用）
- **催繳名單 payment_status 與 void API**：見下方 **§2026-04-16 — 催繳名單 payment_status 六種狀態與撤銷收款**（`payment_status` 後端計算不可前端自行推導；void API 的 DB transaction 不可拆開；`alerts/tuition` 列入規則不因補充欄位改變；void 僅限 director/admin/super_admin）
- **催繳名單幽靈課程（跳過續報直接新增）**：見下方 **§2026-04-16 — 催繳名單幽靈課程偵測與結案**（`has_newer_course` 欄位不可移除；結案用 `reason='settled'` 走 `togglePause`；結案不改 `Paid`；`newerCourseByStudentClassIds` batch query 不可改為 N+1）
- **固定排課／批次入班／學生課程列表「時段」／編輯課程改星期後未來堂**：見下方 **§2026-04-12 — 固定排課契約與堂次一致**（手動日、列表顯示、`PUT` 同步三項一次對照）
- **老師教學工作台**：見下方 **§2026-04-12 — 老師教學工作台（TeacherHome）**（預設頁、跨分校週課表、badge、deploy）
- **課程管理專注模式與 modal 層級**：見下方 **§2026-04-12 — 專注模式與 modal z-index / 契約時段不得被覆寫**
- **老師管理「授課學段」**：見下方 **§2026-04-13 — 老師管理須含授課學段（subject_level_scopes）**
- **當月學收取代帳單列表**：見下方 **§2026-04-13 — 當月學收月報（取代帳單列表）**（勿加回 `billing` 側欄項；API 只讀不改扣堂）
- **調課後評量表「消失」**（請假 cascade 作廢 LR + 堂次改日後已上）：見下方 **§2026-04-13 — 調課／請假 cascade 後評量表作廢未恢復**
- **出缺勤「補登」仍列出已暫停課程**：見下方 **§2026-04-13 — 出缺勤補登（`ended-sessions`）須排除 `StudentClass.Stop=1`**
- **手機出缺勤「請假確認沒反應」**：見下方 **§2026-04-14 — 手機請假確認：彈窗層級 + `leave/excused` 契約 + 非同步確認流程**
- **智慧排課誤標「取消」**（同日同時段 `cancelled + scheduled`）：見下方 **§2026-04-14 — 智慧排課角標誤判（張正樂 4/15）**
- **老師自助註冊 vs 主任待審／Teacher 重複鍵**：見下方 **§2026-04-15 — 老師註冊 Server Error 與 `directors/pending` 誤列老師**
- **老師管理側欄橘點 vs「待審核」**：見下方 **§2026-04-15 — 側欄 `pending_teachers` 與 `TeachersList`「待審核」不同步**
- **單堂加課衝突（已有出缺勤/核准評量）**：見下方 **§2026-04-15 — 單堂加課衝突修正**（`detectAddSessionConflict` 共用邏輯、前端預檢、結構化 409）
- **課程管理堂次警示誤報（請假/調課後）**：見下方 **§2026-04-15 — 請假調課後堂次警示假陽性**（`sessionUnits().length` 不可與購買堂數比較；須用 `effectiveSessionCount`）
- **單堂時間費率自動計算（`session_charge`）**：見下方 **§2026-04-17 — 單堂時間費率自動計算**（per-day duration 優先、Rate 變更 preserve delta、SmartCalendar 僅顯示不編輯）
- **課程管理同日 chip 重複（LEFT JOIN 行乘積）**：見下方 **§2026-04-15 — ClassSessionController::index LEFT JOIN 行乘積導致 chip 重複**（`sub_sched`／`LearningRecord`／`StudentSingIn` 的 LEFT JOIN 必須用 Derived Table 去重；前端 `normalizeClassSessionsPayload` 須有 id 去重防禦層）
- **評量頁課表同時段重複卡片**（`cancelled + scheduled` 同格兩張卡）：見下方 **§2026-04-15 — LearningRecordsPage 課表 widget 同格重複卡片（buildEvents 未去重）**
- **課表出入回報系統（老師回報／主任處理）**：見下方 **§2026-04-17 — 課表出入回報系統禁止回歸項**（`reporter_id` 後端注入不可改為前端傳；重複回報須回既有紀錄不可 insert 新筆；`missing_session` 不可綁 `class_session_id`；`resolved` 必填 ≥10 字說明；LINE Push 失敗不得阻擋 API；`withdrawn` 為軟取消不可硬刪；跨校 `require_campus` 必保留）

---

## 2026-04-17 — 課表出入回報系統禁止回歸項

**背景**：此系統解決「主任課」（主任排班、他人授課）在紙本/LINE 改動後系統未即時同步、導致老師點名遇到 4 種出入狀況的長期痛點（詳見 PRD 與 CHANGELOG）。以下為**禁止回歸**的關鍵契約，任何人碰到相關檔案時**必對照本節**。

| 禁止項 | 理由 | 檔案 / 檢查點 |
|---|---|---|
| **`reporter_id` 由 body 傳入** | 任何 T 帳號可偽冒他人回報，破壞稽核性 | `ScheduleDiscrepancyController::store()` 必須以 `$this->resolveUserId()` 讀 `auth_user`，**絕不可** 接受 request body 的 `reporter_id`；test `test_teacher_can_submit_discrepancy_for_known_session` 已鎖此行為 |
| **重複回報 insert 新筆** | 同一堂次會有多筆 pending 噪音，違反 FR-003 | `store()` 對有 `class_session_id` 的 payload 必先 `whereIn status pending/acknowledged` 查詢；存在時回 200 + `duplicate:true` + 現有紀錄，**不得** 新建 |
| **`missing_session` 類型允許綁定 `class_session_id`** | 語意矛盾（「此課不在系統」卻綁 session） | `store()` L.44-49 的 guard 不可拿掉 |
| **`missing_session` 允許空 subject/student/time** | 主任拿不到上課資訊無法處理 | 相同 guard L.50-54，三欄位為必填 |
| **`resolved` 處理說明 < 10 字** | 違反 FR-008 稽核要求 | `updateStatus()` `mb_strlen($note) < 10` 回 422 不可移除 |
| **已結案（resolved/withdrawn）改回 pending/acknowledged** | 破壞生命週期不可逆原則 | `updateStatus()` 回 409；test `test_status_lifecycle_with_resolution_note_guard` 覆蓋 |
| **LINE Push 失敗拋例外阻擋 API** | 雲端/token 掛掉會連帶老師無法回報 | `ScheduleDiscrepancyNotifier` 一定用 try/catch + log，`fireNotification` 用 `dispatch(...)->afterResponse()`；test `test_submit_succeeds_even_without_line_config` 鎖此路徑 |
| **`withdraw` 改為硬刪** | 失去誰在何時撤銷的稽核 | `withdraw()` 僅改 `status=withdrawn` + `withdrawn_at`，不可 `->delete()` |
| **老師撤銷他人回報** | 權限洩漏 | `withdraw()` 內必驗 `reporter_id === userId`；test `test_other_teacher_cannot_withdraw_someone_elses_report` |
| **主任已確認後還允許老師撤銷** | 稽核脆弱（撤掉已進入處理流程的紀錄） | `withdraw()` 必驗 `status === pending`，否則 409；test 已覆蓋 |
| **移除 `require_campus` middleware** | 跨校讀取/寫入會被打通 | `routes/api.php` 所有 schedule-discrepancy 路由保留 `require_campus`；test `test_cross_campus_director_cannot_view_other_campus_reports` 鎖此 |
| **封存改為硬刪或降低保留年限** | PRD 第 6 節明訂 12 個月保留；刪除會破壞日後稽核 | `ArchiveScheduleDiscrepancies` 只改 `archived_at`，預設 12 個月 |
| **主任儀表板/管理頁 summary 算入 `archived_at` 非 null 者** | 舊資料會灌爆提示，操作者抗拒使用 | `summary()`/`index()` 預設 `whereNull('archived_at')` |

**測試護欄**：`backend/tests/Feature/ScheduleDiscrepancyApiTest.php`（14 tests）— 改動上述任一行為前，**先跑此套件，確認原測試仍通過或有意識地更新**。

**前端禁止回歸**：
- `AttendancePage.vue` 的 「回報出入」按鈕、「已回報」徽章、「有課不在列表中？點此回報」CTA 不得因重構被移除——這些是 FR-001/003/004 的使用者入口。
- `ReportDiscrepancyModal.vue` 的 `withdraw` 按鈕只能在 `status=pending` 時出現；其他狀態改為唯讀。
- `DirectorDashboard.vue` 課表回報卡片與 `App.vue` sidebar 的「課表回報管理」入口不得因佈局調整被拿掉（否則主任看不到待處理提示 → 流程崩潰）。

---

## 2026-04-17 — 單堂時間費率自動計算（`session_charge`）

| 項目 | 說明 |
|---|---|
| 問題 | 老師/主任在「課程管理」或「智慧排課」調整單堂上課時長（例如 2hr → 1.5hr 或 3hr），`StudentClass.Charge` 不會跟著時間比例調整；事後必須手動改帳單／課程費用，容易遺漏。 |
| 修正 | `ClassSession` 新增 `session_charge`（nullable INT）；`ClassSessionController::syncSessionChargeForTimeChange` 在 `start_time`/`end_time` 異動時，依 `rate_unit`（session/hour）與堂次**當日**的標準時長（`duration{N}` > `SessionDuration`）換算 `session_charge`，並以 `delta = new_session_charge − (old_session_charge ∥ standard_charge)` 同步 `StudentClass.Charge`。`StudentClassController::update` 動 `Rate`/`SessionCount` 時保留累積的 `preserved_delta`，不直接覆寫 Charge。前端在 `SessionEditModal` 加入費用預覽、±50% 二次確認；SmartCalendar 僅顯示不編輯。 |

**禁止回歸：**

1. **per-day duration 優先於 `SessionDuration`**：堂次當日若有 `duration{N}`（對 ISO weekday），`syncSessionChargeForTimeChange` 必須用該值當分母；fallback 才是 `SessionDuration`。勿改成一律 `SessionDuration`，否則 Mon 120min / Fri 90min 這種異時長課程會在 Friday 堂次算錯（見 `test_per_day_duration_is_used_when_set_on_session_weekday`）。
2. **Rate/SessionCount 異動保留 delta**：`StudentClassController::update` 在 `isset($mapped['Rate']) || isset($mapped['SessionCount'])` 時，**必須** snapshot 舊 Rate/Count/rate_unit，計算 `preserved_delta = 舊 Charge − 舊 Rate × 舊 Count`，新 Charge = `Rate_new × Count_new + preserved_delta`。**勿改回直接 `Rate × Count` 覆寫**，否則會把單堂時間調整累積的手動金額全部洗掉（見 `test_course_rate_update_preserves_accumulated_session_charge_delta`）。
3. **SmartCalendar 維持顯示-only**：禁止在 SmartCalendar 單堂 modal 加入時間／費用編輯 UI；所有單堂時間調整必須走課程管理的 `SessionEditModal`，保持單一編輯入口與統一的二次確認流程。
4. **baseline 取捨**：`old_session_charge !== null` 用舊值，否則用標準費用；勿改成每次都用標準，否則連續編輯會漏算先前差額（見 `test_second_edit_uses_previous_session_charge_as_baseline`）。
5. **`session_charge` 是財務敏感欄位**：`PATCH /api/v1/class-sessions/{id}` 只接受 `start_time` / `end_time`，前端不可直接傳 `session_charge`；計算永遠在後端。
6. **Rate ≤ 0 或標準時長 ≤ 0 必須 no-op**：`session_charge` 保持 null，`Charge` 不動。勿把 0 當分母。

測試依據：`backend/tests/Feature/ClassSessionChargeTest.php`（9 case）。

---

## 2026-04-16 — 催繳名單幽靈課程偵測與結案

| 項目 | 說明 |
|---|---|
| 問題 | 工作人員在學生需續課時，未用「續報加購」（`purchase-batch`）而直接「新增課程」。舊課程 `Stop=0`、`RemainingSessions=0`、`Paid=1` 永久出現在催繳名單（`renew_needed`），成為幽靈記錄。 |
| 修正 | `AlertController::tuition` 新增 `has_newer_course`、`newer_course_id`、`newer_course_remaining`、`newer_course_start_date` 欄位，透過 `newerCourseByStudentClassIds` batch query 偵測同學生同科目活躍課程。`TuitionCollectionPage.vue` 新增「已有新課程」badge 與「結案」按鈕，呼叫 `POST /student-classes/{id}/pause` with `reason='settled'`。 |

**禁止回歸：**

1. **勿移除 `has_newer_course` 欄位**：前端 `TuitionCollectionPage.vue` 依賴此欄位顯示綠色 badge 和引導結案。
2. **結案不改 `Paid`**：`togglePause` 的 `settled` 分支只設 `Stop=1`、`closed_reason='settled'`、`EndDate=today`，不動 `Paid` 欄位。
3. **`newerCourseByStudentClassIds` 必須 batch query**：勿改為 loop 內逐筆查詢（N+1），影響 `alerts/tuition` 效能。
4. **結案走 `togglePause` 端點**：受現有 `auth`、`role`（director/admin/super_admin）、`require_campus` middleware 保護，勿另建無保護端點。
5. **`suppressRenewedLowSessionAlerts`（同日另一修正）與此功能互補**：前者自動抑制舊課程的 `low_sessions` 提醒（但需新課程 `RemainingSessions > 2`）；後者讓主任手動結案（即使新課程堂數也低）。兩者不互相取代。

---

## 2026-04-16 — 催繳名單 payment_status 六種狀態與撤銷收款（void API）

| 項目 | 說明 |
|------|------|
| **曾發生的問題** | 催繳名單只有 `paid`（布林）+ `last_paid_at` 兩欄，「有日期但顯示未繳費」讓現場誤判已結清；主任無法在名單直接核帳或撤銷錯誤收款。 |
| **根本原因** | `alerts/tuition` 缺乏中間狀態（部分付款、待核帳、月結將到期等），二分法不足以表達真實付款語意。 |
| **正確行為** | 後端 `AlertController::computePaymentStatus()` 依優先序回傳六種狀態值（`pending_report` > `partial` > `unpaid` > `renew_needed` > `monthly_due_soon` > `paid`），前端 `TuitionCollectionPage.vue` 依 `payment_status` 渲染標籤與操作按鈕。新增 `void` API 可撤銷已確認收款並回滾 Payment/Invoice/StudentClass。 |
| **禁止回歸** | **(a)** `payment_status` 計算邏輯必須集中在後端 `AlertController::computePaymentStatus`，前端禁止自行推導。**(b)** `alerts/tuition` 的列入規則（堂數制 / 月結制條件）不因 payment_status 補充欄位而改動。**(c)** `PUT /api/v1/payment-reports/{id}/void` 的 DB transaction 不可拆開（Payment/Invoice/StudentClass 三表一致性）。**(d)** void API 僅限 `role:director,admin,super_admin`，teacher 角色不可呼叫（後端 middleware 已限制）。**(e)** void 操作必須寫入 `voided_by`、`voided_at`、`void_reason` 稽核欄位。**(f)** 已繳（`paid=true`）不得產出催繳通知單圖片（`tuitionSlipData` 的 422 guard 不得移除）。**(g)** `outstanding = max(0, charge - paid_amount)` 不可為負。 |
| **關聯檔案** | `backend/app/Http/Controllers/AlertController.php`、`backend/app/Http/Controllers/PaymentReportController.php`（`void()`）、`backend/app/Models/PaymentReport.php`、`frontend/src/pages/TuitionCollectionPage.vue`、`backend/tests/Feature/TuitionAlertsApiTest.php`、`backend/tests/Feature/PaymentReportApiTest.php`、`backend/database/migrations/2026_04_16_210000_add_void_fields_to_payment_reports_table.php` |

### 高風險區塊（修改前必對照）

| 檔案 | 方法 | 注意 |
|------|------|------|
| `AlertController.php` | `computePaymentStatus()` | 狀態機優先序不可調換；新增狀態值須同步前端 `STATUS_CONFIG` |
| `AlertController.php` | `tuition()` | 補充欄位查詢用批次 `whereIn`，不得逐筆查詢（N+1） |
| `PaymentReportController.php` | `void()` | 整個回滾必須在 `DB::transaction` 內；負值 Payment 金額正確 |
| `TuitionCollectionPage.vue` | `STATUS_CONFIG` / `statusLabel()` | 六種狀態值與後端一對一對應 |
| `AlertController.php` | `tuitionSlipData()` | `Paid=1` 仍回 422，不因 payment_status 改變此邏輯 |
| `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` | — | 列入條件變更前必問使用者 |

---

## 2026-04-15 — LearningRecordsPage 課表 widget 同格重複卡片（buildEvents 未去重）

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 老師評量頁（`LearningRecordsPage.vue`）的課表 widget，鄭翔祐 4/15 19:30-21:30 看到張正樂出現兩張「國文 未填」評量卡。 |
| **根本原因** | 同一 `StudentClassID` 同日同時段存在兩筆 `ClassSession`（`cancelled` + `scheduled`），`buildEvents` 直接迭代所有 `rawSessions` 而無 status 過濾或去重，每筆 ClassSession 都生成一張卡片。此問題與 §2026-04-14 智慧排課角標誤判（張正樂 4/15）**同源**，但 SmartCalendar 的修正（`SESSION_STATUS_PRIORITY` + `pickBestSessionRow`）未同步至 LearningRecordsPage。 |
| **正確行為** | `buildEvents` 對每門課的 rawSessions 先以 `(session_date, start_time)` 分組，同組多筆用統一優先序（`attended/completed/late/absent > scheduled > leave/leave_adjusted/excused > cancelled`；同狀態 `id desc`）只保留最優一筆，再生成卡片。 |
| **禁止回歸** | **(a)** 勿移除 `buildEvents` 中的 `deduplicateSessionsBySlot` 呼叫。**(b)** 勿把優先序改為 `cancelled` 高於 `scheduled`。**(c)** `SESSION_STATUS_PRIORITY` 與 `pickBestSession` 須與 `SmartCalendar.vue` 的同名常數保持一致。**(d)** 新增其他消費 `sessionDatesByClassId` 的路徑時，同樣必須套用去重。 |
| **關聯檔案** | `frontend/src/pages/LearningRecordsPage.vue`（`SESSION_STATUS_PRIORITY`、`pickBestSession`、`deduplicateSessionsBySlot`、`buildEvents`）、`frontend/src/pages/SmartCalendar.vue`（同名常數）、`backend/tests/Feature/ClassSessionDuplicateStatusTest.php` |
| **測試** | 既有 `ClassSessionDuplicateStatusTest`（DB 層）；前端可手動驗證同格 `cancelled+scheduled` 時評量頁只顯示一張卡。 |

---

## 2026-04-15 — ClassSessionController::index LEFT JOIN 行乘積導致 chip 重複

| 項目 | 說明 |
|------|------|
| **曾發生的問題** | 課程管理頁木柵校林宥彣理化課程，1/12 同一時段（16:00-18:00）顯示 3 個相同的「取消」chip。點任一個標記「已上」或「取消」，3 個全部同步變動。 |
| **根因** | `ClassSessionController::index`（L85–139）對 `schedules`（`sub_sched`）使用 `leftJoin`，當同一 `(student_course_id, DATE(schedule_date), start_time)` 組合有多筆 `status=scheduled AND original_schedule_id IS NOT NULL` 的記錄時，SQL Cartesian Product 使同一 `ClassSession` 出現 N 次。案例：course 70 在 2026-01-12 有 3 筆符合條件的 substitute schedule。前端 `normalizeClassSessionsPayload` 無 id 去重，照單全收 push 進 `byClass`，導致 Vue `v-for` 渲染重複 chip（`:key=id:XXX` 三個完全相同）。 |
| **修正** | (1) 後端 `sub_sched`、`LearningRecord`、`StudentSingIn` 三個 LEFT JOIN 全改為 **Derived Table Subquery**，每組合只取 `MAX(id)` 一筆。(2) 前端 `normalizeClassSessionsPayload` 加 `id` 去重（`.some()` 檢查）作為防禦層。(3) `updateLocalSessionRow` 改為遍歷所有同 id 列（非只 `findIndex` 第一筆）。 |
| **禁止回歸** | **(a)** 勿把 `sub_sched`/`LearningRecord`/`StudentSingIn` 的 LEFT JOIN 改回直接 join（不加 Derived Table 去重）。**(b)** 勿移除 `normalizeClassSessionsPayload` 中的 id 去重邏輯。**(c)** 新增 LEFT JOIN 到 `ClassSessionController::index` 時，必須評估是否會造成行乘積（1:N 關係必須用 Derived Table 或 subquery 限定為 1:1）。 |
| **關聯檔案** | `backend/app/Http/Controllers/ClassSessionController.php`（`index` 方法）、`frontend/src/lib/classSessionsApi.js`（`normalizeClassSessionsPayload`）、`frontend/src/composables/course-management/useCourseSessionsDisplay.js`（`updateLocalSessionRow`） |
| **資料稽核** | `LearningRecord`/`StudentSingIn` 無重複非作廢列；`schedules` 有 2 組重複（course 70 × 3、course 190 × 4）。重複資料不需清理——Derived Table 已在 query 層面處理。 |

---

## 2026-04-15 — 請假調課後堂次警示假陽性

| 項目 | 說明 |
|------|------|
| **曾發生的問題** | 課程管理頁展開上課日期面板時，購買 8 堂、已上 6、剩餘 2（含請假與調課）的課程仍顯示「⚠ 排程列數與購買堂數不一致」。使用者（主任）誤以為系統資料異常，實際數據正確。 |
| **根因** | 前端 `CourseManagement.vue` 用 `sessionUnits(c).length !== getPurchasedSessions(c)` 做警示判定。`sessionUnits` 只排除 `cancelled`，**仍包含 `leave/leave_adjusted`**（請假列），導致「請假原堂 + 補課新堂」使總列數 > 購買堂數，觸發假陽性。而後端 `extendSessionsIfNeeded` 已明確排除 `cancelled/leave/leave_adjusted`。 |
| **修正** | (1) 在 `useCourseSessionsDisplay.js` 新增 `SESSION_NOT_OCCUPYING_QUOTA` 狀態矩陣常數，與後端口徑一致。(2) 新增 `effectiveSessionCount`（排除非占額狀態的堂次數）。(3) 新增 `sessionCountWarning` 結構化警示判定（`over`/`under_leave`/`under_other`）。(4) 前端警示改用 `sessionCountWarning(c)` 取代原始列數比較。(5) 請假未補課時文案改為「有請假堂次尚未補課」（藍色資訊色），與真異常黃色警告區分。 |
| **禁止回歸** | **(a)** 勿把警示條件改回 `sessionUnits().length !== purchased` 或任何包含請假列的計數。**(b)** `SESSION_NOT_OCCUPYING_QUOTA` 與後端 `extendSessionsIfNeeded` 的 `whereNotIn` 必須同步維護。**(c)** 勿讓 `effectiveSessionCount` 影響 `displayRemainingSessions`——兩者解耦。 |
| **狀態矩陣** | 占購買額度：`scheduled`, `attended`, `completed`, `late`, `absent`。不占：`cancelled`, `leave`, `leave_adjusted`, `excused`。 |
| **測試** | `backend/tests/Feature/SessionCountWarningTest.php`（5 案例：CaseA~E）。 |
| **關聯檔案** | `frontend/src/composables/course-management/useCourseSessionsDisplay.js`、`frontend/src/pages/CourseManagement.vue`、`backend/app/Http/Controllers/StudentClassController.php`（`extendSessionsIfNeeded`） |

---

## 2026-04-15 — 單堂加課衝突修正

| 項目 | 說明 |
|------|------|
| **曾發生的問題** | 主任在課程管理/學生管理「加課／補登」選了一個已有出缺勤或核准評量的日期＋時段，系統只彈出「該堂已有出缺勤或核准評量，無法重覆補登」，使用者不知如何解決，只能反覆嘗試或致電客服。 |
| **根因** | `StudentClassController::addSession()` 偵測到同日時段有 `ClassSession` 且在鎖定集合（`StudentSingIn` / approved `LearningRecord`）時，回傳單一 `message` 的 409，前端只做 `alert(json.message)`，無引導。 |
| **修正** | (1) 抽出 `detectAddSessionConflict()` 為 check 與 addSession 共用的私有方法；(2) 409 改為結構化 JSON（`error_code`, `conflict_type`, `has_attendance`, `has_approved_learning_record`, `conflict_session_id`, `suggested_actions`）；(3) 新增 `POST add-session/check` 唯讀預檢端點；(4) 前端 `QuickAddSessionModal` 日期/時間變更後自動預檢，衝突時顯示橘色 banner 並禁用送出。 |
| **禁止回歸** | (a) 勿將 `check` 與 `addSession` 的衝突判斷拆成兩份邏輯——必須共用 `detectAddSessionConflict`。(b) 409 回應必須保留 `message` 欄位（舊前端相容）。(c) 鎖定堂次（有出缺勤或核准評量）不可被覆寫，此為硬規則。 |
| **測試** | `tests/Feature/AddSessionConflictTest.php`（11 案例）：locked by attendance、locked by LR、overwrite unlocked、full capacity、movable session、check endpoint (locked/ok/full)、backward compat message、race condition、check vs add-session error_code 一致性。 |

---

## 2026-04-15 — 側欄 `pending_teachers` 與 `TeachersList`「待審核」不同步

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 主任切到大直分校：側欄 **「老師管理」** 顯示 **橘點（例如 1）**，進入頁面後 **「待審核」** 分頁與摘要卡皆為 **0**，正式老師列表卻看得到該員（**在職**）。現場以為通知壞掉。 |
| **根本原因（雙來源）** | **(1)** `GET /api/v1/notifications/unread-count` 的 `by_type.pending_teachers` 由 **`NotificationController::unreadCount`** 計算： **`User.type=T` 且 `UserCampus.Approved=false`**（分校人員綁定尚未放行）。**(2)** `TeachersList.vue` 的「待審核」只數 **`User.status === 'pending'`**（帳號層級）。兩者**不同欄位**；可能出現 **`status=active` 但某分校 `UserCampus.Approved` 仍為 0**（例如早期只把帳號核准、未寫回 `Approved`，或手動／遷移資料不一致）。**(3)** 前端 **`approveTeacher`** 只送 **`PUT /api/v1/profiles/{id}` + `{ status: 'active' }`**，若後端未一併處理 `UserCampus`，**核准後橘點仍不會消**。 |
| **正確行為** | **產品語意**：側欄若要代表「有分校綁定待放行」，頁面應有對應提示（或與 `status=pending` 對齊）；**技術上**將老師設為 **`active` 時應同步** 該員所有 **`UserCampus.Approved=true`**（`ProfileController::update`）。**診斷**：`User` join `UserCampus` where `type=T` and `Approved=0` and `CampusID=分校`。 |
| **禁止回歸** | **(a)** 勿假設 `pending_teachers` 等於「待審核 tab 人數」；改 badge 或改列表前須先對齊產品定義。**(b)** 勿移除「`status` 改 `active` 時釋出 `UserCampus.Approved`」邏輯（除非改由專用 API 核准且全路徑覆蓋）。**(c)** 解讀現場問題時先查 **DB 是否 `active` + `Approved=0`**，勿只盯前端 tab。 |
| **關聯檔案** | `backend/app/Http/Controllers/NotificationController.php`（`unreadCount`、`pending_teachers`）、`frontend/src/App.vue`（`badgeTypes: pending_teachers`）、`frontend/src/pages/TeachersList.vue`（`pendingCount`、`approveTeacher`）、`backend/app/Http/Controllers/ProfileController.php`（`update`） |
| **資料修復（一次性）** | 若已上線累積 **`User.status=active`** 且 **`UserCampus.Approved=0`**：可 **`UPDATE UserCampus SET Approved=1 WHERE UserID IN (...)`** 限縮在已確認可放行之列；或請主任對該員再觸發一次帶 `active` 的 **`PUT profiles`**（後端已補同步時會清掉）。 |
| **搜尋用關鍵字** | pending_teachers、unread-count、UserCampus Approved、老師管理橘點、待審核 0、楊宸宇（案例：大直 `CampusID=3`） |

---

## 2026-04-15 — 老師註冊 Server Error 與 `directors/pending` 誤列老師

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | **(1)** 老師自助註冊回傳 **Server Error**：`Teacher` 表已有同 `(CampusID, T_Name)` 舊列時，`INSERT` 觸發 unique（MySQL 錯誤鍵名常顯示為 `CampusID`），整筆 `register` transaction 回滾。**(2)** 超級管理員「主任管理」→「待審申請」出現 **老師**（例如預設分校為大直、姓名楊宸宇）：與產品預期不符。 |
| **根本原因** | **(1)** `AuthController::register` 只以 `Teacher.id = User.id` 判斷是否存在，未涵蓋「同校同名不同 id」的歷史 `Teacher` 列。**(2)** `GET /api/v1/directors/pending` 用 **`UserCampus.Approved = false`** 撈人，**未過濾 `User.type`**；老師註冊也會寫入 `Approved=false` 的 `UserCampus`，故與主任申請（`type=U`）混在同一 API。 |
| **正確行為** | **(1)** 寫入 `Teacher` 使用 **`insertOrIgnore`**（或等價：先查 `(CampusID, T_Name)` 再決定 insert/update），避免舊資料阻擋新 `User` 建立。**(2)** `directors/pending` **僅回傳** `User.type` 為 **`U` 或 `A`** 的待審者；**老師待審**由 **`TeachersList`「待審核」**、`User.status=pending`、`ProfileController` 核准路徑處理。 |
| **禁止回歸** | **(a)** 勿把 `directors/pending` 改回「只依 `UserCampus.Approved` 不過濾 type」。**(b)** 勿在 `AuthController::register` 對 `Teacher` 改回裸 `insert()` 而無衝突處理（除非產品改為明確拒絕並回傳可讀訊息）。**(c)** 解讀 `1062 Duplicate entry '3-某某'` 時：**數字為 `Campus.id`**，勿口頭誤稱校名。 |
| **關聯檔案** | `backend/app/Http/Controllers/AuthController.php`（`register`、`Teacher`）、`backend/app/Http/Controllers/DirectorAccountController.php`（`pending`）、`frontend/src/pages/DirectorAccountsPage.vue`、`frontend/src/pages/TeachersList.vue` |
| **測試** | `tests/Feature/ResetDataAndDirectorFlowTest.php` — `test_directors_pending_excludes_pending_teachers` |
| **搜尋用關鍵字** | directors/pending、待審申請、Teacher insertOrIgnore、Duplicate entry CampusID、UserCampus Approved、老師註冊 |

---

## 2026-04-14 — 智慧排課角標誤判（張正樂 4/15）

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 行事曆同一格有課程卡，但角標顯示「取消」。典型案例：張正樂 4/15（同課程同時段存在 `cancelled` 與 `scheduled` 兩筆 `ClassSession`）。 |
| **根本原因** | `SmartCalendar.vue` 的 `findSessionRowForCell` 只用日期+起始時間 `find()` 第一筆；當資料排序先遇到舊 `cancelled` 列時，會覆蓋掉同格的有效堂次。另有代課 modal 兩處 `sessions.find(...)` 也可能抓到錯列。 |
| **正確行為** | 同格多筆堂次必須走**統一解析器**：狀態優先序 `attended/completed/late/absent > scheduled > leave/leave_adjusted/excused > cancelled`；同狀態用 `id desc`（較新優先）作 tie-break。`rollCallBadge`、`evalBadge`、tooltip/操作入口與代課 session 選取都要共用同一規則。 |
| **禁止回歸** | **(a)** 勿把 `findSessionRowForCell` 改回單純 `.find()`。**(b)** 勿在 `rollCallBadge` / `evalBadge` / 右鍵操作各自重寫判斷邏輯（必須共用解析器）。**(c)** 勿在代課 modal 用「同日第一筆」直接取 `session_id`。**(d)** 勿讓 `useCourseSessionsDisplay` 優先顯示 `cancelled` 高於 `scheduled`。 |
| **關聯檔案** | `frontend/src/pages/SmartCalendar.vue`（`SESSION_STATUS_PRIORITY`、`pickBestSessionRow`、`findSessionRowForCell`、代課 session_id 解析）、`frontend/src/composables/course-management/useCourseSessionsDisplay.js`（`getSessionDisplayRow`、`getSessionState`）、`backend/tests/Feature/ClassSessionDuplicateStatusTest.php` |
| **測試** | `ClassSessionDuplicateStatusTest`（`cancelled+scheduled`、全 `cancelled`、`leave+scheduled`） |
| **觀測口徑** | 監控 `ClassSession` 同 `StudentClassID + SessionDate + StartTime` 多筆比例；若新增異常集中再進入資料整併評估。 |

---

## 2026-04-14 — 手機請假確認：彈窗層級 + `leave/excused` 契約 + 非同步確認流程

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 老師手機在出缺勤頁把堂次改為「請假」後，點「確認送出」看似無反應。 |
| **根本原因（雙因子）** | **(1) UI 層級**：`AttendancePage` 的確認彈窗 `z-index` 低於全域 `mobile-bottom-nav`，按鈕被底部導覽覆蓋。**(2) API 契約**：前端送 `Status='leave'`，但 `AttendanceController` 驗證僅允許 `excused`（未含 `leave`），導致 422。另有 **(3) 互動流程**：確認按鈕觸發後立即關閉 dialog，錯誤不易被看見。 |
| **正確行為** | **UI**：確認彈窗層級高於手機底部導覽，並有 `safe-area` 底距。**API**：`AttendanceController::store` 與 `batchMark` 同時接受 `leave` 與 `excused`（輸入相容），內部統一為 `leave` 語意。**互動**：確認按鈕必須 `await` API，送出中禁用；成功才關閉，失敗保留彈窗並顯示可讀錯誤。 |
| **禁止回歸** | **(a)** 勿把確認彈窗 `z-index` 改回低於 `.mobile-bottom-nav`。**(b)** 勿在出缺勤 API 驗證移除 `leave`（會讓手機前端再度 422）。**(c)** 勿把確認送出改回「呼叫後立即關閉 dialog」。**(d)** 勿只回傳泛用 `Forbidden`，至少要有可讀權限訊息，避免現場誤判。 |
| **關聯檔案** | `frontend/src/pages/AttendancePage.vue`、`backend/app/Http/Controllers/AttendanceController.php`、`frontend/src/styles.css` |
| **測試** | `tests/Feature/AttendanceLeaveStatusContractTest.php`、`tests/Feature/AttendanceExcusedLeaveCascadeTest.php`、`tests/Feature/AttendanceBatchMarkTest.php` |
| **搜尋用關鍵字** | mobile-bottom-nav、att-confirm-overlay、leave status 422、confirmDialog await、attendance batch leave |

---

## 2026-04-13 — 出缺勤補登（`ended-sessions`）須排除 `StudentClass.Stop=1`

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 課程已在課程管理 **暫停**（`StudentClass.Stop = 1`，`togglePause` 會將未來堂次改為 `cancelled`），但主任在 **出缺勤 → 補登** 仍看到該課堂次（`AttendancePage` 呼叫 **`GET /api/v1/attendance/ended-sessions`**）。 |
| **根本原因** | `endedSessions` 組 `classIds` 時 **`StudentClass::whereIn(StudentID, …)->pluck('ID')` 未加 `where('Stop', 0)`**；且堂次查詢 **`whereNotIn('Status', ['attended','completed','late'])` 仍會納入 `cancelled`**，暫停取消的堂次符合「已結束、無有效簽到」條件而被列出。 |
| **正確行為** | 補登清單只應包含 **進行中契約**（`Stop = 0`）的課程；`Stop = 1` 的課程（手動暫停、結算、結案，`closed_reason` 不影響此處）**一律不列入** `classIds`（主任與老師代課彙總路徑皆同）。 |
| **禁止回歸** | **(a)** 勿在 `endedSessions` 移除 **`where('Stop', 0)`**（或等價篩選），否則暫停課程的 `cancelled` 堂次會再回到補登。**(b)** 新增其他「依課程列可操作堂次」的 API 時，預設應與 **`Stop=0`** 對齊，除非產品明確要查歷史／稽核並另開參數。 |
| **關聯檔案** | `backend/app/Http/Controllers/AttendanceController.php`（`endedSessions`）、`frontend/src/pages/AttendancePage.vue`（`fetchMakeupSessions` → `attendance/ended-sessions`） |
| **測試** | `tests/Feature/MakeupAttendanceEndedSessionsTest.php` — `test_ended_sessions_excludes_paused_student_class` |
| **搜尋用關鍵字** | ended-sessions、補登、MakeupAttendance、StudentClass Stop、togglePause、暫停 |

---

## 2026-04-13 — 調課／請假 cascade 後評量表作廢未恢復

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 營運流程：某堂先走 **請假 cascade**（`CourseLeaveCascadeService::applyLeaveCascade`）→ 該堂 `LearningRecord` 被標 **`VoidedAt`**（作廢）；之後以 **`POST /api/v1/learning-records/reschedule-session`** 把 **同一筆** `ClassSession` 改到別日並標 **已上**（`attended` 等）。結果：`ClassSession` 顯示已上，但列表／補建邏輯只看到「該 `ClassSessionID` 已有一筆 LR」→ **`ensurePastRecords` 舊版對作廢列直接 `continue`** → 評量表在 UI 上永遠不見。另：`ClassSessionController::update` 允許 **`leave → attended`**，但落入「通用只改狀態」分支時 **不會** 恢復作廢 LR。 |
| **正確行為** | **`ensurePastRecords`**：堂次狀態為 **`attended` / `completed` / `late` / `absent`**（已上課口徑，且查詢已排除 `leave` / `leave_adjusted` / `cancelled`）時，若唯一 LR 為作廢列 → **un-void**（清 `VoidedAt` / `VoidedByUserID` / `VoidReason`，`Status=pending`，日期時間與 `ClassSession` 對齊），**不得**再 `INSERT` 第二筆（unique 約束）。**`leave → attended`（及 `late`/`absent`/`completed`）**：在 `ClassSessionController::update` 成功存檔後呼叫 **`restoreVoidedLearningRecord`**，立即恢復同一筆作廢 LR。 |
| **禁止回歸** | **(a)** 勿把 `ensurePastRecords` 改回「只要 `LearningRecord::where(ClassSessionID)->first()` 存在就一律 `continue`」而忽略「堂次已已上、LR 仍作廢」的 self-heal。**(b)** 勿在 `leave → attended` 路徑拿掉 `restoreVoidedLearningRecord`（或等價邏輯）。**(c)** 仍須遵守 2026-04-12「請假與學習評量」：`excludeLeaveSessionPendingReview`、`ensurePastRecords` **不對請假堂補建**；本節僅補「**請假後堂次已不再是請假、且已上**」時的 LR 恢復，與「請假堂不顯示 pending」不衝突。 |
| **關聯檔案** | `LearningRecordController.php`（`ensurePastRecords`）、`ClassSessionController.php`（`update`、`restoreVoidedLearningRecord`）、`CourseLeaveCascadeService.php`（作廢 LR）、`LearningRecordController.php`（`rescheduleSession` 會改 `ClassSession.SessionDate`）、`tests/Feature/LearningRecordApprovalDeductionTest.php` |
| **測試** | `LearningRecordApprovalDeductionTest::test_ensure_past_does_not_recreate_voided_record`（斷言改為：應恢復 1 筆、不新增列、作廢欄位清空） |
| **搜尋用關鍵字** | ensure-past、作廢 LR、un-void、reschedule-session、調課、restoreVoidedLearningRecord、leave→attended |

---

## 2026-04-13 — 請假狀態單一化：`excused` 併入 `leave`

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 系統同時存在 `excused` 與 `leave` 兩種「請假」語意：課程管理多走 `leave`，出缺勤可寫 `excused`，導致狀態機、查詢過濾、UI 按鈕與測試口徑分岔。AI 常在新改動時只修其中一條路徑，造成回歸。 |
| **正確行為** | **唯一一般請假狀態為 `leave`**；補請假維持 `leave_adjusted`。`AttendanceController` 可為相容性接受 `excused` 輸入，但必須立即映射成 `leave` 後續處理。`ClassSession` / `StudentSingIn` 新資料不可再寫入 `excused`。 |
| **禁止回歸** | **(a)** 勿在 `ClassSessionController::STATUS_TRANSITIONS` 重新加入 `excused`。**(b)** 勿在 `ScheduleController` / `AttendanceController` 新增或恢復 `StudentSingIn.Status='excused'` 寫入。**(c)** 勿在課程管理單堂操作加回「公假」按鈕。**(d)** 勿把 `leave` 顯示文案改成「離班」（本域語意應為「請假」）。 |
| **關聯檔案** | `backend/app/Http/Controllers/ClassSessionController.php`、`backend/app/Http/Controllers/AttendanceController.php`、`backend/app/Http/Controllers/ScheduleController.php`、`backend/app/Models/LearningRecord.php`、`frontend/src/components/course-management/SessionEditModal.vue`、`frontend/src/composables/course-management/useSessionEditFlow.js`、`frontend/src/pages/AttendancePage.vue`、`backend/database/migrations/2026_04_13_400000_merge_excused_into_leave.php` |
| **測試** | `tests/Feature/AttendanceExcusedLeaveCascadeTest.php`、`tests/Feature/LearningRecordApprovalDeductionTest.php` |

---

## 2026-04-13 — 增加購買堂數後第 N+1 堂起未自動產生

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 主任在課程編輯介面將「購買堂數」從 8 改成 10 後，`StudentClass.SessionCount` 正確更新為 10，但課程列表底下仍只顯示第 1～8 堂，沒有出現第 9、10 堂。 |
| **根本原因** | `StudentClassController::update` 在 `SessionCount` 異動時只呼叫了 `cancelExcessScheduledSessions`（縮減邏輯），**完全沒有處理增加的情況**。`maybeRebuildSessionsAfterUpdate` 僅在排課欄位（week/time）或 `StartDate` 改變時才觸發重建，單純改 `SessionCount` 不會進入任何補建分支。 |
| **正確行為** | 若 `SessionCount` 增加且 `ScheduleMode = 'count'`，必須從現有最後一堂的隔日起，按固定星期繼續往後補建缺少的 `ClassSession` 記錄，並同步 `UsedSessions` / `RemainingSessions`（`SessionDeductionService::syncCounters`）。 |
| **實作位置** | `StudentClassController::update`（在 `cancelExcessScheduledSessions` 之後呼叫 `extendSessionsIfNeeded`）+ 新增私有方法 `extendSessionsIfNeeded`。 |

### 禁止回歸

- **勿移除或繞過 `extendSessionsIfNeeded` 呼叫**：`cancelExcessScheduledSessions` 之後必須緊接呼叫，否則增堂時仍會靜默失效。
- **勿在 `extendSessionsIfNeeded` 中改用「從 `StartDate` 重建全部堂次」**：若前面的堂次已有出缺勤 / 評量記錄，整刪重建會連帶清掉歷史資料；應**只補差額**（`newCount - currentCount` 筆），從最後一堂隔日開始排。
- **`currentCount` 必須排除 `cancelled` 與 `leave`／`leave_adjusted`**（請假不佔用購買額度，與 `cancelExcessScheduledSessions` 口徑一致）；勿只算 `scheduled`，否則補建數量偏多。
- **補建堂次若日期已過去**，狀態應設 `completed`（非 `scheduled`），並自動建立 `Status=pending` 的 `LearningRecord`，與新建課程時的行為保持一致。

### 相關檔案

| 檔案 | 角色 |
|------|------|
| `backend/app/Http/Controllers/StudentClassController.php` | `update`（新增 `extendSessionsIfNeeded` 呼叫）、`extendSessionsIfNeeded`（新增私有方法）、`cancelExcessScheduledSessions` |
| `backend/app/Services/SessionDeductionService.php` | `syncCounters`（補建後重新計算 RemainingSessions / UsedSessions） |
| `frontend/src/components/CourseEditForm.vue` | 送出 `sessions_purchased` → 後端 `mapFrontendPayload` 映射至 `SessionCount` |

---

## 2026-04-13 — 當月學收月報（取代帳單列表）

| 項目 | 說明 |
|------|------|
| **背景** | 帳單列表（`BillingList.vue`）綁 `Invoice` 表，多數分校從未在系統開帳單故列表空。產品決定以「當月學收」取代帳單列表，直接顯示各學生每門課的月堂數 × 費率試算。 |
| **架構** | 後端 `FinanceController::branchMonthlyTuition`（`GET /api/v1/finance/branch-monthly-tuition`）；前端 `TuitionReportPage.vue`（`active = 'tuition-report'`）。 |
| **堂數口徑** | `ClassSession` 在指定月 + `Status in ('attended','completed','late')`，不含 `absent`/`excused`/`leave`/`cancelled`。 |
| **費率** | `StudentClass.Rate`；null/0 fallback `Charge / SessionCount`。 |
| **分校隔離** | 沿用 `getCampusIds()`（`auth_campus_ids` + `branch_id`），與其他 finance API 一致。 |

### 禁止回歸

- **勿把「帳單列表」（`active = 'billing'`、`BillingList.vue`）加回側欄**——已被產品決定由「當月學收」取代。`BillingList.vue` 檔案與 `BillingController` Invoice API 保留在程式中，但不掛載。
- **勿修改本 API 使其寫入** `StudentClass` / `ClassSession` / `LearningRecord` 等表——此 API 為**純讀取**報表。
- **堂數口徑勿擅自改**（例如改成只算 `attended` 或加入 `absent`）——除非產品明確要求。
- **費率 fallback 勿移除**——部分舊課程 `Rate` 為 null，仍需 `Charge / SessionCount` 作為備援。

### 相關檔案

| 檔案 | 角色 |
|------|------|
| `backend/app/Http/Controllers/FinanceController.php` | `branchMonthlyTuition` 方法 + `resolveRate` helper |
| `backend/routes/api.php` | director 區塊 `finance/branch-monthly-tuition` 路由 |
| `frontend/src/pages/TuitionReportPage.vue` | 當月學收頁面 |
| `frontend/src/App.vue` | 側欄 `tuition-report` 項目 + `v-if` 掛載 |
| `frontend/src/pages/BillingList.vue` | 保留但不再掛載（未來可重新啟用正式帳務） |

---

## 2026-04-13 — 課程 Stop 語意：`closed_reason` 區分暫停 vs 結算

| 項目 | 說明 |
|------|------|
| **背景** | `StudentClass.Stop = 1` 過去同時代表「手動暫停」和「堂數用完加購結算」。加購結算的課程顯示黃色大 banner「課程暫停中」，使用者反應視覺不適切。 |
| **方案** | 新增 `closed_reason` (nullable string 20) 欄位：`null` = 手動暫停（黃色 banner）、`'settled'` = 堂數用完結算（灰色小標「已結算」）、`'completed'` = 主任手動結案（灰色小標「已結案」）。**`Stop = 1` 語意不變**——所有 `where('Stop', 0)` / `where('Stop', 1)` 查詢不受影響。 |
| **後端寫入點** | `purchaseBatch` → `settled`；`togglePause(action=pause, reason=completed)` → `completed`；`togglePause(action=pause)` → `null`；`togglePause(action=resume)` → 清為 `null`。 |
| **前端判斷** | `c.closed_reason === 'settled'` or `'completed'` → `.course-settled` class + `tag-settled`；`c.status === 'inactive' && !c.closed_reason` → 現行 `.course-paused` + `tag-paused`。 |
| **禁止回歸** | **(a)** 勿移除 `closed_reason` 寫入——加購結算必須標 `settled`，否則使用者看到「暫停」黃色 banner。**(b)** 勿將 `closed_reason` 用來決定是否從 `AlertController::tuition` 列入（alert 只看 `Stop`）。**(c)** 恢復課程（resume）務必清 `closed_reason = null`。**(d)** 不要在 `where('Stop', 0)` 之外額外檢查 `closed_reason`，以免排除已結算課程的已有資料查詢被意外修改。 |

---

## 2026-04-13 — 催繳名單、tuition-slip 與 PaymentSlipModal

| 項目 | 說明 |
|------|------|
| **模組目的** | 主任在專頁檢視與 **`GET /api/v1/alerts/tuition`** 相同規則的「待聯繫／待繳」課程；**僅未繳費（`StudentClass.Paid != 1`）** 可產出傳家長用的圖片；**已繳**列可出現在名單（續課／月結將屆）但**不得**出「繳費單」按鈕或呼叫出圖 API。 |
| **名單資料源** | **`TuitionCollectionPage.vue`** 只應呼叫 **`GET /api/v1/alerts/tuition?branch_id=…`**，與 **`DirectorDashboard.vue`** 一致，避免兩處規則分叉。 |
| **兩種出圖路徑** | **(1)** 有帳單：**`GET /api/v1/invoices/{id}/slip-data`** + **`PaymentSlipModal` 的 `invoiceId`**（正式「繳費通知單」、含帳單編號）。**(2)** 無帳單：**`GET /api/v1/alerts/tuition-slip/{studentClassId}`** + **`studentClassId`**（**催繳通知**語意，抬頭／樣式與 Invoice 版區隔，**無**帳單編號）。 |
| **後端強制** | **`tuitionSlipData`**：`Paid === 1` → **422**；學生 **`CampusID`** 須在 **`auth_campus_ids`**（非 super_admin）；成功寫 **`[TuitionSlip] generated`** log。**`BillingController::slipData`** 成功寫 **`[InvoiceSlip] generated`**。 |
| **禁止回歸** | **(a)** 勿在催繳名單對 **`paid === true`** 顯示出圖按鈕或略過後端 Paid 檢查。**(b)** 勿把 **`tuition-slip`** 回傳格式偽裝成帳單（含假 `invoice_id`）。**(c)** 勿移除 **`tuition-slip`** 的校區檢查（避免以 ID 枚舉他校）。**(d)** 改 **`AlertController::tuition`** 的列入條件前必讀 **`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`** 並經產品同意。 |
| **關聯檔案** | `backend/app/Http/Controllers/AlertController.php`（`tuition`、`tuitionSlipData`）、`backend/routes/api.php`、`backend/app/Http/Controllers/BillingController.php`（`slipData`）、`frontend/src/pages/TuitionCollectionPage.vue`、`frontend/src/components/PaymentSlipModal.vue`、`frontend/src/pages/BillingList.vue`、`frontend/src/App.vue`（`tuition-collect`） |
| **搜尋用關鍵字** | tuition-collect、TuitionCollectionPage、tuition-slip、TuitionSlip、PaymentSlipModal、InvoiceSlip、催繳名單 |

### PaymentSlipModal 繪圖時序坑

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | `PaymentSlipModal` 的 `watch(show)` 中，`slip.value = …` 後立即 `await nextTick()` + `drawSlip(canvasRef)`，但此時 `loading` 仍為 `true`（`finally` 未執行），模板的 `v-else-if="slip"` 不渲染 canvas → `canvasRef.value` 為 null → 預覽與下載皆空白圖。 |
| **正確行為** | 成功取得資料後：先 `slip.value = …`，再 **`loading.value = false`**，然後 `await nextTick()`，最後 `drawSlip`。`catch` 分支同理須自行設 `loading = false`，不依賴 `finally`。 |
| **禁止回歸** | **(a)** 勿把 `loading = false` 移回 `finally`（會讓 canvas 在 draw 後才掛載）。**(b)** 勿把 `drawSlip` 提前到 `loading = false` 之前。 |

---

## 2026-04-13 — 老師管理須含授課學段（subject_level_scopes）

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 老師的「授課學段」（國小／國中／高中，依科目勾選）存在 **`teacher_subject_levels`**，由 **`TeacherScopeService`** 與 **`PUT /api/v1/me`**（`AuthController::updateMe`）維護；但 **`GET /api/v1/teachers`**／**`GET /api/v1/profiles`** 的列表與 **`PUT /api/v1/profiles/{id}`**（主任老師管理）長期**未帶出、未接受、未寫入** `subject_level_scopes`，且 **`TeachersList.vue`** 表單與列表**沒有學段 UI**，導致主任在「老師管理」看不到、改不到學段，與老師自行在帳號中心設定的資料脫節；排課／入班學段提示也失去可視性。 |
| **正確行為** | **`ProfileController::index`**：`buildTeacherExtras`（或等價路徑）須合併 **`TeacherScopeService::getScopesForTeachers`**，每位老師回傳 **`subject_level_scopes`**（`{ subject_id, level }[]`，`level` ∈ `elementary`/`junior`/`high`）。**`ProfileController::update`**：當請求帶有 **`subject_level_scopes`** 時須 **`TeacherScopeService::replaceScopes`**（與 `me` 一致）；**`getTeacherExtra`**／**`update` 回傳**須含 **`subject_level_scopes`**。**`ProfileController::store`**（主任新增老師）：可選接受 **`subject_level_scopes`** 並寫入。**`TeachersList.vue`**：編輯／新增 modal 須有與 **`TeacherProfilePage.vue`** 相同語意的**科目×學段**矩陣；列表（卡片／表格）須顯示學段摘要；儲存時 **`PUT`/`POST` `profiles`** 須送出 **`subject_level_scopes`**。 |
| **禁止回歸** | **(a)** 勿從老師列表 API 再移除 **`subject_level_scopes`**。**(b)** 勿讓主任 **`PUT /api/v1/profiles/{id}`** 只更新科目而不處理學段（若前端送學段，後端必須持久化）。**(c)** 勿在 **`TeachersList.vue`** 拿掉學段表單或僅顯示科目不顯示學段（與 **`TeacherScopeService`**／**`docs/AI_REGRESSION_LESSONS.md`（2026-04-11 學段提示）** 一條龍）。 |
| **關聯檔案** | `backend/app/Http/Controllers/ProfileController.php`（`index` 的 `buildTeacherExtras`、`getTeacherExtra`、`update`、`store`）、`backend/app/Services/TeacherScopeService.php`、`frontend/src/pages/TeachersList.vue`、`frontend/src/pages/TeacherProfilePage.vue`、`backend/app/Http/Controllers/AuthController.php`（`updateMe` 對照） |
| **搜尋用關鍵字** | subject_level_scopes、teacher_subject_levels、授課學段、TeachersList、profiles |

---

## 2026-04-12 — 專注模式與 modal z-index / 契約時段不得被覆寫

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | **(1)** `CourseManagement.vue` 的「專注模式」（`.focus-fullscreen-mode`）設 `z-index: 1000`，全域 `.modal-overlay`（`styles.css`）僅 `z-index: 200`，導致編輯／重建／加購等 modal 被蓋在專注層底下，使用者看不到。**(2)** 專注層 `overflow-y: auto` + 內層 `.group-table-wrap { max-height: 56vh }` 形成巢狀捲動，視窗利用率差。**(3)** `StudentClassController::index` 的 `$sessionSlotsByClassId`（ClassSession 推導）會**覆寫** `$class->day_time_slots`，導致列表與編輯表單的「固定排課日」不反映契約。**(4)** 前端 `editCourse` 從 `classSessionsByCourse` 合併推斷時段至 `existingSlots`，同樣污染固定排課。**(5)** `reconcileWeekTimeFieldsFromSessions` 在「沒有未來 scheduled 堂次」時 fallback 到 **completed/attended** 全歷史，把**週二代課**等一次性堂次的星期寫回 `StudentClass.week*`，使用者編輯移除週二後儲存仍被蓋回。 |
| **正確行為** | **(1)** `.modal-overlay` z-index 設為 1100，始終高於專注層。**(2)** `.focus-fullscreen-mode` 下子層 `.group-table-wrap`、`.table-wrap` 的 `max-height` 改為 `none`，避免雙重捲軸。**(3)** `index()` 的 `$sessionSlotsByClassId` 不覆寫 `day_time_slots`，**僅**用「今日起 `scheduled`」堂次組槽與契約比對 `schedule_drift`（**勿**併入 completed/attended，否則已結案課程會因週一代課歷史誤報偏移）。**(4)** `editCourse` 與 `formatDayTimeSlotLines` 只使用契約資料，不從堂次合併。**(5)** `reconcileWeekTimeFieldsFromSessions` **僅**依 `Status=scheduled` 且 `SessionDate >= today` 的堂次調整主檔；若無未來預排則直接 return，**不得**用歷史堂次覆寫契約。 |
| **禁止回歸** | **(a)** 勿把 `.modal-overlay` z-index 降回 200 或低於專注層 1000。**(b)** 勿在 `index()` 中讓 `$sessionSlotsByClassId` 覆寫 `$class->day_time_slots`（僅供 drift 偵測）。**(b2)** 勿把 `index()` 組 `$sessionSlotsByClassId` 時改回合併 completed/attended fallback（僅能自「今日起 scheduled」）。**(c)** 勿在前端 `editCourse` 或 `formatDayTimeSlotLines` 中用堂次資料覆寫契約 slots。**(d)** 勿把 `reconcileWeekTimeFieldsFromSessions` 改回「無未來堂時用 completed/attended 重建 week/time」。 |
| **關聯檔案** | `frontend/src/styles.css`（`.modal-overlay` z-index）、`frontend/src/pages/CourseManagement.vue`（`.focus-fullscreen-mode`、`editCourse`、`formatDayTimeSlotLines`、`schedule-drift-badge`）、`backend/app/Http/Controllers/StudentClassController.php`（`index()` `$sessionSlotsByClassId` → `schedule_drift`；`reconcileWeekTimeFieldsFromSessions`） |
| **測試** | `SameDayMultiSlotTest::test_index_day_time_slots_reflect_contract_not_sessions`、`SameDayMultiSlotTest::test_index_schedule_drift_detected_when_sessions_differ`、`StudentClassUpdateScheduleReconcileTest::test_update_removed_weekday_not_restored_from_history_when_no_future_scheduled`、`StudentClassUpdateScheduleReconcileTest::test_memo_only_update_keeps_contract_when_no_future_scheduled` |
| **搜尋用關鍵字** | 專注模式、z-index、modal、focus-fullscreen、契約、schedule_drift、day_time_slots 覆寫 |

---

## 2026-04-12 — 科目顯示、排課彈性、待補點名、開課日重建

### A. 科目名稱顯示

| 項目 | 說明 |
|------|------|
| **問題** | `LearningRecord.Subject` 歷史資料含英文；`StudentClassController` 將中文名反向 map 成英文 key；前端課表、評量列表、主任待審核區直接顯示英文。 |
| **正確做法** | `hydrateRecordForResponse()` 與 `index()` 批次版本透過 `SubjectID → Subject.Subject_Name` 解析，回傳 `student_class_label` 中文名。課表用 `ev.subjectName`（`sc.subject_name`）顯示，badge 用 `record.student_class_label`。科目下拉呼叫 `GET /api/v1/subjects`，不可寫死任何固定清單。 |
| **禁止回歸** | **(a)** 勿把 `student_class_label` 改回 `studentClass->Subject`（欄位不存在）。**(b)** 勿把科目下拉改回寫死陣列。**(c)** 勿用 `record.Subject` 直接顯示（可能是英文）。 |
| **關聯檔案** | `LearningRecordController.php`（`hydrateRecordForResponse`、`index`）、`LearningRecordsPage.vue`（`fetchSubjects`、`buildEvents`、badge）、`DirectorDashboard.vue`（待審核 tag）、`TeacherHomePage.vue`（`ev.subject` → 已更正） |

### B. 待補點名不應顯示已核准堂次

| 項目 | 說明 |
|------|------|
| **問題** | `ApprovalSessionSyncService` 核准評量時設 `ClassSession.Status = attended`，但不建 `StudentSignIn`；`endedSessions()` 只看 `SignIn`，導致已核准堂次仍列入待補點名。 |
| **修正** | `AttendanceController::endedSessions()` 加 `->whereNotIn('Status', ['attended', 'completed', 'late'])`。 |
| **禁止回歸** | 勿移除此 `whereNotIn` 條件。 |
| **關聯檔案** | `AttendanceController.php`（`endedSessions`）、`ApprovalSessionSyncService.php` |

### C. 開課日編輯後堂次不同步

| 項目 | 說明 |
|------|------|
| **問題** | 課程有歷史記錄時，修改開課日後系統靜默不重建堂次，前端僅顯示小字提示，造成主檔與堂次資料不一致。 |
| **正確行為** | `hasImmutableSessionHistory()` 排除已作廢（`VoidedAt IS NOT NULL`）的 `StudentSignIn` 與 `LearningRecord`。開課日有變且有歷史時走「安全部分重建」（`reason: partial_rebuild`）：鎖定已點名／已核准堂次，重排未鎖定未來堂次。主任可從操作選單「重建未上堂次」強制觸發（`force_partial_rebuild: true`）。 |
| **退回未上解法** | `attended → scheduled` 會 void SignIn＋LR，再編輯開課日即可觸發全量重建，無需刪除重建。 |
| **禁止回歸** | **(a)** 勿把 `hasImmutableSessionHistory()` 的 StudentSignIn 查詢改回不過濾 VoidedAt。**(b)** 勿移除 `partial_rebuild` 路徑。**(c)** 勿把 `CourseManagement.vue` 的 `@click.self` 加回 modal-overlay（會讓使用者誤觸關閉）。 |
| **關聯檔案** | `StudentClassController.php`（`hasImmutableSessionHistory`、`maybeRebuildSessionsAfterUpdate`、`update` 的 `force_partial_rebuild`）、`CourseManagement.vue`（`originalFirstClassDate`、`openRebuildModal`、`submitForceRebuild`、`.rebuild-modal`） |

### D. 手動排課日期不限固定星期

| 項目 | 說明 |
|------|------|
| **問題** | `UniversalClassScheduler` 前端驗證與後端 `EnrollmentService` 均要求手動日期必須在固定上課星期，導致補登歷史課程被阻擋。 |
| **正確行為** | **過去日期**（`< today`）不限固定星期，可自由選擇任意日期；**今天（含）之後**的日期仍須符合固定上課星期。`sessionCountForYmd` 對不在固定星期的手動日回傳 1（不回傳 0）；送出時找不到 slot 改用全域 `start_time` fallback。 |
| **禁止回歸** | **(a)** 勿把 `onDateClick` 的 `cell.ymd >= todayYmd` 條件移除（會讓未來也不限星期）。**(b)** 勿把 `sessionCountForYmd` 改回對非固定星期回傳 0。**(c)** 勿把後端 `EnrollmentService` 的 `$today` 跳過邏輯移除。 |
| **關聯檔案** | `UniversalClassScheduler.vue`（`onDateClick`、`sessionCountForYmd`、送出邏輯、hint text）、`EnrollmentService.php`（星期驗證迴圈） |

---

## 2026-04-12 — 老師教學工作台（TeacherHome）

| 項目 | 說明 |
|------|------|
| **產品行為** | 老師（`role=teacher`）登入後預設 **`active=teacher-home`**（**教學工作台**）：今日待辦 CTA、**本週課表為所屬全部分校合併**（每筆標分校）、科目數／行事曆捷徑。側欄「出缺勤」可顯示**今日待點名數紅點**（不依主任 `notifications/unread-count`）。 |
| **曾易犯的設計錯誤** | **(1)** 週課表只查 `currentBranch`，與「跨分校一覽」規格矛盾。**(2)** 從他校堂次開評量卻不切 `localStorage.app_branch`／`currentBranch`，導致評量 API 仍打錯校。**(3)** 改 `refreshUnreadNotifications` 時刪掉或漏呼叫 **`mergeTeacherAttendanceBadge`**，老師側欄紅點永遠沒有。**(4)** 把老師預設頁改回 `learning` 卻未同步文件／導覽，現場又忘記點名。 |
| **正確行為** | **週課表**：對 `teacherBranches`（或 `App.vue` 傳入的 `teacherBranchIds`）每個 `branch_id` 並行 `fetchClassSessions`，合併去重、排序；單校失敗不白屏。**跨校填評量**：寫入 `app_branch` 再 `setActivePage('learning')`，必要時帶 `learningTargetRecordId`。**紅點**：`refreshUnreadNotifications` 結尾須保留 `await mergeTeacherAttendanceBadge()`（老師專用，與主任 `badgeByType` 來源分離）。**上線**：凡動 `frontend/src/**`，整輪 **`npm run deploy`**。 |
| **禁止回歸** | **(a)** 勿把老師預設 `active` 改回僅 `learning` 而未經產品確認。**(b)** 勿移除工作台跨校合併或改回僅單校卻不更新 UI 文案。**(c)** 勿讓老師 badge 依賴主任專用 unread API（權限／格式不同）。**(d)** 勿只複製 `assets` 或讓 `index.html` 與 chunk 脫鉤。 |
| **關聯檔案** | `frontend/src/pages/TeacherHomePage.vue`、`frontend/src/App.vue`（`teacher-home` 掛載、`sidebarNavGroups`／`mobileTabItems`、`mergeTeacherAttendanceBadge`、`handleLoginSuccess`／`fetchProfile` 預設頁）、`frontend/src/lib/classSessionsApi.js`（`fetchClassSessions`）、`frontend/src/pages/LearningRecordsPage.vue`（老師 RWD 與本頁「本週」widget 若與工作台不一致須標示或對齊） |
| **文件** | `docs/CHANGELOG.md` **2026-04-12 (G)**、`docs/FAQ.md`（老師登入預設）、`docs/OPERATIONS_RUNBOOK.md` §A 第 6 點 |
| **搜尋用關鍵字** | `teacher-home`、`TeacherHomePage`、`mergeTeacherAttendanceBadge`、`teacherBranchIds` |

---

## 2026-04-12 — 固定排課契約與堂次一致（手動日、列表幽靈時段、改星期未同步）

**營運情境（濃縮）**：(1) 固定星期只有週六日，手動卻點到週三仍建成堂次。(2) 主檔已只剩週六，列表仍多一條週日（孤兒預排覆寫顯示）。(3) 改成僅週日後，摘要變週日但底下未來堂仍停在週六。

### 技術摘要表

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | **(A)** `UniversalClassScheduler.vue` 組 `session_plan` 時，若使用者點選的日曆日之星期幾**未**在 `day_time_slots`／`days_of_week` 內，`getSlotIndicesForDay` 回傳空陣列，程式卻走 **fallback**：仍用該**錯誤日曆日** + 全域 `start_time` 送後端 → 出現「固定排課寫週六日、第 1 堂卻在週三」等與現場固定排課矛盾的堂次。**(B)** 學生課程列表 `GET /api/v1/student-classes` 曾用「未來 `ClassSession` 推導的星期」**整段覆寫** `day_time_slots`，若主檔已改為僅週六、但庫裡仍留週日預排，畫面會多顯示一個週日時段。 |
| **正確行為** | **(A)** 手動勾選的日期必須落在已勾選的固定上課星期；`EnrollmentService::store` 驗證堂次日曆星期。**(B)** `StudentClassController::index`：課程主檔 `week*`／`time*` 組出的時段為**契約**；用預排堂次覆寫顯示時，須**過濾掉契約中沒有的星期幾**，避免孤兒預排多出幽靈時段。**(C)** `PUT /api/v1/student-classes` 有歷史堂次時，`syncFutureScheduledSessionTimes` 僅「同星期改時間」不足；若未來 `scheduled` 堂仍落在**已從契約移除的星期**（例：改為僅週日後仍留週六預排），須依 `buildSessionsForCount` 節奏**重算 SessionDate** 並同步未作廢 `LearningRecord` 的日期／時間。 |
| **禁止回歸** | **(a)** 勿恢復 `session_plan` 的錯誤日曆日 fallback。**(b)** 勿移除 `EnrollmentService` 星期驗證。**(c)** 勿把 `index` 的 session 覆寫改回「不經契約星期過濾」整包取代。**(d)** 勿把 `syncFutureScheduledSessionTimes` 改回「只改 Start/End、遇到契約外星期就略過」而讓未來堂永遠卡在舊星期。 |
| **關聯檔案** | **前端**：`frontend/src/components/UniversalClassScheduler.vue`（`onDateClick`、`sessionCountForWeekday`、`submit`）。**後端**：`backend/app/Services/EnrollmentService.php`（`store` 堂次日曆星期驗證）；`backend/app/Http/Controllers/StudentClassController.php`（`index` 契約過濾 session 覆寫；`syncFutureScheduledSessionTimes`／`remapFutureScheduledSessionsToContract`／`buildSlotsByWeekdayMap`／`snapDateToContractWeekday`） |
| **測試** | `ClassSessionBatchApiTest::test_batch_rejects_session_plan_on_weekday_outside_fixed_schedule`、`SameDayMultiSlotTest::test_index_day_time_slots_ignore_future_sessions_outside_contract_weekdays`、`StudentClassUpdateScheduleReconcileTest::test_update_weekday_remaps_future_sessions_from_saturday_to_sunday` |
| **搜尋用關鍵字** | 幽靈星期、契約、`session_plan`、週三誤排、`day_time_slots`、`syncFutureScheduledSessionTimes` |

### 2026-04-16 補充：新增星期未觸發 remap

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 使用者在課程管理把固定排課從「一、二」改成「一、二、四」，`syncFutureScheduledSessionTimes` 的 `$needsRemap` 只偵測「現有堂次在契約外星期」→ 一、二都在新契約內 → 不觸發 remap。結果：契約主檔存了「一二四」，但未來堂次全留在一、二，新星期四無堂次。此外 `force_partial_rebuild` 路徑無條件呼叫 `reconcileWeekTimeFieldsFromSessions`，sync 回傳 0 筆時仍執行 reconcile → 用舊 ClassSession 的一、二回寫覆蓋契約，導致使用者看到「改了沒生效」。 |
| **正確行為** | `$needsRemap` 必須**雙向偵測**：(1) 堂次在契約外星期 → remap；(2) 契約有但堂次沒有的星期（新增星期）→ remap。`force_partial_rebuild` 路徑的 reconcile 必須有 `updatedCount > 0` 守衛，與主路徑 `$skipReconcile` 一致。 |
| **禁止回歸** | **(a)** 勿把 `$needsRemap` 改回只看「堂次→契約」單方向。**(b)** 勿移除 `force_partial_rebuild` reconcile 的 `updatedCount > 0` 守衛。**(c)** 前端成功訊息須加總兩次 PUT 的 sync 計數，勿只取第二次。 |
| **測試** | `StudentClassUpdateScheduleReconcileTest::test_adding_weekday_remaps_future_sessions_to_new_cadence`、`test_force_partial_rebuild_preserves_contract_when_zero_synced` |
| **搜尋用關鍵字** | `$needsRemap`、`$sessionWeekdays`、`force_partial_rebuild`、`reconcile_skipped`、新增星期、remap |

---

## 2026-04-12 — 調課失敗後孤兒 `rescheduled` 紀錄導致課堂消失

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 智慧排課的調課流程分**兩步**寫入 `schedules`：(1) 把原堂次 insert 為 `status=rescheduled`（標記原日消失）；(2) insert 新堂次 `status=scheduled`（新日出現）。前端 `supabase.js` 的 `insert([row])` 會將 body 序列化為 **JSON 陣列** `[{...}]`，而 `ScheduleController::store` 的 `$request->validate()` 預期**根層物件**，導致第二步 422 失敗。結果：原堂被標 `rescheduled`（行事曆不顯示），新堂未建立 → **課堂憑空消失**。此 bug 造成吳艾潼 4/12 理化課兩度消失。 |
| **修復** | **(A) 前端**：`supabase.js` POST 分支在序列化前，若 `_body` 為「僅含一筆 plain object 的陣列」，unwrap 為該物件再 `JSON.stringify`。**(B) 後端防禦**：`ScheduleController::store` 開頭偵測若 `$request->all()` 根是 `[0 => [...]]` 單元素數值陣列，先 `$request->replace($all[0])` 再 validate。兩層保險確保新舊前端皆可正確寫入。 |
| **禁止回歸** | **(a)** 勿把 `supabase.js` POST 的 body 改回直接 `JSON.stringify(this._body)` 不做 unwrap。**(b)** 勿移除 `ScheduleController::store` 開頭的陣列 unwrap 防禦。**(c)** 調課前端流程若第一步（寫 `rescheduled`）成功但第二步（寫 `scheduled`）失敗，應回滾第一步或提示使用者；目前靠雙層 unwrap 避免第二步失敗，但未來若重構調課流程須注意此原子性問題。 |
| **關聯檔案** | `frontend/src/supabase.js`（POST unwrap）、`backend/app/Http/Controllers/ScheduleController.php`（store 防禦性 unwrap）、`frontend/src/pages/SmartCalendar.vue`（`submitReschedule` 兩步寫入）、`frontend/src/composables/course-management/useRescheduleAndMakeup.js`（同路徑） |

---

## 2026-04-11 — 「手動補登日期」汙染課程時段顯示（三處同性質缺口）

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤（三層）** | **(1) `reconcileWeekTimeFieldsFromSessions()`**：編輯課程固定時間後，DB 欄位先被正確存入，卻馬上被 `reconcileWeekTimeFieldsFromSessions` 用**舊 `completed/attended` 堂次（手動補登的過去日）**覆寫回去，導致課程卡片時段永遠不更新。**(2) `StudentClassController::index()` → `$sessionSlotsByClassId`**：課程列表 API 撈 `['scheduled','completed','attended']` 全部堂次建立 `day_time_slots`，再用它蓋掉 DB 的 `week/time` 欄位；使用者只在「固定上課星期」設了週日，但手動補登了週五、週六兩天，結果課程管理顯示三個時段（週五、週六、週日），多出兩個無中生有。**(3) `ensurePastRecords()` EndTime 條件**：評量表的自動建立條件用 `EndTime`（下課時間）而非 `StartTime`（上課時間），導致老師在課程開始後、下課前開不了評量表填寫。 |
| **正確行為（2026-04-12 修訂）** | **原則：課程管理列表與編輯的「時段」以 `StudentClass` 契約（DB `week`/`time`/`week1..`/`time1..`）為唯一準，不由 `ClassSession` 覆寫。** `index()` 的 `$sessionSlotsByClassId` 仍查未來 scheduled 堂，但**僅用於計算 `schedule_drift` boolean**（前端顯示「堂次偏移」警告），不再覆寫 `day_time_slots`。前端 `editCourse` 與 `formatDayTimeSlotLines` 同樣不再從堂次合併推斷。智慧排課（`SmartCalendar`）每格仍優先該日 `ClassSession`（見下方「智慧排課」節），語意不同、不衝突。**(1)** `reconcileWeekTimeFieldsFromSessions`：先查 `Status='scheduled' AND SessionDate >= today`；若不空只用這些重建 week/time；否則 fallback。**(3)** `ensurePastRecords`：條件改用 `StartTime`。 |
| **禁止回歸** | **(a)** 勿把 `reconcileWeekTimeFieldsFromSessions` 的 Session 查詢改回只查全部狀態。**(b)** 勿把 `index()` 的 `$sessionSlotsByClassId` 改回**覆寫** `day_time_slots`（應僅用於 `schedule_drift` 偵測）。**(b2)** 勿在前端 `editCourse` 或 `formatDayTimeSlotLines` 中用堂次推斷覆寫契約 slots。**(c)** 勿把 `ensurePastRecords` 改回 `EndTime`。**(d)** 出缺勤 `index()` 必須保留 `->whereNull('si.VoidedAt')`。 |
| **出缺勤補請假（retro-leave）** | 出缺勤頁面對**已到班（present/late）**記錄改請假，前端須呼叫 `POST /api/v1/schedules/retro-leave`（帶 `student_course_id` + `session_date`），**不可**繼續呼叫 `leave-by-session`（後者對 attended 狀態直接拒絕）。`retro-leave` 會作廢 StudentSignIn + LearningRecord 並沖回堂數。 |
| **關聯檔案** | `StudentClassController.php`（`reconcileWeekTimeFieldsFromSessions`、`index()` 的 `$sessionSlotsByClassId`）、`LearningRecordController.php`（`ensurePastRecords`）、`AttendanceController.php`（`index()` `whereNull('si.VoidedAt')`）、`AttendancePage.vue`（retro-leave 分支） |

---

## 2026-04-12 — 請假與學習評量：作廢列、孤兒 pending、`ensure-past`（改動前必讀）

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤（兩層）** | **(1)** 請假 cascade 只把評量標 `VoidedAt`（`Status` 仍可能是 `pending`），但 **`GET /api/v1/learning-records` 未過濾作廢列** → 主任總覽「待審核評量」仍顯示已請假堂次。**(2)** 僅補 `->active()` **仍不足**：`ensurePastRecords` 曾在 `ClassSession` 已是 **`leave`/`excused`/`leave_adjusted`** 時仍建立 **未作廢** 的 `pending` 評量（`VoidedAt` 為 null）→ 畫面上與「請假後不應有評量」矛盾；DB 上 `learningrecord_classsessionid_unique` 也禁止在已有作廢列時再 insert。**(3)** 出缺勤 **`excused` 且無 `ClassSessionID`** 時不跑 cascade，堂次可能長期停在 `excused`，與「請假＝leave」路徑不一致（見計畫／`AttendanceController::store`）。 |
| **正確行為** | **列表與待辦**：`LearningRecord::active()`（排除 `VoidedAt`）**加上** `LearningRecord::excludeLeaveSessionPendingReview()`：對 `pending`/`changes_requested`，若關聯 **`ClassSession.Status` ∈ `leave`,`excused`,`leave_adjusted`** 則不列出（與 `ApprovalSessionSyncService` 不扣堂語意對齊）。**`ensurePastRecords`**：上述狀態的堂次**不補建**評量；若該 `ClassSessionID` **已有任一筆**評量（含作廢），**不得**再 `create`（避免 unique 衝突；作廢列只做 sync 或略過）。**批次核准**：與 index 同一套篩選，避免一鍵核准誤核請假堂。**通知**：`NotificationSyncService::buildLearningNotifications` 同樣套用 `excludeLeaveSessionPendingReview`。**既有孤兒**：營運庫內曾出現「`pending` + `VoidedAt` null + `cs.Status=leave`」者應 **void** 或依產品決策刪除（一次性修復可寫 migration 或 runbook SQL）。 |
| **禁止回歸** | **(a)** 勿只依 `VoidedAt` 過濾待審列表而忽略「堂次已請假但評量未作廢」的孤兒。**(b)** 勿把 `ensurePastRecords` 的 `ClassSession` 查詢改回只排除 `cancelled`。**(c)** 勿在 `where('ClassSessionID')->first()` 找「是否已有評量」時忽略作廢列與 unique 約束（應區分：有 active → 不重建；僅 voided → 不 insert）。**(d)** 修改請假／評量／財務讀取路徑時，勿移除 `FinanceController`／`ParentPortal` 等處的 `->active()`。 |
| **營運／稽核 SQL（建議上線後偶跑）** | 孤兒 A：`LearningRecord lr JOIN ClassSession cs ON cs.id=lr.ClassSessionID WHERE lr.Status IN ('pending','changes_requested') AND lr.VoidedAt IS NULL AND cs.Status IN ('leave','excused','leave_adjusted')` → 應為 **0**。孤兒 B：pending + `ClassSession` 為 `cancelled` → 應為 **0**。 |
| **關聯檔案** | `LearningRecord.php`（`scopeActive`、`scopeExcludeLeaveSessionPendingReview`）、`LearningRecordController.php`（`index`、`ensurePastRecords`、`batchApprove` 等）、`NotificationSyncService.php`、`CourseLeaveCascadeService.php`、`ApprovalSessionSyncService.php`（skip 狀態對照） |
| **測試** | `LearningRecordApprovalDeductionTest.php`（含 `ensure-past` 跳過 leave、作廢不重建、作廢不進 index／batch） |

---

## 2026-04-12 — 出缺勤「科目」欄、待點名科目、舊 Subject 主鍵、Subject 中文化

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | **(1)** `AttendanceController::index` 只 join `Subject ON sc.SubjectID`，主檔空或 id 在 `Subject` 表已不存在時，**簽到上的 `si.SubjectID` 無法回填**，API 的 `subject_name` 為 null → 前端顯示「—」。**(2)** `ClassSessionController::index`（供待點名用的 `GET /api/v1/class-sessions`）**完全沒有 join Subject**，回傳無 `subject_name` → 前端待點名表格科目欄全部顯示「—」。**(3)** 舊庫殘留 Subject id（1、14、15、21）與重建後字典表 id（64-71）不一致，JOIN 失敗。**(4)** `Subject.Subject_Name` 存英文（Chinese、English…），台灣補教業使用者無法一眼辨識。 |
| **正確行為** | `AttendanceController::index` 主查詢 **leftJoin `Subject as sub_sc`（主檔）與 `sub_si`（簽到）**，`subject_name = COALESCE(sub_sc.Subject_Name, sub_si.Subject_Name)`（**主檔優先**）。`ClassSessionController::index` 須 **leftJoin Subject on sc.SubjectID** 並 select `subject_name`。`Subject.Subject_Name` 儲存中文（國文、英文、數學、物理、化學、理化、社會、生物）。部署後執行 migration **`2026_04_12_200000_remap_orphaned_subject_ids`** 修正歷史 id。 |
| **禁止回歸** | **(a)** 勿改回「只依 `sc.SubjectID` 單一 join」而忽略 `si.SubjectID`。**(b)** 勿移除 `ClassSessionController::index` 的 Subject join（否則待點名科目又空白）。**(c)** 勿把 `Subject.Subject_Name` 改回英文；前端 `constants.js` 的 `SUBJECT_NAME_MAP` 已支援雙向，但使用者期望直接看到中文。**(d)** 新增科目時 `Subject_Name` 須用中文。**(e)** 勿在出缺勤請假路徑繞過 `CourseLeaveCascadeService`。 |
| **關聯檔案** | `AttendanceController.php`（`index`、`store` excused 分支）、`ClassSessionController.php`（`index`）、`ScheduleController.php`、`CourseLeaveCascadeService.php`、`backend/database/migrations/2026_04_12_200000_remap_orphaned_subject_ids.php`、`Subject` 表（中文名）、`frontend/src/lib/constants.js`（`SUBJECT_NAME_MAP`） |
| **測試** | `AttendanceSubjectNameResolutionTest.php`、`AttendanceExcusedLeaveCascadeTest.php` |

---

## 2026-04-11 — 主任「繳費／續課提醒」：`AlertController::tuition`（變更前必問使用者）

| 項目 | 說明 |
|------|------|
| **產品規則（摘要）** | 見 **`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`**。堂數制：`Stop=0` 且（`Paid!=1` **或** `RemainingSessions<=2` **含 0**）→ 已繳低堂數仍會出現（`alert_type`：`low_sessions`）。月結制：須 `settlement_day`；提醒窗口為距**本次計算之繳費日** **0～4 天**（小於 5 天）；未繳且**已過**當月繳費日則逾期期間**一律**提醒。 |
| **禁止擅自** | 改成「僅未繳才列出」、只查堂數制漏月結、`remaining>0 && <=2` 漏 0 堂、或任意放寬／收緊天數門檻而不經產品確認。 |
| **改動前必做** | **先取得使用者（產品）明示同意**；更新 **`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`**；跑過／補齊 **`TuitionAlertsApiTest`**、**`LargeBranchDataHandlingTest`** 等相關測試。 |
| **關聯檔案** | `backend/app/Http/Controllers/AlertController.php`、`frontend/src/pages/DirectorDashboard.vue`、`backend/tests/Feature/TuitionAlertsApiTest.php`、`backend/tests/Feature/LargeBranchDataHandlingTest.php` |

---

## 2026-04-11 — 主任總覽「待審核評量」：`only_due=1` 造成核准一筆後其餘「整批消失」

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | `DirectorDashboard.vue` 載入待審評量時呼叫 `GET /api/v1/learning-records?...&only_due=1`。`only_due` 只回傳「`SessionDate` + `EndTime`（無則 23:59）≤ 現在」的筆數。主任在總覽核准**一筆已過下課時間**的待審後，`loadData()` 重抓清單；若佇列裡其餘待審多為**同一天但尚未到下課**的堂次，會全部被 `only_due` 濾掉 → 畫面變成「無待審核評量」。使用者重新整理後（時間已過下課或與快取無關的完整重載）又看到待審，誤以為資料遺失。 |
| **正確行為** | **主任總覽**應列出分校內所有需審核的評量（`pending` + `changes_requested`），**勿**對總覽卡片套用 `only_due=1`（該參數僅適合「只想看已下課可審」的**明確子功能**，且須產品同意後才可加開關）。核准後可對該筆做樂觀移除並再 `loadData()`，避免重載前短暫空白。 |
| **關聯檔案** | `frontend/src/pages/DirectorDashboard.vue`（`loadData` 內 `learning-records` 查詢）、`backend/app/Http/Controllers/LearningRecordController.php`（`only_due` 參數語意） |
| **禁止回歸** | 勿在主任總覽待審卡片上**默默**加回 `only_due=1` 或僅查 `status=pending` 而漏掉 `changes_requested`；若需「只顯示已下課」請另做**可切換的篩選**並寫入本檔與 CHANGELOG。 |

---

## 2026-04-11 — ⚠️ 核准評量 = 點名核課（架構級變更，改動前必問使用者）

| 項目 | 說明 |
|------|------|
| **架構決策** | 2026-04-11 起，**核准評量（LR approved）等同點名**：`ApprovalSessionSyncService::syncOnApprove` 會建立 `StudentSignIn(Memo=lr_approve)`、更新 `ClassSession.Status=attended`、呼叫 `deductOnAttendance`。rollback 對稱沖回。**此為產品方明確要求的重大架構變更**。 |
| **禁止回退此行為** | 任何 AI 或工程師**不得**將核准評量改回「不扣堂」、不得移除 `syncOnApprove` 呼叫、不得在 `approve/batchApprove/rollbackApproval` 內繞過 `ApprovalSessionSyncService`。如有疑慮，**必須先詢問使用者**後才可改動。 |
| **關鍵守衛規則** | leave/cancelled 跳過、未來堂次不預扣、已有扣堂 SignIn 則冪等跳過（不重複扣）、rollback 只 void `Memo='lr_approve'` 型 SignIn（不影響獨立點名） |
| **月結制** | `RemainingSessions` 恆 0，`UsedSessions` 透過 `recomputeCounters` 累加 |
| **改動前必讀** | `docs/OPERATIONS_RUNBOOK.md` §K（強制口徑）、`docs/CHANGELOG.md`（2026-04-11 B）、本檔本節 |
| **關聯檔案（改動前必問使用者）** | `ApprovalSessionSyncService.php`、`SessionDeductionService.php`、`LearningRecordController.php`（approve / batchApprove / rollbackApproval）、`AttendanceController.php`、`LearningRecordApprovalDeductionTest.php` |
| **測試** | `./vendor/bin/phpunit --filter=LearningRecordApprovalDeductionTest`（17 tests, 95 assertions，必須全綠） |

---

## 2026-04-11 — 前端上線：`index.html` 與 Vite hashed chunk 不同步（整站無法載入，嚴重）

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | `backend/public/index.html` 仍引用**舊 build** 的 `./assets/index-*.js`（Vite 產生的 hash 檔名），但 `backend/public/assets/` 內已是**另一輪** build 的檔名（或曾**只覆寫部分** `assets`、未一併更新 `index.html`）。瀏覽器請求不存在的 `.js` 時，Laravel SPA fallback（`routes/web.php` 的 `/{path?}`，`^(?!api)`）改回傳**同一個** `index.html`，`Content-Type` 為 **`text/html`**。ES module 載入器預期 JavaScript → 主控台出現 **`Failed to load module script... MIME type of "text/html"`**，**整個後台白屏／無法使用**。 |
| **正確行為** | 每次要上線前端變更，一律在 repo 內執行 **`cd frontend && npm run deploy`**（`vite build` + `node scripts/copy-to-backend.cjs`），讓 **`index.html` 與整個 `assets/` 目錄同一輪、一併覆寫**（copy 腳本會清空後再拷貝 `assets`）。**禁止**只手動複製部分 chunk、或只更新 `assets` 忘記 `index.html`、或讓邊緣快取長期持有**舊** `index.html` 卻打到**新**檔名的路徑。部署後**抽查**：`index.html` 裡 `<script type="module" ... src="./assets/index-….js">` 的檔名，**必須**實際存在於 `backend/public/assets/`。 |
| **2026-04-12 補強（防靜默不同步）** | `copy-to-backend.cjs` 對 `index.html` 改為 **`readFileSync` + `writeFileSync` 整份覆寫**（避免少數環境 `cpSync` 未真正更新目標檔）。拷貝結束後執行 **`verifyIndexHtmlReferencesAssets()`**：若 `index.html` 內任一 `./assets/…` 檔在 `backend/public/assets/` 不存在，腳本 **throw → process exit 1**，禁止留下「舊 index 引用舊 hash + assets 已是新一輪」的組合。若仍見 MIME 錯誤，請在伺服器上 **`head backend/public/index.html`** 與 **`ls backend/public/assets/index-*.js`** 交叉比對。 |
| **關聯檔案** | `frontend/scripts/copy-to-backend.cjs`、`frontend/vite.config.js`（`base: './'`）、`backend/public/index.html`、`backend/routes/web.php`；Cursor 規則 **`.cursor/rules/auto-frontend-deploy.mdc`**（改 `frontend/src` 等後須 deploy）。 |

---

## 2026-04-11 — 手動「過去日期」必須維持「已上完」語意

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | AI 為處理「隔天建課、首堂在昨天」誤扣堂，將 **過去手動日改為預排**、並放寬 `EnrollmentService` 對 `future_dates` 的驗證，**違反營運既定邏輯**。 |
| **正確行為** | 使用者在月曆**手動點選今天以前**的日期＝**已上完／補登**（進 `confirmed_dates`、後端 `completed`＋扣堂流程）。**不得**在未經產品同意的情況下改為「錨點預排」。目前產品**僅**透過 **`UniversalClassScheduler.vue`**（排課 modal）操作；**前端正向入口已無「新生入班精靈」**（舊元件已自 repo 移除）。 |
| **關聯檔案** | `docs/MANUAL_SCHEDULE_DATE_SEMANTICS.md`、`UniversalClassScheduler.vue`、`EnrollmentService.php` |

---

## 2026-04-11 — 新建課程「學段／科目」提示：前後端與 Vue ref

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | **(1)** `UniversalClassScheduler.vue` 的 `scopeWarning` 把 **`subjectOptions` ref 物件**傳給 `checkTeacherScope`，未傳 **`subjectOptions.value`**，在 `<script setup>` 內不會自動 unwrap，導致比對邏輯對不到陣列、**畫面學段黃條形同失效**（載入 API 科目後尤其嚴重）。**(2)**（歷史、精靈已移除）舊版前端正向「入班精靈」元件曾用**寫死科目、無 `Subject.id`**，導致 `checkTeacherScope` 與 `POST /api/v1/enrollments` 後端語意不一致。**(3)** 前端只比對「選到的那一筆科目的單一 `id`」；`Subject` 表內**同名科目多筆 id**（歷史／分校資料）與老師授課設定裡的 `subject_id` 不一致時，出現**假陽性**：「老師設定沒有數學」其實有。後端已用 `TeacherScopeService::resolveEquivalentSubjectIds` 處理等價 id。 |
| **正確行為** | 所有**目前產品內**「新建課程」入口（學生管理、課程管理、智慧排課之 **`UniversalClassScheduler`**，以及 **`CourseEditForm`** 等）的**事前**學段提示，應與後端同一套語意：**同名科目多 id 一併納入比對**；傳入 `checkTeacherScope` 的科目列表必須是**陣列**（`ref` 請 `.value`）；科目選項須含 **`id`**（例如 `fetchSubjectOptions()`）。成功建立後仍應保留 **`class-sessions/batch`** 回傳的 **`scope_warning`**（alert）。後端 **`POST /api/v1/enrollments`** 仍存在（測試／整合）；若日後重做精靈 UI，須符合上列並與 `EnrollmentService` 一致。 |
| **關聯檔案** | `frontend/src/lib/constants.js`（`checkTeacherScope`）、`frontend/src/components/UniversalClassScheduler.vue`、`frontend/src/components/CourseEditForm.vue`、`frontend/src/lib/subjectsApi.js`、`backend/app/Services/TeacherScopeService.php`、`backend/app/Services/EnrollmentService.php` |

---

## 2026-04-11 — 智慧排課：同一門課「不同週幾、不同時段」不得只複製 `start_time`

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 學生同一 `StudentClass` 登記週二 17:00～19:00 與週六 10:00～12:00；**點名／出缺勤**依 **該日 `ClassSession`** 顯示正確，但 **智慧排課課表圖**在週六仍把區塊畫在 17:00～19:00。根因：`GET /api/v1/student-classes` 將 `start_time`／`end_time` 設為 **`day_time_slots` 排序後第一筆**（常為週序較前的那一天）；`SmartCalendar.vue` 的 `filteredCourses` 在依 **堂次日期集合**（`ClassSession` 載入的 `sessionDatesByCourseId`）展開格子時，只複製課程主檔時段，**未依該日 session 或星期幾覆寫時段**。 |
| **正確行為** | 課表格上每一格顯示的 **開始／結束／時長** 須與 **該日實際堂次**一致：優先該日 `ClassSession`（與點名、課程管理一致）；若無則用後端 **`day_time_slots` 對應 `dow`**；最後才退回主檔 `start_time`。勿假設「一門課全週同一 `start_time`」。 |
| **關聯檔案** | `frontend/src/pages/SmartCalendar.vue`（`resolveCourseGridTimes`、`filteredCourses` 合併）、`frontend/src/lib/classSessionsApi.js`、`backend/app/Http/Controllers/StudentClassController.php`（`day_time_slots`、主檔 `start_time` 語意） |

---

## 使用方式

1. 實作或重構觸及下方「關聯檔案」時，逐項確認行為是否仍符合「正確行為」。
2. 若引入新的高風險 regression，於本檔**以日期新增一節**（簡短：缺口 → 正確行為 → 關聯檔案／測試）。

---

## 2026-04-11 — 聊天頭像、Bug 附件／權限／紅點

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 頭像存成含 `APP_URL` 的完整 URL，區網開網頁時聊天／側欄破圖；Bug 主任誤以為能看全校；指派與狀態權限混在 `director` 路由。 |
| **正確行為** | 詳見 **`docs/AI_HANDOFF_CHAT_BUG_AVATAR.md`**：`PublicAvatarUrl`、只存 disk 路徑、主任／老師僅自己的 bug、僅 super_admin 狀態／mark-inbox、無指派、未讀紅點規則與路由順序。 |
| **關聯測試** | `ChatApiTest.php`、`BugReportApiTest.php`、`ProfileCenterApiTest.php`（頭像相關） |

---

## 2026-04-10 — 暫停課程、評量待審、繳費提醒、課程列表 UI

### A. 暫停課程（`StudentClass.Stop = 1`）仍出現在「待審評量」

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 課程已暫停，主任儀表板與學習評量頁仍出現該課的 `pending`／`changes_requested` 評量，誤以為還要填寫／審核。 |
| **正確行為** | 暫停課程的待審／需修改評量**不應列入**待審佇列與相關通知；**已核准、已退回等歷史**仍可查。 |
| **實作要點** | `LearningRecord` scope `excludePausedCoursePendingReview`；`LearningRecordController::index` 套用；`batchApprove` 僅限未暫停之 `StudentClass`；`NotificationSyncService::buildLearningNotifications` 排除暫停課程。 |
| **測試** | `tests/Feature/LearningRecordApprovalDeductionTest.php`（`test_paused_course_hides_pending_learning_record_from_index_but_keeps_approved_visible`）。 |

### B. 課程管理列表：暫停狀態「看不出來」

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 僅小標「已暫停」，整列與操作區與正常課程幾乎相同，主任沒有「真的暫停」的感受。 |
| **正確行為** | 整列背景／左側色條、科目欄上方 **明確 callout**（暫停說明）、學生群組標題 **「含暫停課程」**、展開的上課日期區塊視覺一致；**恢復**按鈕仍清楚可點。 |
| **關聯檔案** | `frontend/src/pages/CourseManagement.vue` |

### C. 主任儀表板「繳費提醒」漏提醒（堂數 0 堂、整類月結消失）

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | `GET /api/v1/alerts/tuition` 只查 `ScheduleMode = 'count'`，**整個月結制（`date`）被略過**；堂數制用 `RemainingSessions > 0 && <= 2`，**漏掉 0 堂**；畫面顯示「全數已繳」易誤導。 |
| **正確行為** | **必須**與 `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` 一致（堂數制 ≤2 含 0、月結 `settlement_day`、距繳費日 &lt; 5 天、逾期未繳等）。 |
| **關聯檔案** | `backend/app/Http/Controllers/AlertController.php`、`frontend/src/pages/DirectorDashboard.vue` |
| **測試** | `tests/Feature/TuitionAlertsApiTest.php`、`tests/Feature/NotificationApiTest.php`（`test_tuition_alert_endpoint_includes_low_sessions_even_when_paid`） |
| **營運手冊** | `docs/OPERATIONS_RUNBOOK.md`（繳費提醒／tuition API 說明需與上列規格文件同步） |

### D. 通知 API 測試與 `unread-count` 內建 sync

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | `GET /notifications/unread-count` 會先執行 `NotificationSyncService::sync`，手動建立的 `Type=tuition` 等**託管類型**可能被自動結案；測試預期的 `active_count` 與實際 sync 來源數不一致。 |
| **正確行為** | 測試用手動通知時使用**非** `managedTypes` 的 `Type`；或斷言與目前 `buildTuition`／`buildLowSessions` 等合併後筆數一致。 |
| **關聯檔案** | `backend/app/Http/Controllers/NotificationController.php`、`backend/tests/Feature/NotificationApiTest.php` |

---

## 檢查清單（快速）

- **前端 bundle 有變**（`frontend/src/**` 等）→ 上線前／Agent 任務結束前必跑 **`cd frontend && npm run deploy`**；**切勿**留下「舊 `index.html` 引用舊 hash + `assets` 已是新 hash」或相反組合。異常徵兆：主控台 **`MIME type of "text/html"`** on `index-*.js` → 先對照本檔 **「index.html 與 Vite hashed chunk 不同步」**。

修改以下路徑時，至少重跑相關 Feature tests：

- `ApprovalSessionSyncService.php` / `SessionDeductionService.php` / `LearningRecordController.php`（approve/batchApprove/rollbackApproval）→ **改動前必問使用者**；`LearningRecordApprovalDeductionTest`（17 tests 全綠）
- `LearningRecordController.php` / `LearningRecord.php` → LearningRecord 測試
- `AlertController.php`（`tuition`）→ `TuitionAlertsApiTest` + `NotificationApiTest`（tuition 相關）
- `NotificationSyncService.php` → `NotificationApiTest`
- `ChatService.php` / `ChatController.php` / `PublicAvatarUrl.php` / `AuthController.php`（`uploadAvatar`、`toAvatarUrl`）→ `ChatApiTest` + `ProfileCenterApiTest`
- `BugReportService.php` / `BugReportController.php` → `BugReportApiTest`
- `CourseManagement.vue` → 手動確認暫停列 UI；有腳本則 `npm run deploy`
- `EnrollmentService.php` / `UniversalClassScheduler.vue` → 必讀 **`docs/MANUAL_SCHEDULE_DATE_SEMANTICS.md`**，勿改「過去手動＝已上完」；學段提示見本檔 **2026-04-11 — 新建課程「學段／科目」提示**（`ref` 傳 `.value`、科目選項須含 `id`）。**前端正向已無入班精靈**；勿在文件或回覆中假設仍有 `EnrollmentWizard.vue`
- `checkTeacherScope` / `TeacherScopeService.php` → 科目多 id、前後端等價比對一致；勿只比對單一 `subject_id`
- `SmartCalendar.vue`（`filteredCourses`、堂數制與 `sessionDatesByCourseId`）→ 多日／多時段須對齊 **該日 `ClassSession` 或 `day_time_slots`**，勿全週套用主檔 `start_time`；見本檔 **2026-04-11 — 智慧排課：同一門課「不同週幾、不同時段」**；變更後 `npm run deploy`
- `DirectorDashboard.vue`（總覽待審評量 API）→ 勿加回 **`only_due=1` 當唯一清單**；見本檔 **「主任總覽待審核評量：only_due」**；變更後 `npm run deploy`
- `ClassSessionController.php`（`index`）→ 勿移除 Subject left join，否則待點名科目空白；見本檔 **2026-04-12 — 出缺勤「科目」欄**
- `Subject` 表 → `Subject_Name` 須為中文（國文、英文…）；新增科目亦同；勿改回英文。前端 `SUBJECT_NAME_MAP` 已支援雙向
- `StudentClassController.php`（`update`）→ Rate 或 SessionCount 異動後須同步 `Charge`；見本檔 **2026-04-15 — 編輯課程費率後 Charge 未同步**

---

## 2026-04-15 — 編輯課程費率後 Charge（總費用）未同步至催繳通知

### 現象

主任在「課程管理」編輯單堂費率（Rate: 1000 → 1100），8 堂課總費用應為 8800，但催繳通知單（`PaymentSlipModal`）仍顯示 NT$8,000。

### 根因

`StudentClass.Charge` 是**建課時的快照欄位**——`EnrollmentService::store()` 與 `purchaseBatch()` 會在建立課程時計算 `Charge = Rate × SessionCount`（或 `Rate × TotalHours`）並寫入 DB。

然而 `StudentClassController::update()` 經 `mapFrontendPayload()` 只映射 `Rate` 與 `SessionCount`，**從未重算 `Charge`**。催繳單 API（`AlertController::tuitionSlipData`）直接回傳 `StudentClass.Charge`，因此金額永遠停留在建課時的數字。

### 修正

在 `StudentClassController::update()` 的 `$studentClass->refresh()` 之後，新增 Charge 同步區塊：

- `rate_unit = 'session'`：`Charge = Rate × SessionCount`
- `rate_unit = 'hour'`：`Charge = Rate × TotalHours`
- 月結制（`SessionCount = 0`）：`newCharge` 為 0 → guard `> 0` 保護不覆寫

### 高風險區塊（修改前必對照）

| 檔案 | 方法 | 注意 |
|------|------|------|
| `StudentClassController.php` | `update()` | Rate/SessionCount 異動後必須同步 `Charge`；勿移除重算區塊 |
| `StudentClassController.php` | `mapFrontendPayload()` | 若新增映射 `Charge` 欄位，確認不與重算邏輯衝突 |
| `EnrollmentService.php` | `store()` | 建課的 Charge 計算邏輯為權威來源，update 應與其保持一致 |
| `StudentClassController.php` | `purchaseBatch()` | 加購時的 Charge 計算也須與 update 同口徑 |
| `AlertController.php` | `tuitionSlipData()` | 直接讀 `Charge` 欄位，不做額外計算；仰賴上游正確 |

### QA 驗收

1. 建課 1000/堂 × 8 堂 → `Charge = 8000`
2. 編輯費率改為 1100/堂（堂數不變）→ `Charge` 應更新為 `8800`
3. 開啟催繳通知單 → 金額顯示 NT$8,800
4. 僅改 SessionCount（8 → 10，Rate 不變 1100）→ `Charge = 11000`
5. 月結制課程修改 Rate → `Charge` 不應被清零
6. 時數制課程修改 Rate → `Charge = Rate × TotalHours`

---

## §FRONTEND-005 — StudentsList.vue 明確欄位 mapper 遺漏方案欄位，方案分支靜默失效

### 問題模式

`StudentsList.vue` 的 `loadStudentCourses()` / `loadAllStudentCourses()` 在將 Laravel API 回應 map 成本地 course 物件時，**採用明確欄位列舉（而非 `...spread`）**。
當後端新增欄位（如 `PackageID`、`package_remaining_sessions`）後，若未同步更新這兩個 mapper，前端 `course.PackageID` = `undefined`（falsy），模板的 `v-if="course.PackageID"` 分支靜默跳到 `v-else`，顯示個別課程的 `remaining_sessions` 而非方案共用池的 `package_remaining_sessions`。

**沒有任何 runtime error**，是典型的「資料正確、顯示邏輯走錯分支」的無聲 bug。

### 已知遺漏欄位（2026-04-22 修復）

| mapper 函式 | 補充欄位 |
|---|---|
| `loadStudentCourses` | `PackageID`, `PackageName`, `package_remaining_sessions`, `package_total_sessions`, `package_used_sessions`, `status`, `closed_reason`, `paid_at`, `last_paid_at`, `sessions_used` |
| `loadAllStudentCourses` | 同上 |

### 防再犯規則

1. **修改 `StudentClassController::index()` 回傳欄位時，必須同步檢查 `StudentsList.vue` 兩個 mapper**。
2. **方案相關欄位（`PackageID` 前綴、`package_*` 前綴）新增時，必須同步更新所有明確 mapper**。
3. 如未來重構 mapper，優先考慮改為 `{ ...c, data_source: 'laravel' }` 並在必要時覆蓋特定欄位，以避免此類靜默遺漏。

---

## §SEC-001 — DB::table() raw queries 不受 Eloquent global scope 保護，campus 過濾必須手動加

### 問題模式（2026-04-23，PR #34）

`TeacherAttendanceController` 的 `index()` / `unclosed()` / `export()` / `exportMonthly()` 全用 `DB::table()` 而非 Eloquent Model。前端傳入 `campus_id`（代表 UI 選定分校），但後端完全忽略，只用 `auth_campus_ids`（用戶所屬**所有**分校）。多分校 director 會看到其他分校的老師出勤記錄。

### 禁止回歸

```php
// ❌ 舊寫法：frontend 傳的 campus_id 被忽略
if ($role !== 'super_admin' && ! empty($campusIds)) {
    $query->whereIn('ts.CampusID', $campusIds); // 所有分校，無視選定分校
}

// ✅ 正確：使用 resolveEffectiveCampusIds() 統一驗證
$effectiveCampusIds = $this->resolveEffectiveCampusIds($request);
if ($effectiveCampusIds instanceof JsonResponse) return $effectiveCampusIds;
if ($effectiveCampusIds !== null) $query->whereIn('ts.CampusID', $effectiveCampusIds);
```

### 強制規則

1. **任何 `DB::table()` 涉及分校資料的方法**：必須同時驗證 `campus_id` 參數是否在 `auth_campus_ids` 子集內
2. **空 `auth_campus_ids` 非 super_admin**：必須回 403，不能 bypass 顯示全部
3. **前端送的 `branch_id` / `campus_id`**：後端有責任驗證，不能盲目信任，但也不能完全忽略

### 業界根因（WebSearch 2026-04-23）

> "Global scopes only protect Eloquent queries. Any raw SQL, DB::table() calls will not apply the tenant filter. Audit every raw query." — IGC Laravel Multi-tenant Guide

---

## §TEST-003 — AI 對自訂 VoidedAt（非 Laravel SoftDeletes）使用 withTrashed() 導致 CI 崩潰

### 問題模式

AI 寫測試時，看到模型有 `VoidedAt` 欄位，誤以為使用了 Laravel 內建 `SoftDeletes` trait，在 test assertion 中呼叫：

```php
StudentSignIn::withTrashed()->find($id)?->VoidedAt
```

但 `StudentSignIn` 用的是**自訂 soft-void 機制**（`VoidedAt` / `VoidedByUserID` / `VoidReason` 欄位 + `scopeActive()`），**沒有** `use SoftDeletes`，導致：

```
BadMethodCallException: Call to undefined method App\Models\StudentSignIn::withTrashed()
```

### 正確做法

```php
// ❌ 錯誤：假設 Model 有 SoftDeletes
StudentSignIn::withTrashed()->find($id)?->VoidedAt;

// ✅ 正確：直接用 DB::table 繞過 Model scope 查詢
$voidedAt = \Illuminate\Support\Facades\DB::table('StudentSingIn')
    ->where('id', $id)
    ->value('VoidedAt');
$this->assertNotNull($voidedAt, 'VoidedAt 應被寫入');
```

### 受影響模型清單（自訂 VoidedAt，無 SoftDeletes trait）

| 模型 | 表名 | 備註 |
|---|---|---|
| `StudentSignIn` | `StudentSingIn` | `scopeActive()` 過濾 `VoidedAt IS NULL` |
| `LearningRecord` | `LearningRecord` | 同上 |

### 防再犯規則

1. **寫測試前先確認 Model 是否有 `use SoftDeletes`**：
   ```bash
   grep "SoftDeletes" /home/admin/backend/app/Models/StudentSignIn.php
   ```
   若無輸出 → 禁止使用 `withTrashed()`、`onlyTrashed()`、`restore()`
2. **VoidedAt ≠ deleted_at**：有 `VoidedAt` 欄位的 Model，查詢時一律用 `DB::table()` 繞過 scope，或用 `Model::withoutGlobalScopes()->find()`
3. **`scopeActive()` 的 Model**：永遠記得 `find()` 只回傳 `VoidedAt IS NULL` 的記錄

---

## §MIGRATION-001 — MySQL 新增帶 DEFAULT 的欄位會自動回填所有舊記錄，導致業務狀態被污染

### 問題模式（2026-04-23 發現）

執行以下 Migration：

```php
$table->enum('Status', ['normal', 'late', 'source_only', 'pending_review'])
      ->default('pending_review')
      ->after('Source');
```

MySQL 的行為：**對已存在的所有資料列，自動填入 `'pending_review'`**（即使這些記錄的實際業務狀態是 `source_only` 或 `normal`）。

結果：所有歷史打卡記錄的 `Status` 都變成 `pending_review`，老師的行政出勤全部顯示「系統待確認」，主任誤以為系統異常。

### 根因

這是 MySQL 的標準行為，**不是 Bug**。DDL `ADD COLUMN ... DEFAULT 'X'` 在 MySQL 8 中是 instant operation，但舊有行的值會被設為 DEFAULT，無論業務語義為何。

### 防再犯規則

1. **新增「狀態類」欄位時，必須同步判斷：舊記錄的 DEFAULT 值在業務上是否合理。**
   - 若 `pending_review` 意為「尚待計算」，舊記錄填此值語義不對
   - 解法：default 改用 `null`（允許 NULL），或 default 改用最安全的已知正確狀態，或同步新增回填 Migration

2. **凡 Migration 加欄位帶 DEFAULT 的，必須立即評估是否需要回填 Migration**：
   ```php
   // 緊接著加一個回填 migration，重新依業務規則計算舊記錄
   // 範例：2026_04_23_200000_backfill_teacher_signin_status.php
   ```

3. **回填 Migration 必須**：
   - 用 `->chunk(200)` 避免 lock timeout
   - 記錄 `Log::info('... start/progress/done ...')`
   - `down()` 為 no-op（回填後無法安全還原）

4. **在 ARCH 設計階段就標明**：「此欄位新增後，存量資料需要回填」（參考本 PRD §10 技術方向標記）

---

## §EXPORT-001 — PhpSpreadsheet 動態 Sheet 名稱為空字串時拋 Invalid parameters 例外

### 問題模式（2026-04-23 發現）

以老師姓名作為 Excel Sheet 名稱（動態命名）：

```php
class TeacherMonthlyPerTeacherSheet implements WithTitle
{
    public function title(): string
    {
        return $this->teacherName; // ← 若 teacherName 為 '' 則 PhpSpreadsheet 拋例外
    }
}
```

當 `TeacherSingIn` 記錄的老師在 `Teacher` / `User` 表均無對應行時，`COALESCE(t.T_Name, u.Name, '')` 回傳空字串，`title()` 傳回 `''`，觸發：

```
PhpOffice\PhpSpreadsheet\Writer\Exception: Invalid parameters passed.
  in PhpOffice/PhpSpreadsheet/Writer/Xlsx/Workbook.php:211
```

CI 出現 HTTP 500，整個 XLSX 下載失敗。

### 正確做法

```php
// ✅ 在 Export 層加空字串防護
$raw = $teacherRecords->first()->teacher_name ?? '';
$teacherName = $raw !== '' ? $raw : "老師{$teacherId}";

// ✅ 在 Sheet 層 sanitize 後也加防護
$sanitized = $this->sanitizeSheetName($teacherName);
$this->sheetTitle = $sanitized !== '' ? $sanitized : 'Sheet';
```

### 防再犯規則

1. **凡用動態字串作為 Sheet 名稱**，一律在 `title()` 傳出前加 non-empty guard
2. **PhpSpreadsheet Sheet 名稱的限制**：
   - 不可為空字串
   - 不可含 `/ \ ? * : [ ]`
   - 最多 31 字元（multibyte 需用 `mb_substr`）
3. **寫 DB 查詢做 export 時**，`COALESCE(..., '')` 的最後一個 fallback 應改為有意義的字串，或在 Export class 層再做 fallback
4. **Export 測試資料**若沒有完整的 Teacher/User join 記錄，要手動補 teacher_name，或在 test 中直接插入 Teacher 記錄

---

## §P0-006 — AI 在 production 跑 `php artisan test`（第三次 DB 清空事故）

### 事故（2026-04-23 17:17，fix/self-study-status-edit）

AI 為了驗證自修狀態編輯的測試（RED→GREEN），在 `/home/admin/backend` 直接執行：

```bash
cd /home/admin/backend && php artisan test tests/Feature/AttendanceSelfStudyStatusTest.php
```

`RefreshDatabase` trait 對 production `AllTrue` DB 執行 `migrate:fresh`，**清空所有資料表**。

### 影響

- User: 87→0、Student: 544→0、ClassSession: 5665→0
- 全站無法登入（User 表空）
- 從 `sixhour/alltrue_6h_2026-04-23_1700.sql.gz` 還原，資料損失窗口約 30 分鐘

### 根因

1. **AI 明知 R2 紅線仍違反**：本對話稍早才剛寫完「開工前必讀摘要」中的 R2（禁止在 Pi 上跑測試），幾分鐘後就自己違反
2. **誤以為「只跑一個測試檔」比較安全** — `RefreshDatabase` 不分測試數量，一律 `DROP ALL TABLES`
3. **這是同一 workspace 第三次發生 DB 清空**（§2026-04-22 P0 最高級、§2026-04-23 事故E、本次）

### 加嚴規則

- R2 紅線新增「包括只跑單一測試檔也禁止」
- `.cursorrules` 第 8 條（⛔⛔⛔ 絕對禁止 php artisan test）已是最高級警告，但 AI 仍執行
- **任何涉及 `php artisan test`、`phpunit`、`vendor/bin/phpunit` 的指令，AI 必須在執行前停下來，確認「我是不是在 /home/admin/backend？」，答案是 YES 就絕對不執行**

---

## §P0-005 — AI 在 feature branch 直接修改 production 檔案，違反 CI-first 規則

### 事故（2026-04-23，fix/self-study-status-edit）

AI 在 `/home/admin` 建立 feature branch `fix/self-study-status-edit` 後，**直接編輯了 production 伺服器上的檔案**：

- `backend/app/Http/Controllers/AttendanceController.php`（後端 API）
- `frontend/src/pages/AttendancePage.vue`（前端頁面）

由於 `/home/admin/backend` **就是 production 伺服器的 document root**，任何本地檔案修改會**即時影響線上服務**。

### 影響

- `AttendanceController::update()` 的 validation rule 被改動，可能導致使用者操作時收到非預期的 422 或行為異常
- `AttendancePage.vue` 為前端 source，雖需 build 才生效，但先前已執行過 `npm run deploy`，若再次 deploy 會把未經 CI 驗證的程式碼推上線
- 同一時段 `npm run deploy` 重建前端 bundle，造成瀏覽器 JS hash 不匹配、auth token 狀態丟失，使用者看到全站 401

### 根因

1. **忘記 `/home/admin` 即 production**：在 feature branch 上修改檔案等同直接改 production
2. **未遵守 RED-GREEN-REFACTOR on CI**：正確流程是只寫測試（新檔案），push 到 GitHub 讓 CI 跑 RED，再改 code push GREEN — 全程不改 production working tree 的既有檔案
3. **`npm run deploy` 執行時機錯誤**：應在 CI 全綠、PR merge 後才 deploy，不是發現問題就先 build

### 正確流程（必遵守）

```
1. git checkout -b fix/xxx        ← 建 branch（OK，但不改既有檔案）
2. 只新增 test 檔案               ← 新增不影響 production
3. git add tests/ && git commit && git push
4. CI RED 確認 → 修改 production code → commit → push
5. CI GREEN → PR review → squash merge
6. git checkout main && git pull   ← production 才更新
7. npm run deploy                  ← 前端才重建
```

### 禁止事項（加入 P0 清單）

| 編號 | 禁止行為 |
|------|----------|
| P0-005a | 在 feature branch 上修改 `backend/app/`、`backend/routes/`、`backend/config/` 等既有 production 檔案 |
| P0-005b | 在 PR merge 前執行 `npm run deploy` |
| P0-005c | 在沒有使用者明確要求的情況下執行 `npm run deploy`（auto-deploy rule 僅限「本對話中曾修改前端 + 已 merge 到 main」的情境） |

### 防再犯

- 每次要修改既有後端 / 前端檔案前，先問自己：**「這個檔案改了會不會直接影響 production？」**
- 答案永遠是 **YES**（因為 workspace = production）
- **唯一安全的寫入**：新增測試檔案（`tests/`）、新增 Export class、新增 migration — 這些不會被既有 route 載入
- 參考 `§2026-04-22 P0 最高級事故` 和 `p0-no-system-changes-before-ci.mdc`

---

## §TEST-004 — schedules 表必填欄位再次漏填（二次違反 §TEST-001）

### 問題（2026-04-23，TeacherSigninStatusBackfillTest）

`§TEST-001` 已列明 `schedules` 表的必填欄位，但本次仍漏填：

```php
// ❌ 只給了 teacher_id / schedule_date / start_time / end_time / status
DB::table('schedules')->insert([
    'teacher_id'    => 888,
    'schedule_date' => $date,
    'start_time'    => '09:00',
    'end_time'      => '11:00',
    'status'        => 'scheduled',
]);
// 錯誤：Field 'student_id' doesn't have a default value
```

### 正確最小插入（`schedules`）

```php
DB::table('schedules')->insert([
    'student_id'    => 1,          // NOT NULL
    'teacher_id'    => 888,
    'day_of_week'   => 1,          // NOT NULL（0=Sun, 1=Mon...）
    'branch_id'     => 1,          // NOT NULL
    'schedule_date' => $date,
    'start_time'    => '09:00',
    'end_time'      => '11:00',
    'status'        => 'scheduled',
    'type'          => 'normal',   // 有 DEFAULT 'normal'，可省略，但建議明寫
]);
```

### 防再犯

- `§TEST-001` 的 `schedules` 行已列出必填欄位，**每次寫涉及 schedules 的測試必須對照該表格**
- 速記口訣：**S.D.B.**（`student_id`, `day_of_week`, `branch_id`）— schedules 三巨頭必填

---

## §MIGRATION-002 — chunk() 在 mutation 下跳行（600 筆 pending_review 未被修正）

**發生日期**：2026-04-23  
**PR**：#20 fix/teacher-pending-review-backfill

### 事故經過
`2026_04_23_200000_backfill_teacher_signin_status` 使用 `DB::table()->chunk()` 在 callback 內對同一張表 UPDATE Status，
導致 OFFSET 分頁計算錯誤（row 被移出 WHERE 條件後，後續 batch 的 OFFSET 基準漂移），約 600 筆記錄被跳過，
仍保留 `pending_review` 狀態。

### 根本原因
```sql
-- chunk() 內部分頁方式：
SELECT * FROM TeacherSingIn WHERE Status='pending_review' ORDER BY id LIMIT 200 OFFSET 0
-- callback 更新 200 筆 Status → 這 200 筆消失於結果集
SELECT * FROM TeacherSingIn WHERE Status='pending_review' ORDER BY id LIMIT 200 OFFSET 200
-- 此 OFFSET 已跳過消失後移位的 200 筆！後 200 筆的前 N 筆被跳過
```

### 解決方法
- 改用 `chunkById()`：以 `WHERE id > last_processed_id` 分頁，mutation 對分頁無影響
- 新增 `2026_04_23_400000_fix_backfill_teacher_signin_chunkbyid.php` 補執行 600 筆
- 生產執行後 `pending_review` 從 600 降為 0；所有記錄正確歸類為 `source_only` / `normal` / `late`

### 防再犯規則
> **⛔ 任何 migration 在 callback 內 UPDATE 迭代基準（WHERE 條件欄位）時，必須使用 `chunkById()` 而非 `chunk()`**

```php
// ❌ 危險：
DB::table('T')->where('Status', 'pending')->chunk(200, fn($rows) => DB::table('T')->where('id', $r->id)->update(['Status' => 'done']));

// ✅ 安全：
DB::table('T')->where('Status', 'pending')->chunkById(200, fn($rows) => DB::table('T')->where('id', $r->id)->update(['Status' => 'done']));
```

---

## §TEST-005 — 時間敏感 CI 測試：start_time 16:00 在 CI 18:00+ 後執行導致 isEndedAtCreateTime=true

**發生日期**：2026-04-23  
**PR**：#20 fix/teacher-pending-review-backfill  
**受影響測試**：`ClassSessionBatchApiTest::test_batch_endpoint_recalculates_remaining_sessions_using_completed_status`
              `ClassSessionBatchApiTest::test_recalculate_session_counters_counts_legacy_attended_for_compatibility`

### 根本原因
`EnrollmentService::batchStore()` 含邏輯：`future_date` 的下課時間已過 → 自動升格為 `completed`（`createdConfirmedSessions++`）。
測試使用 `start_time: 16:00, duration_minutes: 120`（下課 18:00 UTC+8 = 10:00 UTC），
當 CI 在 10:00 UTC 後執行時，今日 Thursday 的 future_date 被計為 confirmed，導致 count 4 ≠ 3。

### 解決方法
將 `start_time` 改為 `23:00, duration_minutes: 30`（下課 23:30 UTC+8 = 15:30 UTC），
CI 在全天任意時間跑都不會觸發 `isEndedAtCreateTime`。

### 防再犯規則
> **⚠️ 測試中含「今日」日期的 future session 時，start_time 必須用 23:00 以後，確保 CI 在任意時間執行都不會觸發 isEndedAtCreateTime**

---

## §PLAN-001 — 寫 PRD/計畫時第一次就用完整 14 節模板

**發生日期**：2026-04-23
**任務**：教學工作台打卡狀態卡片 UI 改版

### 錯誤行為
收到「照 SOP 寫計畫」指令後，第一次輸出自訂簡化格式（只有 5 節），
等使用者糾正「我們不是有模板嗎」才補完整 14 節 PRD。

### 根本原因
- 沒有在收到「寫計畫」指令時**立即**讀 `plan-as-prd-cross-functional.mdc`
- 認為「小 UI 改版不需要完整格式」→ 主觀判斷代替規則執行

### 強制規則（防再犯）
> **收到任何「寫計畫 / 寫 PRD / plan / 規劃」指令，必須：**
> 1. 立刻讀 `.cursor/rules/plan-as-prd-cross-functional.mdc`
> 2. 立刻讀 `.cursor/rules/prd-section-guide.mdc`（含第 5b / 8b / 10 / 11 / 13 / 14 節格式）
> 3. 在腦中確認 14 節清單全部列出，缺任何一節不得送出
> 4. **規模大小不影響格式要求**：小改版也要完整格式，細節可以簡短，但節次不能省

