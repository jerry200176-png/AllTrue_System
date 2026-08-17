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

### R0b. Artifact 已產生 ≠ 已交付給真人（2026-07-22）

- 需要主任／家長／真人接收的 workflow：啟用前必須過 [`OUTBOUND_READINESS_GATE.md`](governance/OUTBOUND_READINESS_GATE.md)（recipient／channel health／test delivery／ack path／PII）。
- `skipped_no_line`、缺 `staff_line_group_id`、僅 Actions artifact → **Operational Delivery BLOCKED**，即使 Engineering PASS。
- #1342：`awaiting_delivery` 直到交檔；簽收後才 `awaiting_review`；逾 ack 未簽收 = `deadline_at_risk`（≠ 內容未審）。

### R1. `/home/admin` 就是 production — 在 Pi 改檔案 = 改線上

```
/home/admin/backend/  ← nginx 直接 serve 的 document root
/home/admin/frontend/ ← npm run deploy 後 copy 到 backend/public/
```

- **只要 cwd 在 `/home/admin` production working tree，任何分支修改既有 .php/.vue/config 檔 = 即時影響 production**
- 在 Pi 上 `git checkout -b` 不會隔離 working tree；WSL2 **task worktree**（非 `/home/jerry/alltrue`）才是安全開發路徑 — 見 `docs/governance/WORKTREE_POLICY.md`
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

**延伸（2026-07-22）**：generic「我知道，仍要新增課程」不可作為長期 bypass。正式課衝突須決策（加購／下一期續報／獨立＋原因／取消）；試聽文案須為「建立試聽」。`force=true` 必須帶 `force_reason` + actor／existing_contract_ids 審計（#1379 follow-up）。Course Continuity 才是最終關聯模型。
**延伸（2026-07-22）**：`overlapping_active_course` 是「續報重疊」守衛，**不可套用到 `class_type=trial`**（試聽＝旁聽正式課堂，見 `ScheduleGuardService` FR-002）。另：`SmartCalendar` 快速排課若未接 `@duplicate-course`，409 會被 `emit` 後靜默吞掉——所有掛 `UniversalClassScheduler` 的入口都必須有 force modal（學生管理／課程管理／行事曆三者對齊）。

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

### Y6. 多 agent／多 session 並行 → 用 git worktree 隔離，勿在 forbidden dirty tree `/home/jerry/alltrue`（或解析到該路徑的 `~/alltrue`）共改

- 本專案常多個 AI agent 並行（#692／#699／maturity／ops…）。**共用同一個 forbidden/dirty working tree（歷史上常是 `~/alltrue` → `/home/jerry/alltrue`）會 race**：別的 agent 一 `git checkout`／切分支，就把你**尚未 commit 的改動還原成 HEAD**。症狀：`git status` 顯示乾淨、但你的編輯不見了；branch ref 在不同 commit 間跳動。
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
| **F1 狀態收尾缺口** | 主檔狀態變更（`Stop=1` / 老師 `suspended` / 月結結算）後，**未對齊未來 `ClassSession.scheduled` / `schedules` / 老師名額**，殘留堂次續顯示；**反面**：堂數制仍有 `RemainingSessions` 時不得把請假順延尾堂當幽靈取消 | #151、#427、#99、**#1839**、行290、§R32、§R59、**§R109** | 停用/結算課程或老師後，未來 scheduled 堂次不得再出現在行事曆/名額；已上堂次須保留；**count + RemainingSessions>0 禁止 settled/completed 除非 `forfeit_remaining`** |
| **F2 月結續期語意** | 續期未依**當期實際堂數**重算金額/堂次；收據未綁 `billing_period` | #149、§R22、§R26、#554、#594 | 續期＝新一期+結算舊期；收據金額=當期堂數×費率、含結算月 |
| **F3 排課堂次生成** | 建課後未依 `week/time` 契約**推算/補齊完整未來堂次**（只生成片段） | #148、#497、#539、#424、§R22、§R23、§R64（週日 slot 全滅→0 元月結） | 建課後即依契約生成完整未來 ClassSession；預排日不得反白/dead-end；weekday 比對先 `isoWeekday()` 正規化 |
| **F4 共用堂數（一對三）** | `Charge` 未計算（=0）；**購買堂數 vs 實體 ClassSession 數**呈現混淆；把方案池總堂數當成員課程應物化列數 → 假「不一致」警告；堂數制 projected chip 誤呼叫 ensure-projected；把方案池剩餘數當成員可排能力 | #147、#553、#430、#448、#440、§R21、§R24、#1465；架構後續見 **ADR-006**（Commitment→materialize→pool coverage；非餘額猜堂） | 池／成員排課／已用分欄；package under→info 且**成員課程 UI 不顯示方案池剩餘**；無 allocation aggregate 前不推導尚可排／未排 N；count projected 不呼叫 ensure-projected；物化 affordance 僅 `ScheduleMode=date` |
| **F5 行事曆合併** | week 檢視 merge/去重/過濾**排除有效堂次**（含歷史已上） | #152、§R47、§R49、§R50、行544、§G-007 | 唯一走 `calendarOccurrenceMerge.js`；`npm run test:calendar`；歷史已上堂次仍顯示 |
| **F7 繳費金額/狀態雙真相** | `Charge` 與 `Rate×數量` 的差額、`StudentClass.Paid` 與 Invoice/Payment 各有兩套真相；點修單邊會「改了又跳回」 | #112、#425、#509、#798、#799、§G-009 | Charge 差額必須可追溯到 `session_charge` 調整；有效收款紀錄存在時課程不得被改為未繳費（解鈴走帳單作廢），任何降級路徑都要明確回饋不得靜默 |
| **F6 輸入邊界 collation／長度** | utf8mb3 文字欄遇 **4-byte 字元（emoji）** → `like` collation 1267 crash；**寫入**同根因 → `Incorrect string value` 1366（`StudentClass.Memo`）；另 **VARCHAR(512) 溢位** → SQLSTATE 22001 Data too long（貼繳費說明） | #657、**#1378**、**#1732** | 搜尋：先濾 4-byte；**寫入**：canonical 修 charset→utf8mb4（禁默默刪 emoji）；過渡期回 422 `memo_charset_incompatible` 且 transaction 回滾；超長備註須 422 `memo_too_long`，禁止 500 |

**通用防再犯規則（跨家族）：**
1. 任何「**狀態變更**」（停用、結束、結算、續期、調課）寫主檔時，必須在**同一交易內**決定其衍生 `ClassSession`/`schedules`/名額/金額如何對齊，並寫測試覆蓋「變更後衍生資料正確」。
2. 任何「**列表/行事曆/收據**」呈現課程資料時，先確認資料來源是否涵蓋 **歷史/停用/未來/月結推算** 四種狀態，缺一即為潛在 F1/F2/F5 復發。
3. 修任一家族成員，PR 必須引用本節家族代號（F1～F6）並附「**revert 後會 fail**」的回歸測試；否則視為點修，會再復發。
4. DB 文字欄若為 `utf8mb3`：查詢 `like` **先濾**非 BMP（F6 搜尋）；**寫入**路徑則必須升級欄位 charset 至 utf8mb4（#1378），禁止永久靜默刪 emoji。

**延伸（2026-07-22 / #1378）**：production `StudentClass.Memo` 為 utf8mb3 時，備註含 📅 會讓建課 transaction 整筆失敗。CI DB 預設 utf8mb4 → 測不到。修法：migration `2026_07_22_130000_convert_student_class_free_text_to_utf8mb4` + `StudentClassMemoUtf8mb4Test`；Founder GO → [`docs/runbooks/1378-memo-utf8mb4-execution-package.md`](runbooks/1378-memo-utf8mb4-execution-package.md)。

**延伸（2026-08-17 / #1732）**：VARCHAR(512) 備註被貼上整封繳費說明 → `StudentClassController::update` 無長度驗證、直接 500。修法：Memo→TEXT + `MEMO_MAX_LENGTH` 422；見 §R111。

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
- **2026-07-18 延伸（新店黃芝琳／王品方／陳品承）**：出缺勤 pending 去重必須用 `student_id|date|start`（不可只用 `student_class_id`）；`GET /api/v1/class-sessions` 預設排除 `Stop=1`+`scheduled`；forward-gen 遇跨 SC 同 slot 必須 skip + `cross_sc_slot_conflict` log；digest 監控 `scheduled_cross_sc` / `orphan_stop_scheduled`。TeacherHome 已用 student-slot 去重，故同 bug 可能只在出缺勤爆。

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

---

### R44. 工作流入口不可只顯示通知，必須保留可定位的下一步

- 家長請假已存在 `ExceptionWorkflow` 與主任決策 API，但課程管理若只顯示「請到主任收件匣」，使用者仍會不知道在哪裡按 approve／退回；這是 workflow discoverability 與跨頁 navigation contract 缺口，不是再新增一套業務流程。
- **強制規則**：任何跨頁待辦摘要必須至少顯示對象、原事件、狀態、下一步與明確動詞 CTA；CTA 必須攜帶可驗證的 entity/workflow ID，導向目標頁的指定區段/案件。字串頁面導覽與 object deep-link 必須由 adapter 統一處理，不能直接把 object 塞進 active page state。
- **可及性規則**：主要 CTA 不可藏在水平滑動區；手機可見、可鍵盤 focus；不可產生巢狀 interactive element（例如 button 裡再放 button）。
- **測試必補**：每個跨頁 workflow 至少覆蓋 normal／empty／loading／API error／long text、390／412／768／1280／1440、無水平 overflow、deep-link payload 與返回/重試路徑。
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

### R68. 排程任務上線必須驗證主機 driver 存在（`schedule:list` 顯示 Next Due ≠ 有在執行）

- **觸發情境**：2026-07-10 稽核發現 production Pi **從未有** `php artisan schedule:run` cron／systemd timer／daemon——`Kernel.php` 8 個夜間任務（reconcile、close-orphans、stranded 稽核、LR 回填、復現閘門…）全部從未執行。`schedule:list` 印出 Next Due 讓所有文件（含 G-010）誤信「已排程」。後果：LR 缺口重新累積 43 筆、stranded 352→387 無人反制，靜默數週。
- **根因**：Laravel scheduler 是兩層架構——Kernel 定義「何時跑什麼」，主機 cron 每分鐘 `schedule:run` 是唯一驅動器。只驗證了前者。
- **強制規則**：
  1. 新增/修改任何 `Kernel.php` 排程任務時，必須同時驗證 Pi 上 `crontab -l | grep schedule:run` 存在，並於次日檢查 `/home/admin/logs/schedule.log` 有該任務的執行痕跡。
  2. 「已排程」的證據 = 執行 log，不是 `schedule:list` 輸出、不是程式碼。
  3. `pi-health.yml` §3b 心跳檢查（schedule.log 10 分鐘內必須有更新）為此的自動防線，不得移除。
  4. 心跳只證明 driver 活著，不能證明每個任務完成；每個排程任務必須保留私有 output 與 PII-free completion ledger，並由 `scheduler:evidence-summary` 在次日 health check 驗證「每任務恰好一次、成功、輸出可解析」及對應 aggregate postcondition。
- **測試必補**：pi-health scheduler 心跳 critical（本條隨 #1127 併入）。

---

### R69. 多堂 reflow 不可用會被前一步改寫的 natural key 逐筆同步 `schedules`

- **觸發情境**：契約由週六＋週日壓縮成只剩週六時，`ClassSession` 依序 4/19→4/25、4/25→5/2。舊同步邏輯先把 4/19 的 schedule 改成 4/25，下一輪又以 `schedule_date=4/25` 查詢，導致同一 schedule 被二次搬到 5/2；堂次與評量日期正確，但例外／代課 anchor 靜默錯位。
- **根因**：bulk permutation 在同一交易中以「舊日期＋時間」作 mutable natural key；前一個 update 產生的值恰好是下一個 move 的查詢條件。
- **強制規則**：reflow 寫入前先 snapshot 每個 move 對應的 `schedules.id`，後續只按 immutable ID 更新；`ClassSession` park/place、active `LearningRecord` 與 schedule anchor 必須在同一 domain service／transaction 完成。禁止在逐筆 move 中重新用已可能改寫的日期查 schedule。
- **測試必補**：`RealignReflowTwoPhaseTest` 同時斷言 ClassSession 最終 slot 唯一、LearningRecord 跟隨其 ClassSession、4/19 schedule **只**移到 4/25（不可連鎖到 5/2）。

---

### R70. 維運診斷面板預設唯讀；禁止由 mock 發明不存在的修復 API

- **觸發情境**：#1188 夜間對帳面板前端把後端 `{data: report}` 外層誤當 report，畫面因此沒有摘要／明細；同時提供「逐筆／全部重算」，但 `POST /admin/reconcile/recompute` 從未存在。前端 mock 全綠，真實 API 契約與資料修復安全閘門都未被驗證。
- **根因**：frontend-only PR 只 mock composable 下游，沒有 producer/consumer contract test；UI 把「診斷」和「修改 production 計數器」混成同一流程，違反 #1188 的 read-only first 與備份／核准／回滾要求。
- **強制規則**：
  1. 維運／資料品質面板預設唯讀；任何 production 資料修復必須另有 dry-run、備份、明確核准、audit 與 rollback package，禁止先放按鈕再等後端補路由。
  2. API client 必須測試真實 envelope（如 `{data: ...}`）與 404/500；composable mock 測試不能取代 producer/consumer contract test。
  3. 排程／GitHub evidence 只能輸出固定 key 的 PII-free aggregate；姓名、分校等顯示資料僅在已授權 API request-time enrich，不寫入 scheduler evidence。
- **測試必補**：`AdminReconcileControllerTest`（super_admin enrich 且磁碟報告無姓名）、`api.reconcile.test.js`（unwrap/404/error）、`NightlyReconcileTest`（原因分類與正確 super_admin `User.type='S'` 通知）。

---

### R71. 請假出缺勤是封閉的課堂佔位，不是等待簽退的 presence interval

- **觸發情境**：02:30 orphan repair 已成功執行，05:15 health 卻仍看到一筆前一日未簽退；下一個夜間週期又修正 20 筆。所有修正列都屬請假，其中唯一跨夜 survivor 是 02:30 後補登的歷史請假。
- **根因**：多條請假寫入路徑只填 `SignInDT`、把 `SignOutDT` 留空；補登歷史堂次時 `SignInDT` 是過去、`MDT` 是現在，因此必然逃過已完成的夜間批次。repair 能清資料，但不能阻止 producer 每天重建同類 orphan。
- **強制規則**：`StudentSingIn.Status='leave'` 代表課堂狀態佔位，建立時必須同時保存該 `ClassSession` 的 start/end；任何 active leave 缺 `SignOutDT` 必須 fail closed。非 Eloquent 寫入仍須由同日、全日期的 PII-free health aggregate 偵測，不能等隔夜 repair 才看見。
- **測試必補**：所有請假入口都斷言 `SignOutDT=ClassSession.EndTime`；另覆蓋 02:30 後補登前一日堂次、status edit fallback、model guard，以及 raw writer bypass 會使 scheduler evidence unhealthy。

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

- **design hex guard 必須只計可執行內容，不計註解**：舊 detector 曾把註解裡 3–4 位十六進制 issue 編號（如 `// #765`）當成色票，造成無關 CI failure（#1155）。`scripts/design-hex-counter.mjs` 現在先以 comment-aware scanner 排除 Vue HTML、JS/CSS 註解，再計 CSS 合法的 3/4/6/8 位 hex；測試必須同時保證註解 `#NNN` 不計數、真實 `#ff0000` 與全數字色票 `#123456` 仍被攔截。
- **codemod 必須限定區域**：自動把 hex→`var(--ds-*)` 的 codemod**只能作用於 `<style>` 區塊 + inline `style=""`/`:style` 綁定**；絕不可全檔替換，否則會改到 (a) 註解／正文的 `#NNN` issue 引用、(b) JS 功能色板（avatar/teacher/軍階識別色）、(c) chart canvas 色（canvas 不吃 CSS var）。功能性多態識別色（如 `TEACHER_AVATAR_PALETTE`、`RocRankBadge` 軍階色）刻意保留 raw hex，屬 TD-064 例外。
- **branch 命名**：presubmit CHECK 1 只允許 `feat|fix|hotfix|chore|exp` + `td-batch<N>-` + `dependabot/`。**`docs/`、`ci/` 都會被擋** → 文件/CI 改動用 `chore/`。
- **PR size**：presubmit CHECK 2 硬上限 **700 行**（含增刪，排除 lock/data）。3 頁合一的治理 PR 容易爆（曾 868 行被擋）→ 一頁/數個小元件一 PR。
- **single-line JSON baseline 衝突**：`docs/design-hex-baseline.json` 是單行；多個治理 PR 各自 relock 會在合併時衝突。**逐頁/批次 PR 不要各自帶 baseline**，全部 merge 後做**一次** `bash scripts/design-hex-count.sh > docs/design-hex-baseline.json` 統一 relock。
- **`backend/public/storage` symlink 會卡住 `git reset --hard` / `git merge`**（WSL/Windows 掛載：`Function not implemented` / `File exists`）→ 改用 `git reset --mixed` 移 HEAD（不寫工作樹）再清殘留；勿對 protected 路徑設 `assume-unchanged`（R58 + pre-commit hook 會擋）。
- **merge-train 稅（拓撲部分已 superseded）**：strict required checks 仍會讓其他 PR 在 main 前進後變 `BEHIND` 並重跑 CI；不可用 `gh pr merge --admin` 繞過。2026-07-14 起所有 jobs 已改用 GitHub-hosted runner，可平行執行，不再受單一 WSL2 runner 序列化；現況見 [`REF_CI_RUNNER_TOPOLOGY.md`](REF_CI_RUNNER_TOPOLOGY.md)。
- **同名測試 DB 的隔離取決於 runner 儲存邊界（#732 已 superseded）**：`backend/phpunit.xml` 仍以 `force="true"` 設定 `DB_DATABASE=AllTrue_test`，但每個 GitHub-hosted PHPUnit job 都有獨立 MySQL service container，因此同名 schema 不會跨 run 互相清表。若未來改回共享 self-hosted MySQL，必須先提供 run-scoped schema 或等價隔離，不能只改 runner label。

---

### R60. 新增 API 路由必須確認落在 `role` + `require_campus` 認證群組內（不可裸放在群組外）

- **觸發情境**：2026-05-31 開發家長回饋雙向回覆時審查發現，System B 的 `parent-feedback/{for-teacher,read,reply,replies}`（#409/#410）被加在所有 `role:`/`require_campus` 群組**之外**，只剩全域 `AttachAuthUser`（只附掛 user、不強制認證/授權）→ 等同未認證即可呼叫的端點。所幸前端 0 引用，未被利用。
- **根因**：`routes/api.php` 很長且巢狀多個 `Route::middleware([...])->group(...)`；在群組**結束後**（`});` 之外）接著寫新路由，會誤以為仍在群組內，實際已落到無認證區。
- **強制規則**：
  - 新增任何需登入的端點後，**必看它前後的 `});` 與縮排**，確認確實在預期的 `role`/`require_campus` 群組內；員工端最少 `role:...` + `require_campus`，家長端 parent token + ownership + `throttle`。
  - 寫權限/越權測試（403 跨師、403 跨校）才算完成；不要只測 happy path。
  - Code review 對 `routes/api.php` 的 diff 必須逐條確認所屬群組，不可只看路由字串對不對。
- **本次處置**：四個 System B 端點收斂進 `role:teacher,director,super_admin`+`require_campus`+`require_password_change`；PR #1056 再以 `authorizeStaffParentFeedback` 補齊 teacher/student 與 director/campus 的 per-row ownership（TD-056 Done）。System A 回覆端點同樣位於既有 staff middleware 群組並以 `authorizeStaffFeedback` 做 row-level ownership。
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
- **強制規則**：`LearningRecordController::store()` 遇到既有 LR `VoidedAt!=NULL` 時，必須檢查 `VoidReason` 屬 **`SYSTEM_RESURRECTABLE_VOID_REASONS` 白名單**（`一般請假`/`由已上調整狀態`/`補請假：已上課改請假`/`單堂標記請假`，皆為系統 cascade 作廢且作廢當下已沖回堂數或該堂未扣堂）且 `ClassSession.Status` 屬於 fillable（`attended/scheduled/completed/late`）→ 自動 resurrect（清 void 欄位、轉 `pending`、用新 payload 覆寫）；其他情境（手動作廢、真實取消 / leave）維持 409 拒絕。**新增系統作廢原因時，務必同步加入此白名單**（用 `in_array` 不要再寫死單一字串）。**`ClassSessionController` scheduled→attended/late 與點名寫入路徑也必須呼叫同一份 `restoreEligibleForSession`**，不可只靠老師重送 store（張韙 2026-08-14：attended→scheduled→attended 後老師端沒有草稿可點）。
- **測試必補**：`LearningRecordVoidedResurrectTest`：(1) cascade voided（一般請假）+ 堂次 attended → 200 + LR 復活 (2) 手動作廢（VoidReason 非白名單）仍 409 (3) 堂次仍 leave/cancelled 仍 409 (4) #146：`由已上調整狀態` + 堂次 attended → 200 + 復活 + `SessionDeducted=false`。
- **副作用提醒**：resurrect 後不可自動再扣 `RemainingSessions`（扣堂仍走 `LearningRecord approved → AttendanceEffectsService` 路徑）；本規則只是把卡關打開，不改業務語意。白名單原因在作廢時都已沖回堂數，resurrect→pending→核准會 net-correct 扣 1 堂。
- **2026-07-28 結構性補強**：架構稽核複查發現 `ClassSessionController::restoreVoidedLearningRecord()`（leave→attended 自動復活路徑）從未檢查 `VoidReason`，只要 session 曾經是 `leave` 現在轉回 attended-like，就無條件復活該堂任何已作廢 LR——與 `LearningRecordController::store()` 的白名單判斷各自維護，兩者可能漂移（同一類根因見 R83/R84、TD-060）。已抽出共用 `LearningRecordResurrectionPolicy::isEligibleForResurrect()`，兩處都改呼叫同一份判斷；`CourseLeaveCascadeService` 的請假撤銷復原（只認 `VoidReason='一般請假'`）維持原樣不動，因為那是刻意窄範圍（只復原「這次請假」本身作廢的記錄，非任意系統白名單原因）。

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

### R45. 家長入口版本公告必須分眾（不可套用教職員 CHANGELOG／關鍵字推導）

**觸發情境**：2026-05-10 家長反映「版本更新太長、與家長無關」；2026-07-26 Founder 核准 B+：關鍵字自動標 `audience:parent` 仍會把主任／代課／帳務內部卡洗進家長首頁。

**根因（演進）**：
1. 早期：`notesForRole('parent')` 複製 director/teacher 全集。
2. 中期：以「請假|評量|帳務|課表…」關鍵字替日卡加 `parent` → false positive（日卡粒度 + 寬關鍵字）。
3. 詳情曾用 staff `summary`，與 teaser 不同源。

**強制規則（B+，2026-07-26）**：

- **家長更新唯一來源**：`docs/PARENT_UPDATES.yml` → `frontend/src/lib/parentUpdates.generated.js`（`npm run sync-release-notes`）。
- **禁止**從 `docs/CHANGELOG.md` 以關鍵字／bullet 推導家長內容；staff 卡 `audience` 僅 `teacher`/`director`。
- `notesForRole('parent')` 只回傳未過期的 explicit projection（`title`/`summary`/`details`）；**不得** fallback 到 staff summary／sections／items。
- 無家長更新時 `[]` 合法；`ParentPortal` 隱藏整塊「與您有關的更新」。
- 普通更新建議 `expires_at` = 發布後 30 天；首頁最多兩則。
- **需家長行動**（重新綁定／登入／付款／資料可見性）→ 通知中心／持續 Banner，不放更新卡，也不受 `slice(0,2)`／過期排序影響。
- 改 YAML 或產生器後：`cd frontend && npm run sync-release-notes`，**提交** generated 檔；CI `git diff --exit-code` 防漂移。
- 測試：`npm run test:release-notes`（空清單合法；禁 staff jargon 進 parent projection）。

### R85. 教職員「版本更新」必須顯式核准（不可由 CHANGELOG 自動發布）

**觸發情境**：2026-07-29 Founder 拍板：工程紀錄與使用者公告拆成兩個內容產品。既有 `changelog-to-release-notes` 自動刮字會造成日期亂序、「55 復活／IsContractException／Phase 3A」等一般人看不懂的卡。

**強制規則**：

- **教職員公告唯一來源**：`docs/STAFF_UPDATES.yml` → `frontend/src/lib/staffUpdates.generated.js`。
- **禁止**把 `docs/CHANGELOG.md` 自動投影當正式發布來源；CHANGELOG 僅可產生 `changelogDraft.generated.js` 供起草。
- `notesForRole(teacher|director|…)` 只讀 STAFF_UPDATES；排序權威＝`published_at` DESC（產生器強制 sort，不依賴 YAML／Markdown 順序）。
- 語言閘門 `scripts/lib/userFacingCopyGate.mjs`：偵測工程黑話／殘詞 → **fail**，不得再當自動改寫器。
- STAFF 檔 **禁止** `parent` audience；家長仍只走 R45／`PARENT_UPDATES.yml`。
- 預設 `importance: digest`（週摘要）；重大／需行動才 `major`／`action_required`。
- 操作指南：`docs/GUIDE_STAFF_UPDATES.md`。

### R86. Composable 的「鏡像測試」不算測試——沒 import 真正模組就攔不住 ReferenceError（P0 整頁空白）

**觸發情境**：2026-07-29 07:39 部署（#1409）後，主任回報課程管理頁**整頁空白**（外層 topbar／分校選單仍在，內容區完全沒渲染），所有角色、所有分校都一樣壞。

**根因**：`useCourseSessionsDisplay.js` 的 `return {…}` 物件末端引用了 `SESSION_NOT_OCCUPYING_QUOTA`——這個常數在重構時被搬進 `sessionOccurrenceFilter.js`（且未 `export`），但composable 自己的 return 忘了一起清掉。`CourseManagement.vue` 每次 `setup()` 呼叫 `useCourseSessionsDisplay()` 執行到這行就丟 `ReferenceError: SESSION_NOT_OCCUPYING_QUOTA is not defined`，整個 Vue 元件掛載中斷。

**CI 為何沒攔住**：唯一看似覆蓋這支 composable 的 `useCourseSessionsDisplay.occurrence.test.js`，檔頭其實寫明「Plain node assertions **mirroring** sessionOccurrenceFilter」——它是把過濾邏輯複製一份重新斷言，從未 `import` 真正的 `useCourseSessionsDisplay.js`。`vite build` 只打包不執行 composable 本體。兩者合計＝這個 return 陳述式從沒被任何自動化流程真的跑過一次。

**強制規則**：

- 任何 `use*.js` composable，只要有被頁面 `setup()` 直接呼叫，就必須有至少一個 vitest 測試**真的 import 並呼叫它**（如 `useRescheduleAndMakeup.test.js` 的寫法），斷言不拋錯 + 回傳 API 形狀正確。純邏輯的 node-assert 鏡像測試（`*.occurrence.test.js` 這類）只能當補充，不可視為對 composable 本體的覆蓋。
- Code review／PR 自查：composable 的 `return {…}` 物件裡每個識別字都要能在檔案內找到宣告或 import；`grep -n "^import\|^const\|^function"` 對照 return list 是最低成本的手動檢查。
- 已修復：刪除未使用、未宣告的殘留引用；新增 `useCourseSessionsDisplay.test.js`（CI `test:unit:cov` 既有 glob `src/composables/**/__tests__/**/*.test.js` 自動涵蓋，無需另外接線）。

### R87. `vite build` / dev-server 測試綠燈 ≠ 正式站真的部署到——`copy-to-backend.cjs` 是另一份獨立白名單

**觸發情境**：2026-07-29 #1512 把 Material Symbols Outlined 圖示字型從即時連 Google Fonts CDN 改為自架（新增 `frontend/public/fonts/material-symbols-outlined.woff2` + `@font-face` 指向 `/fonts/...`）。#1512／#1514／#1515 三個 PR 都用「真實 Vue 元件 + mocked API + 390/768/1440px 截圖」驗證過圖示正確渲染，`vite build` 也全綠，但正式站部署後全站圖示仍然顯示英文原名（`event`、`calendar_today`、`warning`…）。使用者反映「到處都是英文」才被發現——已經連續 3 次部署都受影響。

**根因**：正式站部署（`deploy.yml` SSH 到 Pi 後執行 `npm run deploy` = `vite build && node scripts/copy-to-backend.cjs`）用的是 `frontend/scripts/copy-to-backend.cjs` 這支獨立腳本，把 `dist_build/` 選擇性複製到 `backend/public/`——只複製寫死的 `ROOT_ASSETS` 清單（`manifest.json`／`logo.png`／icon 圖／`version.json`）+ `PUBLIC_DIRS`（原本只有 `['audio']`）+ `assets/`（hash 檔名的 JS/CSS）+ `index.html`。新增的 `fonts/` 目錄從未被列入任何白名單，`vite build` 本身雖然把 `frontend/public/` 完整複製進 `dist_build/`（含 `fonts/`），但這份完整輸出**從來沒有整個被部署**——只有白名單內的子集會被複製到 `backend/public/`。

**為何 CI／測試都沒攔住**：`vite.ui-foundation.config.js`（Playwright 視覺驗證用）與一般 `vite build` 都是直接讀 `frontend/public/` 或建置到 `dist_build/`，從來不經過 `copy-to-backend.cjs` 這個「部署時才跑」的第二層過濾。也就是說，全部驗證路徑測的都是「build 出來的東西對不對」，沒有一個測過「build 出來的東西是否真的會被複製到正式站 serve 的目錄」。這兩者是**兩份獨立的真相來源**，改 A 不代表 B 會同步更新。

**強制規則（未來在 `frontend/public/` 下新增任何目錄、且會被 CSS/JS 用絕對路徑 `url('/xxx/...')` 引用時必讀）**：

- 新增 `frontend/public/<newdir>/` 且有程式碼用絕對路徑引用（`@font-face` `src`、`<img src="/...">`、`fetch('/...')` 等）→ **同一個 PR 必須**把 `<newdir>` 加進 `frontend/scripts/copy-to-backend.cjs` 的 `PUBLIC_DIRS`。
- 驗證方式**不能只看** `vite build` 綠燈或 Playwright 截圖——這兩者都不經過部署腳本。必須額外執行 `node scripts/copy-to-backend.cjs`（對照已存在的 `dist_build/` 輸出）並確認 `backend/public/<newdir>/` 真的產生了對應檔案。
- merge 後除了看 CI／`deploy.yml` 綠燈，**必須額外對正式站該資源路徑 `curl` 確認回 200**（例如 `curl -I https://<prod-domain>/fonts/xxx.woff2`），不可只憑「deploy job 顯示成功」就當作驗證完成——deploy 綠燈只代表 SSH script 沒有 non-zero exit，不代表新資源真的到位。
- 一般原則：任何「本地/CI 建置產物」與「正式站實際部署產物」之間存在額外複製/過濾腳本的專案，該腳本本身就是一個需要被納入變更檢查清單的「白名單型設定檔」，跟 `.env`／`routes/api.php` 一樣，新增資源時要主動想到它可能漏收，而不是等使用者回報才發現。

**修復**：PR #1516（`PUBLIC_DIRS` 加入 `'fonts'`）。

**追加教訓（同日）**：PR #1516 merge、deploy 綠燈、正式站 `curl` 也回 200 之後，使用者手機 PWA 實測**仍然**顯示英文。根因是 `/fonts/material-symbols-outlined.woff2` 這個路徑**沒有內容雜湊**（不像 `assets/` 下所有 JS/CSS 都是 Vite 自動加雜湊檔名）——同一個 URL 在 bug 存在期間可能已被使用者裝置或 CDN 邊緣節點快取過失敗回應，事後把伺服器端修好，不保證所有已快取的用戶端會重新抓取；PWA「加到主畫面」在 iOS/Android 上更有獨立於一般瀏覽器分頁的快取分區，一般「強制重新整理」不保證清得到。**正確修法不是說服使用者清快取，是讓 URL 本身在內容改變時自動改變**：把字型檔搬進 `frontend/src/assets/fonts/`、`@font-face` 改用相對路徑讓 Vite 建置自動雜湊檔名（`material-symbols-outlined-D6tU34w1.woff2`），使其與其餘所有 bundle 資產享有同一套天然免疫快取的機制，同時也不再需要 `copy-to-backend.cjs` 的白名單特殊處理。**強制規則追加**：任何會被使用者裝置快取、且未來可能改內容的靜態資產（字型／圖示／PWA icon 等），一律透過 Vite 資產管線（`src/` 內以相對路徑 `import`／CSS `url()` 引用）取得自動內容雜湊，不要放進 `frontend/public/` 用固定檔名直通——`public/` 只留給内容本來就不會變、或本來就需要固定檔名的資源（`manifest.json`、`favicon` 等瀏覽器規範要求固定路徑者）。**修復**：同日追加 commit（`frontend/src/assets/fonts/`＋`styles.css` 相對路徑），已用真實 headless 瀏覽器驗證 `document.fonts` 狀態為 `loaded`。

**追加教訓 2（同日，同一個錯誤犯了兩次）**：squash-merge 的 PR（如 #1516）merge 後，continuation branch 若沒有先 `git fetch origin main && git checkout -B <branch> origin/main` 就繼續在同一個本機分支上疊加新 commit，本機分支祖先仍是「squash 前」的多筆原始 commit，跟 origin/main 上「squash 後」的單一 commit 內容相同但**物件不同**——GitHub 會回報 `mergeable_state: dirty`（假衝突：diff 內容其實一致，git 只是認不出兩段不同 commit 歷史代表同一份改動）。這件事在 #1517 開 PR 時發生過一次、已排除故障；**同一個 session 裡緊接著開 #1518 時又犯了一次**，因為在完成 #1517 的除錯後，沒有把「PR merge 後先重啟分支」這個動作變成每次的固定反射，而是回頭直接在舊的（尚未重啟的）本機分支上繼續加下一個 commit。**強制規則**：每次某個 PR 被 squash-merge、且還要在同一個 designated branch 上繼續做下一項工作時，**開新 commit 之前**一律先跑 `git fetch origin main && git checkout -B <branch> origin/main`（若有未推送的本機 commit，先 `git cherry-pick` 疊上去，勿用 `git merge` 硬併兩段歷史）。不是「遇到 dirty 才修」，是「每次 merge 後都預防性重啟」，才不會靠事後補救。

### R88. 「參考 star 的 repo」指真的去讀原始碼，`RFC_PLATFORM_OPTIMIZATION_FROM_STARS_2026.md` 只是索引不是替代品

**觸發情境**：2026-07-29 DirectorDashboard Wave A/B/C 全數依 `RFC_PLATFORM_OPTIMIZATION_FROM_STARS_2026.md` 的參考表（一行摘要，如「pacifio/ui → Dense ops UI：多表面、資訊密度」）產出設計方向，未實際讀過任何一個參考 repo 的原始碼。使用者事後追問兩次「你知道我叫你參考 star 的 repo 是什麼意思嗎」，才澄清：意思是真的去讀那些 repo 的實際內容，不是憑 RFC 文件裡別人（或前一個 agent）彙整過的摘要句子做設計判斷。

**根因**：RFC 文件本身承認自己是二手彙整（文件末 `Document control`：「Authors: Agent（Composer）依 Founder star 清單與既有 RFC/roadmap 彙整」），但先前的工作流程把它當一手事實使用——只讀一行「要學什麼」欄位就直接套用，從未驗證彙整是否準確、是否夠具體到能落地成程式碼層級的決策（例如圓角該用幾 px、一個 dashboard 該放幾個統計格）。

**強制規則**：當任務指示「參考 X repo」或指向一份「已彙整參考清單」的文件時：
- 不可只讀彙整文件的摘要句子就動手；必須 `git clone --depth 1`（大型 monorepo 用 `--filter=blob:none --sparse` 只 checkout 需要的子目錄，見下方指令）把 repo 的**真實原始碼／設計 token／規則文件**（如 `lessons.md`、`patterns.md`、實際 `.scss`／`.vue`／`.tsx` 元件）讀進來，用真實內容找出具體、可比對的落差（例如「這個 repo 的圓角只有 3/4/6/9999px 四種，我們寫死了 16px」），而不是憑一行摘要腦補設計判斷。
- GitHub MCP tool（`search_code`／`get_file_contents` 等）的 repo scope 綁定在本 session 的授權清單，讀取清單外的 repo（例如 star 清單裡的第三方開源專案）一律走 `git clone`（純 git 走 proxy 不受此限），不要嘗試用 `add_repo` 跨 owner 加（v1 不支援 cross-tier add）；直接打 `api.github.com` REST 也會被 proxy policy 擋（403），不是 GitHub 端問題。
- 找到的落差要能具體引用來源（哪個 repo、哪個檔案、哪一行規則），寫進 commit/PR/CHANGELOG，讓「參考了什麼」可稽核，而不是只寫「參考大公司軟體」這種無法驗證的空話。
- 讀完不代表照搬：仍要對照 `RULE_DESIGN_SYSTEM.md`／AllTrue 既有 token／既有互動語意（例如某清單是「點擊導頁」而非「打勾完成」，即使參考 repo 有現成的打勾 UI 也不該硬套，語意不同）。

**修復／落地案例**：Wave D（DirectorDashboard）——實際 clone `pacifio/ui`／`primer/css`／`carbon-design-system/carbon`（sparse）／`microsoft/fluentui`（sparse）／`vbenjs/vue-vben-admin`，用其中 `pacifio/ui` 的 `kitchen-sink/app/patterns/dashboard/page.tsx`（4 metrics max 規則）與 `skills/atlas/references/lessons.md` #16（圓角只能 3/4/6/9999px）兩項具體、可引用的真實規則，對照出 `progress-board` 6 格過多、`AtCard`/`AtMetric` 圓角寫死 12px 未接 AllTrue 自己既有 token 兩項落差並修正。

### R59. 扣堂改分鐘制權威後，`RemainingSessions` 是 ROUND_HALF_UP 衍生顯示值（#613）

**觸發情境**：2026-05-31 #613 落地「補課非標準時長按實際分鐘扣堂」；2026-07-19 延伸涵蓋**加長**補課（契約 2h、補課 3h）。

**根因 / 設計**：扣堂權威單位由「堂數」改為「分鐘」。權威來源＝`StudentClass.PurchasedMinutes/RemainingMinutes` + `session_deduction_ledger.minutes`；`RemainingSessions` 變成由 `ROUND_HALF_UP(RemainingMinutes / perSessionMinutes)` 衍生的整數**顯示值**（整數運算、無浮點）。

**強制規則（改扣堂/堂數顯示前必讀）**：
- **唯一權威扣堂路徑**＝`SessionDeductionService::recomputeCounters()`；分鐘換算 chokepoint＝`deductOnAttendance` → `resolvePartialMakeupMinutes`（`type=extra` 且時長 ≠ perSession 記實際分鐘；剛好完整時長傳 `null`＝整堂）。
- **補課非標準時長**（短於**或長於**契約每堂分鐘）走實際分鐘；正常課堂一律整堂。**禁止**擴大到非 `extra`；**禁止** clamp 回 perSession。
- 任何讀取端**不可**用 count-based observed 值覆寫 fractional 餘額；精確值用 `remaining_minutes`。
- `reverseForSession` 必須沖回對應 deduct 的 `minutes`。
- ⚠️ `PackageDeductionService` 尚未分鐘感知（TD-059）。預付包堂加長補課扣 entitlement 分鐘 ≠ 自動加收現金（Charge 見 §R76）。

**測試**：`SessionDeductionMinutesEngineTest`、`PartialMakeupDeductionTest`（90／120／180 分）。

---

### R76. 單堂改時段費用：前後端必須同一套 session／hour 規則（F7）

- **觸發情境**：2026-07-19 主任／老師回報「兩小時收費、排三小時，費用與總時數不會自動扣」；調查發現畫面文案與後端寫入不一致，讓人以為系統壞了。
- **根因**：`SessionEditModal`／SmartCalendar 已依「按堂固定／按時比例」說明，但 `ClassSessionController::syncSessionChargeForTimeChange` 曾對 session mode 仍用 `Rate × actual/standard` 縮放，且 hour mode 文案寫「不會改總費用」卻會改 `StudentClass.Charge`。
- **強制規則**：
  - session mode：`session_charge = round(Rate)`；時段調整**不得**因時長產生新 Charge delta（僅修正舊偏差）。
  - hour mode：`session_charge = round(Rate × actual_minutes / 60)`；儲存後**必須**把 delta 寫回課程總費用；前端文案不得寫「僅供參考／不改總費用」。
  - 改計費分支時前後端與 `ClassSessionChargeTest` 必須同 PR 對齊；詳見 Archive §單堂費用固定。
- **與扣堂分工**：預付包堂 entitlement 的加長／縮短補課扣分鐘見 §R59；本條只管 Charge，不可把「扣堂分鐘」誤當「立刻加收現金」。

---

### R77. 請假順延必須依目標星期對齊契約時段（多星期不同鐘點）

- **觸發情境**：2026-07-19 老師回報「三 5–7、六 10–12，請了三，六會變成 5–7」。
- **根因（Fact）**：`CourseLeaveCascadeService::shiftAndAppendAfterLeave` 只改 `SessionDate`、保留原列 `StartTime`/`EndTime`。多星期契約日期往前推後，異星期鐘點會落到錯誤日期。
- **強制規則**：順延／撤銷／append 後必須對齊目標日契約 `week*/time*`；`IsContractException=1` 不重寫；歷史漂移用 `repair:leave-cascade-slot-times`（預設 dry-run）。
- **2026-07-19 Founder**：禁止對 dry-run 96 筆直接 `--execute --force`；改主任可審核 CSV／明確 `--session-ids` 執行。Closeout：`docs/incidents/leave-cascade-slot-times-closeout-2026-07-19.md`。
- **測試必補**：`LeaveCascadeMultiWeekdaySlotTimesTest`、`RepairLeaveCascadeSlotTimesTest`。

---

### R78. 評量 nightly backfill 不可把「作廢列」當成已有有效評量（#1078）

- **觸發情境**：2026-07-19 business digest `dq_attended_no_LR=3`（enforced #1078／#1080 應為 0）。老師無法填已上課堂次的評量。
- **根因（Fact）**：digest／`bugs:verify-reproductions` 只認 `VoidedAt IS NULL`；`LearningRecordBackfillService::createPendingForSession` 卻用任意列 `exists()` 即跳過。請假 cascade 作廢後若堂次已回到 attended，unique(`ClassSessionID`) 禁止新建，nightly job 永遠清不掉缺口；`ensurePastRecords` 已有 un-void，兩路徑漂移。
- **強制規則**：create／restore 必須共用 `LearningRecordBackfillService`；已上（attended／completed／late／absent）且僅有作廢 LR → **in-place restore**；leave 堂次作廢列不得 resurrect；禁止為同一 ClassSession 再 INSERT。
- **測試必補**：`LearningRecordBackfillMissingTest::test_backfill_restores_voided_lr_for_past_attended_session`。

### R79. 前端不得在後端 API contract 未進 main 前改打新路徑（收據 404 / #1197）

- **觸發情境**：2026-07-21 帳務中心點「收據」一律「請求失敗（404）」。`ReceiptModal` 打 `/api/v1/receipts*` 與 campus `legal-info`，但 production 僅有 `GET /api/v1/payment-reports/{id}/receipt`。
- **根因**：PR #1197（commit `f73177a9`）以「P0 前端」單獨 merge，假設 Receipt domain 後端已存在；後端從未進 main → 全分校收據入口路由層 404。同批 `BatchInvoiceModal` / `OverdueBucketsPanel` 亦為孤兒前端。
- **強制規則**：
  - 前端改呼叫新 API 的 PR，必須與後端 route／controller／migration **同 PR 或後端已在 main**；禁止「前端先上、後端後補」。
  - 收據查看唯一合法路徑（hotfix 後）：`GET /api/v1/payment-reports/{reportId}/receipt`；response 只可使用真實欄位，經 adapter 映射 UI，禁止偽造法定欄位或半套 stub route。
  - 錯誤必須分類：403 權限／跨校、404 找不到核帳、422 尚未核帳；不得一律「請求失敗（status）」。
  - 完整 Receipt domain（immutable snapshot／PDF／void／legal-info）屬 T3，另開 PLAN／ARCH／SEC，未經批准不得實作。
- **測試必補**：`ReceiptModal.test.js` endpoint contract（禁止 `/api/v1/receipts*`）、success／403／404／422、**invalid reportId fail-fast（禁止 NaN URL）**、切換 reportId 不殘留上一筆；`TuitionCollectionReceiptEntry.test.js` 確認入口只傳 `report-id`。**禁止**用 `describe.skip` 留置已廢棄的 `/receipts` 規格測試——應刪除或移入 T3 PLAN 文件。
- **產品語意**：T3 完成前 UI 標題用「電子收據／收款收據」，勿用「正式收據」暗示 legal snapshot／PDF／void 已完備。

---

### R71. 調課不可由前端串三次寫入並吞掉最後一步錯誤

- **觸發情境**：2026-07-18 王品方 7/14 未上課，但課程管理顯示「已上」且待點名／評量仍存在；應改至 7/18 13:00–15:00。畫面可同時出現原日已上、另一時段取消與待處理，無法判斷是系統或人工作業。
- **根因**：三個前端入口先後寫 `rescheduled`、`scheduled`，再呼叫 `reschedule-session`；最後一步曾 `.catch(() => {})`，即使 ClassSession 沒移動仍關 modal 並顯示成功。這是 F1 狀態收尾缺口，並與 R13/R43/R47/R52 的多來源漂移同族。
- **強制規則**：調課必須由單一後端 domain service 在同一交易更新 schedule chain、ClassSession 與衍生評量／點名／扣堂；前端不得直寫兩張 schedule 後自行補償。只有 API 明確回 `committed=true` 才能顯示成功。
- **定位規則**：必傳 `old_date + old_start_time`；同課程同日多堂時不可 date-only 猜第一筆。相同請求重試必須冪等，不可新增第二個 anchor/target。
- **稽核規則**：判斷誤點或系統問題時，先看 `StudentSingIn.RecordedByUserID/recorded_by_name/MDT`；有人員即人工登記，NULL 則屬系統／刷卡路徑，再對照 `schedule_audit_logs.operator_id + old_data/new_data`。
- **測試必補**：成功時四類資料一起提交；晚期 slot conflict 時 schedules 必須為 0 且 ClassSession 原位；相同 payload retry 仍只有一組；前端收到 2xx 但無 `committed=true` 仍視為失敗。

### R72. 已取消 ClassSession 不得讓 schedules.scheduled 例外繼續佔用代課老師

- **觸發情境**：2026-07-18 in-app #203／GitHub #1296：主任代課時挑選器顯示老師「已滿／衝堂」，但該時段僅有 `schedules.status=scheduled` 例外 row，對應 ClassSession 已全部 `cancelled`。
- **根因**：取消／結算／去重等寫路徑取消 ClassSession 時未收尾 linked schedules 例外（F1）。`SubstituteService`／`ScheduleGuardService` 無條件信任 `schedules.status=scheduled`，假佔用永遠卡住代課。
- **強制規則**：讀取老師佔用時，若同 `student_course_id + 日期 + HH:MM` 存在 ClassSession 且全部為 cancelled／leave／leave_adjusted／excused，該 scheduled 例外必須剔除。無 ClassSession 證據的 row（補課 R13）仍為真實佔用。
- **寫入側後續**：永久解法仍需 cancel 路徑 cascade 關閉 schedules 例外；讀側過濾不可省略，否則歷史殘留會再炸。
- **測試必補**：stale 例外 → availability 不 busy 且 substitute 成功；active ClassSession 例外仍 busy；無 ClassSession 的 makeup schedule 仍 busy。

### R73. 跨老師拖曳的 domain intent 優先於日期／時間，寫入必須 atomic 或可證明補償（in-app #201/#202）

- **觸發情境**：主任把歷史單堂從原老師 17:30 拖到代課老師 18:00，畫面曾走一般調課而非代課；重試後留下多筆 `rescheduled` marker、NULL anchor 與取消目的堂，但實際授課老師仍未完整轉移。
- **根因**：跨老師判斷錯綁「同日＋同整點」UI 條件；compatibility QueryBuilder 的 `.select()` 把 mutation 重設成 GET；前端又把 Laravel direct-object response 當 `{ data }` 讀取而丟失 anchor。Legacy 調課先後寫兩筆 `schedules` 再移動 `ClassSession`，卻未檢查最後 response，造成部分成功與 stale retry 放大。
- **強制規則**：
  - calendar drop 只要 `target_teacher_id != effective_teacher_id`，一律由 atomic substitute workflow 擁有；日期／時間是否改變只是同一 operation 的選填參數，不可降級成 plain reschedule。
  - mutation 後需要回傳 row 時，adapter 必須保留原 HTTP method，並把 Laravel direct object 正規化成一致的 `data`；`.insert().select().single()`／`.maybeSingle()` 必須有 contract test。
  - `rescheduled` marker 的 idempotency key 至少為 `student_course_id + schedule_date + start_time`，以 parent course row lock 序列化；清除舊重複 anchor 前必須先把 descendant `original_schedule_id` 重掛到保留列。
  - 優先使用單一 DB transaction。若仍有 legacy 兩階段 flow，第一階段不可先物化 cross-date destination `ClassSession`；第二階段 422 只補償本次 `write_disposition=created` 的 row，network/5xx 結果不確定時不得猜測刪除；缺 token 必須零寫入 fail-closed。
  - 同一課程同日多堂時，查找與移動必須帶 `old_start_time`，不可 date-only 猜堂次。
- **測試必補**：跨老師＋改時間 routing、歷史同日 correction、mutation-return adapter、重試 idempotency＋descendant re-anchor、cross-date 不預建 ghost ClassSession、最後 move 422 精準補償，以及 production build。

### R74. 代課衝突檢查必須排除「同一學生」既有佔用（續約雙軌自撞）

- **觸發情境**：2026-07-18 in-app #203（R72 後仍回報）：沈宇璿換代課給鄭翔祐，7/18 13:00 顯示衝堂。Production 同時存在舊約 cancelled ClassSession + 續約 scheduled 例外（schedules #6034/#6079），代課老師已是續約列的 `teacher_id`。
- **根因**：`ScheduleGuardService` 已支援 `exclude_student_id`，但代課 POST／availability 未傳入；availability 亦不接受該參數。挑選器把「同一學生」續約佔用當成他人衝堂。
- **強制規則**：
  - 為學生 S 指派代課時，availability 與 capacity／跨分校檢查必須 `exclude_student_id=S`（或等價排除 S 的 schedules／ClassSession）。
  - 其他學生的佔用不得被排除。
  - R72 stale 過濾仍必須保留；本規則解決「非 stale、但是同一學生」的假衝堂。
- **測試必補**：`SameStudentExcludeBusyTest` — exclude 後 13:00 不 busy；substitute 成功；其他學生佔用仍 busy。

### R75. 請假順延 vacated 預覽（in-app #204）— SUPERSEDED 2026-07-26 by §R82

- 舊一般 leave SHIFT/vacated week 語意已廢止；vacated 僅剩 explicit pause。
- Preview/apply 仍須同源。歷史 vacated → PR2 `repair:leave-vacated-weeks`。

### R82. 堂數制一般請假保留未來日期、只補尾堂（Founder Decision 2026-07-26）

- 一般 leave：標 leave、不移未來日期、尾端最多 append 一堂；`vacated=[]`。
- Explicit pause：`applyExplicitCoursePauseShift` / preview `policy=SHIFT_FUTURE_DATES_APPEND_TAIL`。
- 歷史 silent vacated week：`php artisan repair:leave-vacated-weeks`（預設 dry-run；runbook `docs/runbooks/REPAIR_LEAVE_VACATED_WEEKS.md`）。
- 測試：`test_count_based_leave_keeps_*`；`LeaveKeepDatesAppendTailTest`（repair dry-run／apply idempotent）。
- **產品規格教訓**：測試與文件只能證明符合既有規格，不能證明規格正確；營運一致反對時必須升級 Founder Decision，不可用舊測試關閉問題。

### R83. 原子調課必須標記 IsContractException（否則 realign 拉回契約時段）

- **觸發情境**：智慧行事曆／課程管理「調課」把單堂從契約時段（例週五 20:00）改到非契約時段（15:00），畫面曾正確；重整或課程「編輯→儲存／同步堂次偏移」後又回到 20:00。截圖常見同日同時出現 15:00 例外卡與 20:00 實體堂。
- **根因（F1 / #556 缺口）**：`PATCH class-sessions` 改時間會設 `IsContractException=1`，但 `RescheduleSessionService`（`ensure_schedule_exception` 原子調課）只移動 `ClassSession` 時段、**未**標記例外 → `schedule_drift` 誤判 → `force_partial_rebuild`／`syncFutureScheduledSessionTimes` 把堂次 realign 回 `StudentClass.week/time`。
- **強制規則**：任何把 occurrence 移出契約 weekday+clock 的寫入路徑（含 `reschedule-session`）必須呼叫同一套 contract matcher 設／清 `IsContractException`；改回契約時段則清 0。禁止只靠前端 schedules 例外撐顯示。
- **資料修復**：已遭 realign 的個案需對照 `schedule_audit_logs`／`schedules`（rescheduled→scheduled chain）確認目標時段後，再以批准的 repair 把 `ClassSession` 移回並設 flag；不可盲目整批。
- **測試必補**：`RescheduleMarksContractExceptionTest` — 原子同日 20:00→15:00 後 flag=1，且 `force_partial_rebuild` 後仍為 15:00。
- **後續結構性修復見 §R84**：本條的「強制規則」當時只能靠每個寫入路徑自己記得呼叫 matcher，事後 grep 全庫發現至少 3 個既有重複實作（`ClassSessionController`／`StudentClassController` 加課／`RescheduleSessionService`）與 2 個完全沒接的缺口（`SubstituteController` 代課復原、`ClassSessionContractReflowService`）。R84 把這個不變量搬進 `ClassSessionObserver`，不再依賴人（或 AI）記得。

### R84. IsContractException 不再靠呼叫者記得——搬進 ClassSessionObserver 結構性保證（R83 根治）

- **觸發情境**：R83 修完 `reschedule-session` 這一個路徑後，複查全庫發現同一個 bug class（「移動 ClassSession 時間卻忘記同步 IsContractException」）在寫這個 PR 之前已經以 3 種略有差異的複製貼上形式存在（PATCH class-sessions、加課 add-session、原子調課），而且另外兩處會動到 ClassSession 時間的寫入路徑（`SubstituteController` 代課撤銷還原時間、`ClassSessionContractReflowService::move()` 本身）完全沒設這個 flag，只是「目前唯一呼叫者剛好有先篩掉」才沒出事——換句話說，下一個新寫入路徑（含 AI 新增功能時）只要忘記呼叫，同一個症狀會用新的樣貌回來，而且舊測試不會發現，因為舊測試只覆蓋既有路徑。
- **根因**：這個不變量（「ClassSession 時間吻不吻合契約」）被當成「每個呼叫者自己記得算」，而不是「model 層自動保證」——衍生欄位（derived column）的一致性不該依賴呼叫端紀律。
- **修復**：把計算搬進 `ClassSessionObserver::saving()`（`ClassSession::observe()` 已註冊、原本就用來寫 `ScheduleAuditLog`）。任何 `ClassSession->save()`，只要 `SessionDate/StartTime/EndTime` 有變動、且該次寫入沒有明確指定 `IsContractException`，就自動用 `ContractScheduleMatcher::applyExceptionFlag()` 重算並覆蓋；若呼叫者在同一次寫入明確指定了 `IsContractException`（例如 `ExceptionWorkflowController` 確認候補時段時強制標記例外），尊重明確意圖、不覆蓋。
- **同時刪除的重複實作**：`ClassSessionController::syncContractExceptionFlag/sessionMatchesContract`、`StudentClassController::sessionMatchesContract`（含 add-session 的手動重算區塊）、`RescheduleSessionService` 對 `ContractScheduleMatcher::syncExceptionFlag` 的顯式呼叫——全部改由 Observer 自動處理，`ContractScheduleMatcher` 只剩一份 `matchesContract()`／`applyExceptionFlag()`。
- **強制規則**：未來任何會改到 `ClassSession.SessionDate/StartTime/EndTime` 的新程式碼，**不需要、也不應該**自己呼叫 contract matcher——只要走 Eloquent `->save()`，Observer 會自動處理。唯一要小心的是若用 `DB::table('ClassSession')->update(...)` 繞過 Eloquent（例如某些 repair command 的批次更新），Observer 不會觸發，仍需手動確認契約吻合狀態。
- **測試**：`ClassSessionObserverContractExceptionTest` —— 直接對 model 做 plain attribute assignment + save()（刻意不經過任何 controller/service），驗證 flag 自動設起/清除；驗證明確指定值不被覆蓋；驗證非時間欄位變動不誤觸發。既有 `StudentClassScheduleDriftExceptionTest`／`RescheduleMarksContractExceptionTest` 全數維持通過（行為不變，只是計算的地方換了）。

### R80. 排課摘要「補登已上（堂）」不可用 dates.length

- **觸發情境**：新建課程 modal 同日兩個固定時段；補登 3 天其中兩天雙時段時，摘要顯示 3、實際應為 5；未排／總堂數仍正確。
- **根因**：摘要用 `confirmedDates.length`（天數），內部 `manualSessionCount`／submit 已按時段展開。
- **防再犯**：摘要與 `session_plan` 必須共用 `schedulerSessionExpand`；顯示用「X 堂（Y 天）」；測試鎖定 summary count = confirmed plan rows；後端 batch 雙時段 confirmed 建 2 筆 ClassSession。
- **測試**：`frontend/src/lib/schedulerSessionExpand.test.js`；`ClassSessionBatchApiTest::test_confirmed_session_plan_dual_slot_same_day_creates_two_class_sessions`。

### R81. 家長請假不可雙寫 Notifications（Action Inbox B-lite + D）

- **強制**：請假真相=`exception_workflows`；唯讀 ActionInbox；禁雙寫 Notification。Badge=`badge_total`；紅燈僅 `urgent_total`；空 campus_ids 對非 super_admin **fail-closed**。 Fail-soft 僅同 authorization scope。
- **測試**：`ActionInboxApiTest`（零校區/未授權 403、pagination 51+、DTO、結案消失、老師 403、count）。
- **決策**：`.cursor/plans/action-inbox-b-lite-d_2026-07-22.md`

---

## 模組對照索引（改特定模組前讀 Archive 對應條目）

> 改下列模組前，**先回本檔 §復發家族** 認領對應 F1～F6（狀態收尾/月結續期/排課生成/共用堂數/行事曆合併/輸入邊界），再讀以下細項。

| 模組 | 必讀條目（在 Archive） |
|------|----------|
| 堂數 / 扣堂 | §2026-04-17 繳費日期、§單堂費用固定、**§R59（分鐘制權威：RemainingSessions 為 ROUND_HALF_UP 衍生值，讀取端勿用 count 覆寫 fractional）**、§R70（對帳面板唯讀＋真實 API contract test）、**§R76（單堂改時段費用前後端必須一致）** |
| 繳費 / 學收 | §繳費狀態 paid_at、§歷史課程漏算、§催繳名單六狀態、§幽靈課程、§R30（帳務入口共用 AR ledger）、**§R76（session／hour 費用文案與 Charge 寫入）**、**§R79（收據前端不得超前後端 contract；合法路徑=payment-reports/{id}/receipt）**、**#1827／RFC_REPORTED_PAID_ACCOUNTING_SPLIT（行政已回報 ≠ Paid；收據僅 confirm 後）** |
| 薪資 / 併堂 | §兼職薪資 concurrency、§同層級併堂 v1.4、§契約時長為準 |
| 代課 / 調課 | §代課Undo通知、§合併Undo還原時間、§雙層防護重複行、§atomic transaction、§R13（補課 schedule 不建 ClassSession）、§R39（代課評量權限需匹配時段）、§R43（調課目標 scheduled 例外以 anchor 去重）、§R44（代課顯示不可讓原老師 stale row 搶贏）、§R46（主任評量列表授課老師須與 effective 代課一致）、§R48（代課點名權限必須以時段級 effective teacher 為準）、§R52（代課 scheduled 例外不可缺 original_schedule_id anchor）、§R71（調課單一交易＋前端 committed gate）、§R83（原子調課必須標記 IsContractException）、**§R84（IsContractException 搬進 ClassSessionObserver 結構性保證）**、§R72（cancelled ClassSession 不得讓 scheduled 例外佔用代課老師）、§R73（跨老師 gesture 必走 atomic substitute；legacy 兩階段精準補償）、§R74（代課衝突排除同一學生續約佔用） |
| 請假 / 順延 | §R29、**§R82（KEEP dates+append）**、§R75（SUPERSEDED）、§R77、§R81、**§R109（結案不可吃掉請假順延尾堂）** |
| 評量 / 家長回饋 | §同天多堂課 buildEvents、§請假後不填評量、§R17（ownership 先於狀態判斷）、§R19（mark-read 不可更新 updated_at）、§R32（停用課程已上課評量不可消失）、§R39（代課評量權限需匹配時段）、§R46（主任評量列表授課老師須與 effective 代課一致）、§R65（新增 session 狀態值必須同步全部消費端；leave 家族用集合判斷）、**§R78（nightly backfill 須 in-place restore 作廢評量，不可把 voided 當已有）**、**§R110（課程管理已上堂數須與日期晶片同源）**、**§R112（預排不可佔第 N 堂）** |
| 家長入口 UI / `releaseNotes` | §R10、§R11、§R18、§R38、§R45（家長卡僅 `PARENT_UPDATES.yml` 顯式投影 + `sync-release-notes`）、**§R85（教職員卡僅 `STAFF_UPDATES.yml`；CHANGELOG 不得自動發布）** |
| 課表回報 | §2026-04-17 回報系統（14 條禁止項） |
| 排課 | §start_time 格式、§智慧排課誤標取消、§R25（請假優先於 scheduled 例外）、§R29（請假不可 fallback 只寫 schedules）、§R43（調課目標 scheduled 例外以 anchor 去重）、§R44（代課顯示不可讓原老師 stale row 搶贏）、§R47（rescheduled 幽靈不可蓋掉同日 ClassSession）、§R49（同學生同時段去重不可用 StudentClassID 當唯一 key）、§R50（行事曆載入不可 REST 成功後再跑 fallback）、§R69（bulk reflow 先 snapshot schedule IDs，禁止 mutable natural key 連鎖更新）、§R71（mutation contract／slot idempotency／兩階段補償）、**§R80（排課摘要補登堂數≠天數；須與 session_plan 同源 expand）**、§R83（調課後 IsContractException 防 realign）、**§R84（IsContractException 結構性保證，不再靠呼叫者記得）** |
| 出缺勤 / 分校隔離 | §SEC-001、§分校隔離後端強制、§R12（查詢日期寫死今天）、§R14（submitQuickAttend 缺 StudentID）、§R15（出勤頁預設只顯示今天，歷史到班紀錄不可見）、§R16（`script setup` const TDZ 初始化順序 → 整頁空白）、**§R86（composable return 引用未宣告識別字 → ReferenceError 整頁空白；鏡像測試攔不住）**、§R33（老師每分校 RFID 優先）、§R36（個別資料有課但老師今日名單缺漏）、§R40（點名扣堂不可只用 ClassSessionID 防重）、§R41（補請假不可只用課程+日期找堂次）、§R42（行事曆堂次顯示老師不可被舊評量老師覆蓋）、§R48（代課點名權限必須以時段級 effective teacher 為準）、§R71（請假寫入即封閉 interval；禁止留待隔夜 repair）、**§R107（projected 堂次必須帶 branch_id；教師首頁禁 Branch #N）**|
| 月結制 / 加購 / 多科固定時段 | §b3 inactive 歷史、§b4 加購分流、§R21（堂數制加購是新批次）、§R22（月結詳情不可只依賴 ClassSession）、§R23（推算日期不可成為 dead-end chip）、§R24（多科固定時段優先走一般課程）、§R26（月結續報與堂數額度不可混在同一語意）、§R38（家長端繳費提醒不可套主任續課提醒） |
| routes/api.php | §AI 靜默回退路由（改前必讀完整檔案 + route:list） |
| 備份 / nightly | §nightly 覆蓋修正、§備份還原演練、§R34（備份新鮮度不可只看 mtime）、§R71（repair 與 producer prevention 分離；同日全日期 health aggregate） |
| Bug 回報 / 附件存檔 | §R11 storage symlink（Archive）、§R51（分診前必查 attachments + reporter 歷史 + 跨分校）、§R53（上線後必回 in-app）、`docs/CHAT_BUG_SYSTEM.md` §3.6–§3.7、**§R108（utf8mb3 姓名 LIKE 禁 4-byte）**、**§R111（課程備註長度 TEXT+422，禁 SQL 500）** |
| Git / PR 工作流 | §R58（禁止 assume-unchanged 藏檔）、`scripts/git-index-audit.sh`、Epic #535 Phase 0、**§R87 追加教訓 2（squash-merge 後繼續在同一 designated branch 開下一個 commit 前，一律先 `git fetch + checkout -B <branch> origin/main` 重啟，勿等 `mergeable_state: dirty` 才修）** |
| Migration / schema drift | §R63（未合併分支的 migration 禁上 production；drift 修復＝port 回 main＋drift 測試） |
| 前端 UI 參考 star repo / RFC 落地 | **§R88（「參考 star 的 repo」＝真的 `git clone` 讀原始碼，`RFC_PLATFORM_OPTIMIZATION_FROM_STARS_2026.md` 的一行摘要只是索引不是替代品；落差要能具體引用來源檔案/規則）** |
| 部署 pipeline | §R62（deploy 必須 fetch fail-fast + reset 到 CI `head_sha` 並校驗 HEAD；禁止 `reset --hard origin/main` 靠 stale tracking ref 靜默出貨舊版；Pi repo config 出現 `http.sslbackend=schannel` = 已被 Windows 工具污染，先 unset）、§R67（SSH script 關鍵步驟失敗必須標紅；migration 失敗不得吞成綠燈）、§R68（排程任務上線必須驗證 schedule:run driver 存在；證據=執行 log 而非 schedule:list）、**§R87（`copy-to-backend.cjs` 是獨立白名單；新增 `frontend/public/` 子目錄必須同步加進 `PUBLIC_DIRS`，且驗證需實際跑複製腳本＋正式站 curl，不能只看 `vite build`／dev-server 截圖）**、**§R106（具名 DB principal 輪替時 username/password 必須同源，禁止 production `.env` 密碼配寫死帳號）** |

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
### R89. Billing read model 與批次 mutation 必須使用同一個 explicit selection contract

- **事件**：2026-08-01 in-app #212/#213。帳單列表沿用 `Invoice.TotalAmount`，課堂頁卻依 `ClassSession` 顯示實際堂次；批次核准則遺漏 `ids`，後端依篩選條件核准整批資料。
- **根因**：同一個業務動作由多個 controller/UI 自行推導數字或範圍，缺少 canonical read DTO 與 fail-closed mutation contract。
- **規則**：月結帳單讀取必須帶 `billing_period`，回傳 stored/computed/source/discrepancy；批次 mutation 必須要求 distinct explicit IDs，server-side 完整比對後才進 transaction。
- **驗收**：所有 batch response 的實際變更數量必須與 request IDs 相等；帳單畫面必須能呈現「堂數 × 單價 = 金額」及差異原因；production evidence 必須保留 API 回應與 UI 證據。
- **參考**：`docs/incidents/2026-08-01-billing-and-batch-approval.md`、`docs/PRICING_CONTRACT.md`、`docs/ADR_003_layering_and_controller_db_ban.md`。

### R90. Billing 修正必須逐一驗證所有 production read surfaces

- **事件**：#213 的課程管理明細已顯示 NT$7,500，但正式帳務中心仍顯示歷史 `Invoice.TotalAmount = NT$6,000`。
- **根因**：修正只覆蓋一個 controller/UI surface；帳務中心、繳費單、發票列表與對帳查詢仍各自讀取不同來源。
- **強制規則**：任何 billing 修正必須列出 route/API/UI surface matrix，所有 surface 共用同一個 read model；驗收必須逐一以同一案例驗證，不能以單一畫面或單一測試代表全站。
- **停止條件**：任一 surface 的 stored/computed/source/discrepancy 欄位缺失、金額不一致或 production smoke 未覆蓋，禁止標記 resolved 或上線結案。
### R91. 行事曆必須區分載入、錯誤與空資料，並在行動版驗證實際可見的案件明細（2026-08-01）

- 行事曆 API 失敗後若只繼續渲染「目前沒有資料」，使用者會把系統錯誤誤判成真的空課表；資料載入狀態、API error、empty state 必須是互斥且可重新整理的 UI 狀態。
- 任何日期型工作台都要在第一眼顯示目前檢視、可見日期範圍與回到今天；只顯示「週／日」而不顯示實際日期，會讓使用者無法確認自己正在處理哪一週。
- Playwright 的 mobile 驗收不可只用全頁第一個 selector：桌面表格與行動卡片可能同時存在於 DOM，測試必須依 viewport scope 到實際可見的 `.sdp-mcard`／明細，否則會出現測試看似點了 CTA 卻驗證到隱藏桌面內容的假綠燈。
- 狀態型案件的主要動作要使用下一步動詞（接手處理、繼續處理、查看處理結果），不是所有狀態都顯示模糊的「展開」；動作標籤、`aria-expanded`、tabpanel 關係與實際可見區塊必須同時維護。

### R92. 多筆內容預覽必須是 read-only projection，且 row preview 要跟同一筆資料綁定（2026-08-01）

- 老師要快速比較多堂學習評量時，不能要求逐筆開啟編輯 modal；列表應先提供可掃讀的內容 projection，編輯、核准與退回仍維持原本的高風險操作邊界。
- 預覽欄位必須由純函式／presentational component 統一整理，不能在 table、card 各自複製欄位映射；空內容要明確顯示尚未填寫，不能以空白或錯誤狀態代替。
- Vue `v-for` 的同筆預覽 row 必須使用 `<template v-for="record">` 同時包住主 row 與 preview row；把 sibling 放在 loop 外會在桌面 render 讀到 undefined，手機因走另一個 render branch 可能掩蓋此錯誤。
- 驗收必須包含 390／412／768／1280／1440px，並同時檢查真正可見的 card/table branch、內容摘要、開關狀態、`scrollWidth <= clientWidth` 與 browser page error；只驗 API 200 或單一 selector 不足以證明 preview 可用。

### R93. 專用 UI foundation spec 不得混入 production smoke（2026-08-02）

- 使用 pilot mount／mock API 的頁面證據測試，必須由專用 Playwright config 明確收斂；default production smoke config 必須 `testIgnore`，避免在沒有 fixture server 時把測試誤當 production smoke 執行。
- 新增任何 foundation spec 後，必須同時驗證專用 config 會執行它、default config 不會執行它，並在 CI 的 UI Smoke 與 Vite Frontend Build gate 中各自確認結果。

---

### R94. `AlertController::computePaymentStatus()` 只認 `StudentClass.Paid`，漏了帳單足額收款（F7 新成員，2026-08-06）

- 主任回報：一名學生的堂數制課程已用帳單收款紀錄結清（`charge === paid_amount`、`outstanding = 0`），課程管理頁面正確顯示「已繳費」，帳務中心卻仍列為「未繳費」——同一課程、同一頁面群組，兩套真相互相矛盾，正是 **F7「繳費金額/狀態雙真相」** 家族的又一個成員。
- 根因：`computePaymentStatus()` 判斷 `$isPaid` 時只看 `StudentClass.Paid` 這個欄位；`StudentClassController` 的對應邏輯早就是「`Paid=1` **或** 有記錄帳單收款」，但這條 OR 規則從未同步搬進 `AlertController`。
- **修法**：`$isPaid = Paid=1 或 (charge > 0 且 paid_amount >= charge)`。刻意用「足額」而非「有任一筆收款」判斷——後者會把只付一部分的 `partial` 狀態也誤判為已繳，見 `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` §「堂數制單科課程的 payment_status 未計入帳單收款」。
- **測試**：`TuitionAlertsApiTest::test_payment_status_paid_when_invoice_fully_paid_without_paid_flag`、`test_payment_status_renew_needed_not_unpaid_when_invoice_fully_paid_and_zero_remaining`（revert 後兩者皆 fail）。
- **防再犯**：任何新增「這筆是否已繳費」的判斷邏輯，一律先查 F7 家族既有的 `Paid OR 足額收款` 規則是否已在別處實作，不要重新發明；`AlertController::tuition` 屬 `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` 明文列管的檔案，改動前需先取得產品方同意（本次已取得）。
- **後續盤點**：修好後掃了一次「已繳費」判斷在全 `backend/app` 被重寫幾次，結果是至少 8 個檔案、4 種互不相同的變體（見 R95）；`AlertController.php` 內部自己就重複了兩次（`computePaymentStatus()` 與 `computePackageCountPaymentStatus()`），已在同一 PR 抽成單一私有方法 `isFullyPaid()`。

### R95. 「已繳費」判斷全專案盤點：至少 8 個檔案、4 種變體，沒有任何集中實作（2026-08-06）

- 承 R94 修復後的盤點：`backend/app/Models/StudentClass.php`、`Invoice.php` 都沒有 `isPaid()`／`isFullyPaid()` 這類集中存取器；`StudentClassController`（`Paid==1` 或任一筆收款）、`AlertController` 內部另兩處（`mapCountModeAlert`／`monthlyAlertRow`，僅 `Paid==1`——但這兩處是刻意保留給列入提醒條件用，依規則不可與顯示用 `payment_status` 混改）、`NotificationSyncService`、`DunningService`（明文凍結）、`PaymentReportController`、`ParentPortalController`（同檔案內三種寫法）、`NotificationController`、`AccountingController`、`SendTuitionReminders` 各自獨立重新推導「已繳費」，條件互不相同。
- 這不是巧合，是 `TD-073`（重複業務邏輯無自動偵測機制）論點在同一天第三次被驗證——已將 TD-073 優先級由 P2 調升為 P1，並記錄於 `docs/SYSTEM_TECH_GUIDE.md` §12.5。
- **本次範圍**：只收斂了 `AlertController.php` 內部的兩處重複（同一檔案、同一 PR #1648，風險可控）。**沒有**跨檔案把其餘 8 處也改成呼叫單一 model 方法——那會是一次橫跨通知/催繳/帳單/家長入口/報表的大範圍金流邏輯變更，其中 `DunningService.php` 又被 `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` 明文凍結需產品方核准，未經明確授權不得一次性大改。
- **防再犯**：日後若要清償 TD-073 這個具體子項，先跟產品方逐一確認要收斂的檔案範圍與驗收方式，分批進行並各自補齊回歸測試，不要一次全部重寫。

### R96. 課程管理「預排」日期：把「查詢範圍內剛好有歷史堂次」當成「該不該投影未來日期」的資格判斷（in-app #222，2026-08-06）

- 主任回報（陳依娟／興隆分校）：「為何預排只能打一個」——課程列表裡大多數課程完全沒有「預排」日期，只有剛好有堂次紀錄的那一門有。
- 根因：`ClassSessionController::buildProjectedByClassForIndex()` 用 `array_keys($materializedByClass)` 當作「要不要幫這門課算預排」的候選清單，而 `$materializedByClass` 只包含這次查詢範圍內**已經有實體堂次紀錄**的課程。一門沒有歷史紀錄的課程（剛排好、第一堂還沒到）連候選資格都沒有，跟它的排課星期/時段本該投影幾筆未來日期完全無關。
- 這是「把資料可得性當成業務資格」的反模式：「這門課有沒有歷史堂次」只是這次剛好查到什麼的技術副產物，跟「這門課排課上該不該有預排日期」是兩個獨立問題，見 `docs/SYSTEM_TECH_GUIDE.md` §12.6。
- **修法**：候選課程清單改為「已有歷史堂次的課程」聯集「請求明確帶入的 `student_class_id`/`student_class_ids`」。
- **測試**：`SessionProjectionSplitTest::test_class_sessions_index_projects_course_with_no_materialized_rows_in_range`（revert 後 fail）。
- **防再犯**：任何「候選清單」／「該不該處理這筆」的判斷，先問清楚「這是業務規則決定的資格，還是剛好這次查詢有沒有抓到資料」——不要把後者直接拿來當前者用；尤其是分頁/範圍查詢場景，資料存在與否很容易受查詢範圍影響，跟業務資格無關。

### R97. 「調課」與「備註 / 時段」按鈕命名／視覺無法區分「能不能換日期」（2026-08-06）

- 主任回報（興隆分校，非 in-app 工單）：某學生課程從星期六 13:00–15:00 改星期四 15:30–17:30「改不過去」，CEO 自己操作卻成功。後端 API 行為一致，差異在使用者點了哪個按鈕。
- 根因：`SessionEditModal.vue` 的單堂操作選單裡，「調課」（可換任何日期＋時段）與「備註 / 時段」（`PATCH /class-sessions/{id}`，驗證規則完全沒有 `session_date` 欄位，物理上不能換日期）視覺樣式相近、命名都圍繞「時間／時段」，沒有任何提示區分兩者能力差異。使用者若誤點「備註 / 時段」，會打開一個沒有日期欄位的表單，連嘗試都無從嘗試。
- 詳細根因分析（含業界對照：Google Calendar/Calendly 一律用單一入口同時處理日期+時間、Nielsen Norman「Recognition rather than recall」原則）：`docs/SYSTEM_TECH_GUIDE.md` §13。
- **修法**：按鈕文字明確化（「調課」→「🔄 調課（換日期）」；「備註 / 時段」→「備註 / 當天時段」）、各自加 `title` tooltip 講清楚能不能換日期、選單下方加一行指引、「調課」改用品牌主色系避免視覺上輸給「備註 / 時段」。純文案／視覺調整，不改後端行為。
- **測試**：`SessionEditModal.test.js`——鎖住兩按鈕文字互斥（其中一個含「換日期」，另一個不含）、各自的 tooltip 內容、選單提示文字存在。
- **防再犯**：新增任何「能力有限縮」的操作入口（例如「只能同一天」「只能單筆」），一律要求：(1) 按鈕文字本身要排除掉最直覺的誤讀，(2) 加 tooltip 明講限制，(3) 若旁邊有能力更完整的入口，要讓使用者看得出兩者差異，不能只靠使用者自己試錯發現。

### R98. 主任／管理員角色的行事曆整週看起來全空：schedules 端點被自己的 user ID 誤當 teacher_id 過濾（in-app #219 追加根因，2026-08-06）

- in-app #219（鄭宇志回報試聽課不顯示）第一輪修復（補齊 `ClassSessionController` 的預排候選清單、修正課程 3153 損毀的 `StartDate`）上線後，回報者反映問題仍存在。用 Super Admin 測試看起來資料正確，但這是**假陰性**——Super Admin 是最不可靠的角色測試代理，因為它不受 `CampusID`／`TeacherID` 任何範圍限制。改用真實建立（測完即刪）的主任帳號實測後，發現整週（非僅回報的那一天）0 堂課，即使載入進度顯示所有項目都抓到了。
- 根因：`frontend/src/lib/calendarCourseLoad.js` 的 `buildSchedulesApiUrl()`（以及 `useCalendarDataLoad.js` 內同邏輯的 legacy fallback 分支）條件寫反——非老師角色（主任／管理員）呼叫 `/api/v1/schedules` 時，把**自己的登入者 user ID** 當成 `teacher_id` 帶進查詢字串（`!isTeacher && userId` 應為 `isTeacher && userId`；同檔案 `buildStudentClassesApiUrl()` 的對應邏輯是對的，可比對）。後端 `ScheduleController::index()` 對 `teacher_id` 參數是無條件 `where()`，不分角色、不檢查這個 ID 是否真的是老師。主任的 user ID 不可能等於任何老師的 ID，於是 schedules 這層對主任／管理員角色永遠回傳空陣列。
- 行事曆週檢視的真相來源是「schedules 模板／例外層」+「class-sessions 已物化層」合併（見 G-007），只要某筆課程當週只活在還沒物化的 schedules 模板裡，主任視角就會直接看不到整筆——這解釋了「主任改不過去、CEO（Super Admin）改得過去」的根本原因，範圍比 in-app #219 原始回報的單一學生個案大得多。
- **修法**：兩處 `teacher_id` 判斷條件改為 `isTeacher && userId`，與 `student-classes` 端點的既有正確邏輯一致。
- **測試**：`calendarCourseLoad.test.js` 新增回歸測試，鎖住「主任/管理員視角的 schedules URL 絕不能帶 teacher_id」。
- **防再犯**：(1) 同一份程式碼裡，兩個平行端點（`student-classes` vs `schedules`）的「依角色決定要不要帶某個過濾參數」邏輯必須用同一套條件寫法抽出來共用或至少互相對照測試，不能各自複製一份、其中一份手滑寫反卻沒有測試守住；(2) 驗證角色限定的可見性 bug，Super Admin 不能作為任何角色的替身測試——它是唯一不受範圍限制的角色，「Super Admin 測試通過」只能證明資料本身存在，不能證明目標角色真的看得到；務必用該角色真實帳號（測完即刪）驗證。

### R99. 同一位老師掛兩個帳號、UI 合併顯示成一欄時，「輸家」帳號的課程在日檢視消失（in-app #219/#223，2026-08-06）

- R98 上線後，回報者（鄭宇志）用附截圖的方式再次確認：6/17、6/18 高為澎老師的試聽學生（吳宥萱）仍未顯示在課表，但**同一天同一位老師欄位底下其他學生的課程都正常顯示**——這個細節是關鍵，代表問題範圍已經跟 R98（整層資料消失）不同，是精準卡在單一課程。
- 查證發現：系統裡「高為澎」其實掛了兩個獨立帳號（ID 73，account `Xizhi01`，`teaching_session_count` 268，實際在用的主帳號；ID 260，account `Kao`，`teaching_session_count` 0，幾乎沒用過的重複帳號）。吳宥萱的試聽課程（`StudentClass.ID` 3153）`TeacherID` 是 260。
- 根因：`SmartCalendar.vue` 的 `filterTeacherOptions`（週/日檢視共用的老師欄位清單）刻意把顯示名稱相同的帳號合併成一欄（`alias_ids`），欄位代表 ID 取「目前載入範圍內課程數較多」的那個帳號（73）——這個合併機制本身是為了解決「同一人多帳號、UI 不要出現兩個一樣的老師欄」而設計，行為正確。但日檢視實際渲染課程用的 `getCoursesForTeacherAt()` 與計算容量徽章的 `getSlotOccupancy()`，比對的是 `course.teacher_id === 合併後的代表 ID`（單一 ID 嚴格比對），從未展開別名帳號集合。課程 3153 的 `teacher_id` 是 260（輸家帳號），欄位代表 ID 是 73，兩者永遠對不上，課程就直接消失——即使畫面上那一欄明明白白寫著「高為澎」。同一支檔案裡，週檢視「選老師 chip」篩選用的 `weekViewExpandedTeacherIdSet` 其實已經正確展開別名集合（`courses.value.filter(... aliasSet.has(...))`），只是日檢視這兩處被遺漏，沒有跟著套用同一套邏輯。
- **修法**：抽出共用的 `frontend/src/lib/teacherAliasMatch.js`（`resolveTeacherAliasIds` 取得某老師欄位的完整別名 ID 集合、`courseBelongsToTeacherAlias` 判斷課程是否屬於該集合），`getCoursesForTeacherAt()`、`getSlotOccupancy()`、`visibleTeachers` 排序用的 `teacherHasCourseToday()` 三處都改為比對別名集合而非單一 ID。
- **測試**：新增 `teacherAliasMatch.test.js`，鎖住「課程掛在合併後的輸家帳號仍要能命中該欄位」的核心案例。
- **防再犯**：(1) 「同一份資料在 UI 上被合併展示（多對一）」的功能，任何後續依這份合併結果做篩選/比對的程式碼，都必須走同一套展開邏輯，不能各自用合併後的單一代表值直接比對原始欄位——這是典型的「合併轉換做了，但下游比對忘記跟著改」；寫這類合併邏輯時，最好直接抽成共用函式讓所有比對點都吃同一份輸入，而不是分散在多個函式裡各自 inline。(2) 「同一顯示名稱、不同帳號 ID」本身也是一個值得盤點的資料品質問題——找找系統裡還有沒有其他重複帳號（例如 bulk onboarding 造成的），確認是否該直接停用/合併，而不是永遠指望前端 alias 合併機制兜底。

### R100. 批次 `Model::where(...)->delete()` 繞過 Eloquent model events，既有審計基礎設施形同虛設（自我檢討，2026-08-06）

- 稍早修復 in-app #219 時，我對課程 3153 執行了一次已徵得使用者同意的資料修正（改回正確的開課日），結果意外觸發 `maybeRebuildSessionsAfterUpdate()` 的整批重建路徑，刪除並重建了該課程的 `ClassSession` 與 `LearningRecord`。事後想找回被刪除的評量記錄內容時才發現：系統其實已經有 `ScheduleAuditLog`／`ClassSessionObserver::deleted()` 這套審計機制，理論上任何 `ClassSession` 被刪除都該留下 `old_data` 快照——但這次刪除完全沒有留下任何記錄。
- 根因：整批重建路徑用的是 `ClassSession::where('StudentClassID', ...)->delete()` 與 `LearningRecord::whereIn(...)->delete()` 這種 query builder 批次刪除，Laravel/Eloquent 的批次刪除**不會**觸發 model events（`deleting`/`deleted`），所以掛在 `deleted` 事件上的審計記錄完全沒被呼叫到。這不是審計機制本身有 bug，是呼叫方式繞過了它。
- 影響：不只是這一次事件——任何走這條整批重建路徑的刪除都沒有審計記錄，一旦刪錯，完全無法追溯或還原，這是系統性缺口，不是單一事故。
- **修法**：`LearningRecord` 在刪除前先手動寫一筆 `ScheduleAuditLog` 快照（`old_data` = 完整內容）；`ClassSession::where(...)->delete()` 改成 `ClassSession::where(...)->get()->each->delete()`（逐筆刪除），讓既有 `ClassSessionObserver::deleted()` 正常觸發，不需要另外重寫一套邏輯。
- **測試**：`RebuildDestructiveDeleteAuditTest`——驗證整批重建刪除 `ClassSession`／`LearningRecord` 前，`schedule_audit_logs` 都留下可還原的快照。
- **防再犯**：(1) 任何要刪除「有價值內容」（使用者填寫的文字、審核紀錄等）的 Model 時，先確認是走 `->delete()`（觸發 events，既有 observer 可攔截）還是 `Model::where(...)->delete()`（query builder，繞過所有 events）——後者只適合「純粹衍生、可重算」的資料，不適合任何帶內容的紀錄。(2) 做任何有風險的 production 資料修正前，先確認「如果這個修正觸發了非預期的連鎖反應，有沒有辦法事後查到發生了什麼」——這次剛好系統已經有審計機制，只是被繞過，算是運氣好；下次不能只靠運氣，修正前應該先確認目標資料表有沒有審計/備份機制、機制實際上會不會被觸發。(3) `LearningRecord` 本身完全沒有任何審計/歷史版本機制（不像 `ClassSession` 有 `ScheduleAuditLog`），這次事件確認了一旦內容被覆蓋或刪除就無法還原——這是後續可評估是否要補上的技術債，記在 `docs/TECH_DEBT.md`。
- **這次遺失的評量內容如何結案**：吳宥萱 6/18 試聽課的評量記錄已確認無法還原（無備份、無法聯繫到當事老師 高為澎 核實），CEO 已核准直接結案，不再追查；堂數與收費不受影響，純粹是這一筆評量文字內容遺失。

### R101. 「這個行事曆格子對應哪一筆 ClassSession」在同一份檔案裡有兩套獨立比對邏輯，其中一套沒跟著新功能更新（in-app #224，2026-08-07）

- in-app #224（張進鴻 8/8 17-19 那堂課無法移動或刪除）B1 偵查發現：`frontend/src/pages/SmartCalendar.vue::findSessionRowForCell()`（供「取消本堂」按鈕可見性、點名/評量角標使用）比對某日期對應哪一筆 `ClassSession` 時，先要求 row 的 `start_time` 完全等於 `course.start_time`（課程契約預設時段），只有調課例外（`is_exception=true`）才會退回「同日任一筆」。同一份檔案裡，**負責實際畫出行事曆方塊**的 `resolveAllCourseGridTimesForDate()` 從來沒有這個限制——只要當日有已物化、非取消的 row 就會用它的實際時間畫出方塊。兩套邏輯對「這個格子是哪一筆」給出不同答案。
- 這個落差本來不會被踩到，直到 #211（逐堂手動排課，2026-08-02 上線）讓使用者可以自由輸入新堂次的開始時間、不必等於課程預設時段——這類堂次 `is_exception` 是 `false`，`findSessionRowForCell()` 的 exact-match 找不到、也不會退回，直接回傳 `null`。使用者看得到方塊（渲染路徑沒問題），但點開後「取消本堂」按鈕整顆消失、點名/評量角標也不顯示，畫面上沒有任何錯誤訊息解釋原因——體感就是「這堂課沒辦法動」。
- 根因層級：**架構缺口**，不是單純邏輯錯字。專案裡其實已經有一套正確、有測試的同類實作——`frontend/src/lib/classSessionPick.js::resolveSessionIdForSubstitute()`（換代課老師流程用，同日 exact-time 優先、找不到才退回 `pickBestSessionRow(sameDateRows)`）——但 `findSessionRowForCell()` 是另一份 page-local 的獨立實作，沒有共用它。這正是**GitHub #1041**「Consolidate frontend session pick/dedupe logic (classSessionPick vs page-local copies)」點名、當時尚未收斂的技術債之一；本次是它第一次造成使用者可見的回報。
- **大廠參考**：Google Calendar Events API 用穩定的 `recurringEventId` + `originalStartTime` 維持一堂次的身分，即使該堂次被改到別的時間，仍能透過原始序列位置找到它，而不是靠「現在應該是幾點」反推。本次修法方向一致：找「這個格子是哪一筆」時，身分依據是「這一天 + 已物化的 row」，不是「課程預設時段」。
- **修法**：`classSessionPick.js` 新增 `resolveSessionRowForCell()`（回傳完整 row，供 `resolveSessionIdForSubstitute()` 內部共用），`SmartCalendar.vue::findSessionRowForCell()` 改為直接呼叫它，移除「只有 `is_exception` 才退回」的限制，讓一般（非例外）逐堂手動排課堂次也能走到同日退回比對。純前端顯示/互動修正，未觸碰任何後端扣堂/寫入路徑。
- **測試**：`classSessionPick.test.js` 新增「時段偏離課程預設仍能找到 row」「exact match 不受影響」「跨日期不誤命中」等 case；`npm run test:calendar` 全綠。
- **防再犯**：(1) 同一個概念（「這個格子對應哪一筆 row」）在同一份檔案裡出現第二套獨立比對邏輯時，應該立刻懷疑而非視為正常——尤其該概念已經有共用實作存在的情況下；(2) 任何新增「使用者可自由輸入時間、不受課程契約時段限制」的功能（如 #211）上線前，應該搜尋所有假設「同一課程同一天只會有一個固定時段」的既有比對邏輯（`grep course.start_time`／`grep courseStart` 一類），逐一確認是否也需要跟著放寬；(3) GitHub #1041 這類「已知但尚未收斂」的技術債，一旦某個 page-local 副本開始造成使用者可見的回報，應優先收斂那一份，而不是就地加 patch 再產生第三份重複邏輯。

### R102. 連續調課（調課的調課）在原始時間重新提交時，後端精確時段比對的防重複刪除抓不到舊紀錄；月結課被誤套「購買堂數」上限（木柵吳艾潼 SC#2688，2026-08-08）

- 現象：主任回報「課程管理只有一堂，行事曆卻出現兩個時段」，且這門月結課被標成「超排」。
- **根因 1（重複時段）**：`schedules` 表這門課 8/8 當晚被連續調課兩次；第二次調課的目的地時間跟第一次相同（都是 14:30），`ScheduleController::store()` 建立 `status=rescheduled` 標記時的防重複刪除（`where('status','scheduled')->whereRaw('SUBSTRING(start_time,1,5)=?', [$startHm])->delete()`）是照「這個 rescheduled 標記自己的時間」去比對要刪掉哪筆舊的 `scheduled` 紀錄——正常情況下這個時間就是被取代掉的舊時段，能抓到；但當使用者把同一堂課「重新調到同一個時間」時，這個比對邏輯理論上仍應該匹配（新舊時間相同），卻沒有生效，確切觸發條件（race / lock 順序）未完全查明，不排除是併發或交易時序的邊界情況。結果是舊的 `scheduled` 紀錄（id 7584）沒被刪除，跟最新的（id 7589）並存，`shouldRenderScheduledException()` 沒有處理「同課程同日多筆 scheduled」的情況，兩筆都被畫成行事曆方塊。
- **根因 2（月結誤判超排）**：`StudentClassController` 不分課程類型，一律把 `sessions_purchased` 設成 `SessionCount`；`isOverQuotaSession()` 只排除包堂（`PackageID`）課程，沒有排除月結（`payment_type !== 'session'`）課程。月結課根本沒有「購買堂數上限」的概念，`SessionCount` 對月結課而言不代表硬性上限，但只要材質化堂數超過這個數字就會被誤標。
- **修法（第一版，已被下面的第二版取代邏輯本身，非取代教訓）**：前端 `calendarExceptionMerge.js::shouldRenderScheduledException()` 加上「同課程同日多筆 `scheduled` 標記時，只採信 id 最大（最新）那筆」的防禦；`useCourseSessionsDisplay.js::isOverQuotaSession()` 加上 `isSessionMode(course)` 檢查（沿用既有的月結/堂數制判斷函式，不是新發明的邏輯），月結課程一律略過超排判斷。均為前端顯示層防禦性修正，未動 production 的 `schedules`/`ClassSession` 資料，也未動後端刪除邏輯本身。
- **同日上線後幾分鐘，同分校另一案例（in-app #225，木柵陳宥翰 SC#1249）證明第一版規則不夠**：這次調課連續改了三次，最後一次的目的地落在**不同一天**（8/7 被取代的紀錄，最終改到 8/8）。「同一天取最新」抓不到這種情況——8/7 那筆被取代的 `scheduled` 標記（id 7208）在 8/7 這天沒有「更新的 scheduled 標記」可以贏過它，因為真正取代它的下一步改到了 8/8，不在同一天可比較的範圍內。
- **修法（第二版，正式版）**：規則從「同課程同日期取最新」精修為「同課程＋同日期＋同時段，若存在一筆更新（id 更大）的 `rescheduled` 標記，代表這個 `scheduled` 標記已被取代」——不看「同一天有沒有更新的 scheduled」，而是直接問「這個確切時段本身有沒有被更新的改期紀錄標記為已取代」，不管新目的地落在哪一天都能正確判斷。這是比第一版更貼近問題本質的規則：一個 `scheduled` 標記的身分是它自己的「課程＋日期＋時段」，取代它的訊號應該直接看這個身分本身有沒有被蓋掉，而不是「同一天還有沒有別的更新標記」這種間接推論。
- **未解決**：後端 `ScheduleController::store()` 那段防重複刪除為何在原始案例（吳艾潼）沒生效，需要更多真實案例或加 log 才能確定；目前前端防禦已能保證畫面正確，暫不追根究柢阻擋修法上線。
- **業務判斷，非本次範圍**：吳艾潼那門課目前實際有 5 筆佔堂數紀錄對上 `SessionCount=4`，是否為合理的補課多算、還是請假轉補課少頂替了原堂號，需主任/owner 確認，不是可以由 AI 判斷的技術問題。
- **大廠對標（待落地，未在本次一併實作）**：這整類問題的架構性根因是 `schedules` 表用「不可變紀錄鏈」（每次改期都是新增一對 rescheduled/scheduled 紀錄，靠 `original_schedule_id` 串起來）表達「這一堂被改期了」，但語意上這應該是**單一堂次的目前狀態**，不是一串需要正確走訪的歷史鏈。
  - **RFC 5545（iCalendar）**：一個重複事件系列共用一個 `UID`；被改期的某一次用 `RECURRENCE-ID` 綁定「這是原始重複規則算出的哪一個時間點」（值永遠不變，即使該堂次被移到別的時間），對同一個 occurrence 的第二次修改是**更新同一個 VEVENT 元件**（同一組 UID+RECURRENCE-ID，`SEQUENCE` 遞增），不是疊加第二層 override。（[§3.8.4.4](https://icalendar.org/iCalendar-RFC-5545/3-8-4-4-recurrence-id.html)）
  - **Google Calendar Events API**：完全對應同一套模型，`recurringEventId` + `originalStartTime` 是穩定不變的身分鍵；移動該堂次是對「這一個 instance 自己的資源 URL」`PATCH`，再移動一次是對**同一個** instance 資源再 `PATCH`，不會生出第二個 instance 物件。（[Recurring events guide](https://developers.google.com/workspace/calendar/api/guides/recurringevents)）
  - **Cal.com（開源，本專案 `RFC_PLATFORM_OPTIMIZATION_FROM_STARS_2026.md` 已引用的排程參考）**：用的是跟 AllTrue 一樣的「鏈」模型（`Booking.rescheduledToUid` 正向指標，AllTrue 是反向的 `original_schedule_id`），**且已知在 production 出過同一類 bug**——[cal.com issue #12922](https://github.com/calcom/cal.com/issues/12922)：「對已經改期過的 Booking 再次改期，會產生多筆同時有效的 Booking」，跟本次 AllTrue 的事故是同一種根因形狀。這是「鏈模型本身容易在二次改期時出錯」的獨立佐證，不是 AllTrue 特有的失誤。
  - **落地方向**：根治計畫已寫成 [`docs/architecture/RFC_SCHEDULE_OCCURRENCE_IDENTITY.md`](architecture/RFC_SCHEDULE_OCCURRENCE_IDENTITY.md)（工程主線 [`ALLTRUE_ENGINEERING_NORTH_STAR.md`](architecture/ALLTRUE_ENGINEERING_NORTH_STAR.md)）。Agent 不得在未讀 RFC、未獲 Founder GO 前改 `schedules` 寫入形狀。前端 dedupe 仍是治標，直到 Phase 4+。
- **測試**：`calendarExceptionMerge.test.js`、`calendarOccurrenceMerge.test.js`、`useCourseSessionsDisplay.test.js` 均以本案真實資料（SC#2688 schedules id 7583/7584/7588/7589、ClassSession id 24169；SC#1249 schedules id 7138/7139/7207/7208/7422/7423）新增回歸案例。
- **延伸（2026-08-14，大安翟君和社會）**：R102 只擋了月結「超排」標記，課程管理「上課日期」標題仍走 `packageMemberSessionSummary()` 的堂數制「已上 / 購買」文案，月結課 `SessionCount` 被讀成購買上限。老師清單也不走週檢視的 `dedupeCalendarRowsByStudentSlot()`，同一學生同時段的兩筆 active 契約會並列。修法：月結標題改「已上 N 堂」；老師清單套用同一套學生+星期+開始時間去重。

### R103. in-app #225 的症狀被誤標成 R102 的鬼影方框問題；真正症狀（行事曆有、課程管理沒有）到今天才被正確分診（in-app #225/#226/#227，2026-08-08）

- **自我檢討**：處理 in-app #225 時，直接沿用 R102／TD-076（#1687）既有記錄「in-app #225 = 木柵陳宥翰 SC#1249 鬼影方框」去開 GitHub issue、回覆使用者「已修好」，**沒有先跑 `bug-detail-dump` 撈 #225 在資料庫裡實際的 `description` 欄位**。事後撈證據才發現 #225 真正回報的是完全不同的症狀：「8/7 李維 陳宥翰的數學課程在行事曆有 但是課程管理沒有」——跟 R102 修的「同一堂課出現兩個方框」無關。已發公開留言更正，改開正確 issue（#1690）。**教訓**：既有文件對某個 bug ID 的描述，不能取代重新撈那個 bug 的實際證據；§3.6 規則「先撈附件與描述再分診」對「看起來已經有前例」的 bug 一樣適用，越是看起來眼熟的 bug 越容易因為省略這一步而分診錯目標。
- **現象（更正後的真正症狀）**：#225（10:03，早於當天任何部署）、#226（10:39）、#227（11:00）三筆回報，同一分校（campus_id=16），文字幾乎一模一樣：「行事曆有，課程管理沒有」，三個不同學生/老師。#225 早於當天任何一次部署，確定是既有問題，非 R102 那兩次修復造成的回歸。
- **根因**：`calendarOccurrenceMerge.js` 的 exception-only 合併迴圈，會把一筆 `status='scheduled'` 且帶 `original_schedule_id`（代表自己是某次改期的目的地）的例外，在**找不到對應的已物化 `ClassSession`** 時仍然合成一個行事曆 occurrence（`class_session_id: null`）。課程管理只讀 `/api/v1/class-sessions` 回來的已物化列，`sessionViewModelFromClassSessionsRow()` 會擋掉沒有真正 session id 的列——同一份 `schedules` 資料，行事曆多畫了一堂課程管理看不到的孤兒堂次。這是 R102／#180 的同一個「行事曆與課程管理沒有共用同一套 occurrence 真相來源」架構問題的第三種變體（#180 是課程管理有、行事曆漏；R102 是同一堂重複畫兩次；這次是行事曆畫了課程管理否認存在的一堂）。
- **修法**：`mergeWeekCalendarOccurrences()` 內既有的「materialized-completeness」檢查旁，加一道守衛：`scheduled` 且為改期目的地（`original_schedule_id` 存在）卻找不到對應 `sessionRow` 時，直接跳過、不合成 occurrence。純前端顯示層防禦，未動 `schedules`/`ClassSession` 資料或後端邏輯。
- **測試**：`calendarOccurrenceMerge.test.js` 新增合成案例（無法取得 #226/#227 真實 production id，明確標註為 synthetic，區別於 R102 用真實 id 的案例）；revert-proof 已人工驗證（移除守衛後測試從 pass 變 `1 !== 0` fail，restore 後再度 pass）。
- **大廠對標**：同 R102 已引用的 RFC 5545 `RECURRENCE-ID`、Google Calendar `recurringEventId`/`originalStartTime`、cal.com 同款鏈模型 bug（[calcom/cal.com#12922](https://github.com/calcom/cal.com/issues/12922)）——不重複列出，見 R102。TD-076（#1687）持續追蹤架構根治（穩定 occurrence identity + 更新既有紀錄取代新增鏈節點），本次僅治標。
- **未解決**：孤兒 `scheduled` 例外（有 `original_schedule_id` 卻無對應 `ClassSession`）是怎麼產生的，需要後端／DB 層才能確認（是否跟 R102 提到的「防重複刪除沒生效」是同一根因），本次無 DB 存取權限，僅能防禦性隱藏顯示，未追根究柢。

### R104. `1387-db-password-rotation.yml` 的 `ALTER USER '<name>'@'localhost'` 跟連線時實際使用的 host 不一致，Founder 觸發時失敗（2026-08-07 觸發、2026-08-08 診斷修復）

- **現象**：SEC-ALLTRUE-003（production DB 密碼與 CI log 洩漏事件關聯）的最後一步——Founder 觸發 `1387 DB Password Rotation` workflow——2026-08-07 執行失敗，`rotate` job 的「Generate and apply new credential」步驟報 `ERROR 1396 (HY000): Operation ALTER USER failed for '***'@'localhost'`。
- **根因**：腳本用 `mysql -h 127.0.0.1 -u "$DB_USER" ...` 連線，MySQL 會用 `DB_USER@'127.0.0.1'` 或 `DB_USER@'%'` 這個帳號列做驗證（TCP 連線不會匹配 `@'localhost'` 那一列，那是給 Unix socket 連線或明確存在對應 grant 列時才會匹配的獨立帳號身分）。但緊接著的 `ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY ...` 卻寫死要改 `@'localhost'` 這個帳號——如果 MySQL 裡實際上沒有這一列（只有 `@'%'` 或 `@'127.0.0.1'`），對 MySQL 來說這是在改一個不存在的帳號，回 1396。
- **修法**：把 `ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY ...` 改成 `ALTER USER CURRENT_USER() IDENTIFIED BY ...`——`CURRENT_USER()` 一定解析成「這個連線實際驗證通過的那個帳號」，不管它的 host pattern 是 `%`、`127.0.0.1` 還是 `localhost`，從根本上消除這種「連線用一個身分、改密碼卻寫死另一個身分」的錯位可能性。
- **範圍**：只修了 workflow 腳本本身的 bug，**沒有觸發這個 workflow**——實際的密碼輪替仍是 Founder-only 動作，AI agent 不應該也沒有執行它。
- **測試**：`python3 -c "import yaml; yaml.safe_load(...)"` 確認 YAML 語法合法；`ALTER USER CURRENT_USER() IDENTIFIED BY '...'` 是 MySQL 官方文件記載的標準寫法（自我改密碼不需要知道自己帳號的 host pattern），無法在不觸發真正 production 輪替的前提下端對端測試，這點是本次修復的已知限制，留給 Founder 下次觸發時驗證。

### R105. 補課候選日期必須晚於原堂，不能只從今天起算

- **觸發情境**：今天是 8/8、學生請假原堂是 8/20，主任的補課候選卻從 8/9 開始，容易安排成原堂前的錯誤時序。
- **根因**：主任儀表板原本固定用「今天 +1～+14 天」呼叫候選 API，候選產生器也沒有以 `class_session.SessionDate` 做日期邊界驗證。
- **強制規則**：補課候選查詢起點必須是 `max(今天+1、原堂日+1)`；前端只負責提供正確視窗，後端候選產生器必須再次夾限，避免舊客戶端或手動 request 繞過規則。
- **測試必補**：原堂 8/20、request window 8/9～8/23 時，第一個候選為 8/21，且所有候選日期都大於 8/20。

### R106. 換成具名 DB principal 時，不能只搜尋 `DB_PASSWORD`；username/password 必須同源切換（2026-08-08）

- **觸發情境**：SEC-ALLTRUE-003 改採新 principal 的三階段輪替後，盤點發現 7 個 production workflow 與 3 支 production-oriented script 雖然每次都從 `/home/admin/backend/.env` 讀最新 `DB_PASSWORD`，MySQL 指令卻仍寫死 `-u admin`。只改 `.env` 的 `DB_USERNAME`/`DB_PASSWORD` 會讓這些路徑把「新密碼＋舊帳號」配在一起，下一次備份、deploy migration backup、診斷或 repair 才延遲爆炸。
- **強制規則**：任何 DB credential 輪替盤點都要把 `DB_USERNAME`、`DB_PASSWORD` 與 DSN 當成同一個 identity tuple；所有 production CLI consumer 必須從同一個 effective config 同時取得 username/password。CI service/local-dev 的隔離 fixture 必須另外分類，不可誤當 production consumer 批次改名。
- **驗收**：除了 app health，必須用 fresh Laravel connection 查 `CURRENT_USER()` 並比對新 username；另掃描 production workflow/script 不得再出現搭配 production `.env` password 的 `-u admin`。舊 principal 只可在人類確認 observation window 後由獨立 gate 鎖定。
- **範圍**：本次只準備 workflow/scripts/runbook，未觸發 Actions、SSH、DB 或 production mutation；實際 grant replay、server account-lock 支援與 cron/backup observation 仍是 Founder-only evidence。

### R107. 教師首頁 projected 堂次必須帶 branch_id，缺分校不可顯示內部編號（in-app #235，2026-08-15）

- **現象**：#1739／第一次欄位對齊修法上線後，回報者兩次按「問題仍存在」。週課表仍出現「Branch #0」。
- **根因**：已物化堂次有 `branch_id`；`SessionProjectionReadService::projectedSlot()` 從未回傳，前端 `s.branchId || 0`。另外 `getBranchName` 對不到名單時輸出 `Branch #N`，今日待辦另走一份未正規化 JSON。
- **修法**：projected slot 從學生 `CampusID` 帶 `branch_id`；`sessionDates` 與 index 路徑 eager-load `student`；教師首頁今日待辦改走 `fetchClassSessions`；缺分校隱藏或顯示「未設定」。
- **測試**：`SessionProjectionReadServiceTest`、`classSessionsApi.test.js`、`useBranches.test.js`、`teacherHomeSessionContract.test.js`。
- **驗收**：deploy 後請 in-app #235 回報者再按確認；GitHub #1739 已關，不可再提前標 resolved。

### R108. utf8mb3 姓名欄位不可把 4-byte Unicode 直接丟進 LIKE（GitHub #1788，2026-08-15）

- **現象**：學生名單搜尋 `蔡🏠` 直接 SQLSTATE 1267（utf8mb3 vs utf8mb4 collation）。
- **根因**：`Student.name` 仍是 utf8mb3；課程名單已用 `Utf8mb3SearchSanitizer`，`StudentController::index` 漏掉。
- **修法**：搜尋 term 先去掉 4-byte 字元；只剩 emoji 則空結果。欄位 charset 升級另案，不在這次。
- **測試**：`Utf8mb3SearchSanitizerTest`、`StudentNameSearchUtf8mb3Test`。

### R109. 堂數制請假順延尾堂不可被結案取消（GitHub #1839，2026-08-17）

- **現象**：木柵 SC#2155 連續請假自動順延到 EndDate 之後；繳費頁結案（`reason=settled`）把最後一堂標 `[結案取消]`，但 `RemainingSessions` 仍為 1。主任調課被 `ManualSessionBookingService` `course_stopped` 擋死。
- **根因層級**：F1 狀態收尾的**反面缺口**——R20 要求停用必須清未來 scheduled，但沒有「還欠堂數」守衛，結案把請假鏈尾堂當成幽靈堂次吃掉。對標：餘額未用完不可 close（Stripe subscription cancel at period end / remaining credits）。G-010／ADR-006 向前生成仍是架構債，本次不處理。
- **強制規則**：
  1. count-mode `RemainingSessions > 0` 時，`togglePause` 的 `settled`／`completed` 必須 422 `remaining_sessions_unscheduled`，除非顯式 `forfeit_remaining=true`。
  2. 已誤結案且仍有餘額時，`ManualSessionBookingService` 不得 `course_stopped`，也不得用 `EndDate` 擋住補課日。
  3. 繳費催繳「結案」按鈕在剩餘堂數 > 0 時不可送出。
- **測試必補**：`StudentClassCloseFutureSessionsTest` 結案 422 且尾堂仍 scheduled；`forfeit_remaining` 才取消。`ManualSessionBookingTest` Stop=1 + remaining>0 可排在 EndDate 之後；remaining=0 仍擋。

### R110. 課程管理「已上 N 堂」必須跟日期晶片同一套資料（in-app #237 / GitHub #1834，2026-08-17）

- **現象**：木柵吳艾潼物理月結課，卡片「已上 6 堂」但展開明細第 7 堂 08/16 已標「已上」，該堂評量也打不開。
- **根因**：標頭 `getCompletedSessionCount` 只數 `materializedSessionsOnly` 的 attended；晶片走 `primarySessionUnits`（含 session-dates 合併）+ `getSessionState`。列表載入還用「今天前後兩個月」切窗，展開詳情若已有日期快取就不再重抓。月結若缺 `payment_type` 只剩 `SessionCount`，標題會誤寫「購買 N 堂」。
- **強制規則**：已上堂數必須數使用者看得到的「已上」晶片；展開詳情必須重抓該課全部 ClassSession；`isSessionModeCourse` 遇 `ScheduleMode=date` 不得當堂數制。
- **測試必補**：7 筆 attended 晶片 → `getCompletedSessionCount === 7`；`ScheduleMode=date` 且無 payment_type 時 summary 不得出現「購買」。

### R111. 課程備註超長必須 422，不可讓 MySQL 截斷變 500（GitHub #1732，2026-08-17）

- **現象**：主任把給家長的繳費說明貼進課程備註，儲存直接 SQLSTATE 22001 Data too long for column `Memo`（Sentry PHP-LARAVEL-2B）。編輯表單沒有字數上限；`update()` 也沒驗證 Memo。
- **根因**：F6 輸入邊界的長度面——欄位仍是 VARCHAR(512)，寫入路徑假設「備註很短」。#1378 只修 charset，沒修長度。
- **強制規則**：
  1. `StudentClass.Memo` 用 TEXT（utf8mb4）；長度上限單一常數 `StudentClass::MEMO_MAX_LENGTH`。
  2. 更新／建課 API 超長回 422 `memo_too_long`，禁止落到 SQL 500。
  3. 前端 textarea `maxlength` 必須跟後端常數同一數字。
- **測試必補**：600 字中文備註 PUT 成功；上限 +1 → 422 且資料未改；MySQL `DATA_TYPE` 為 text。

### R112. 預排晶片不可佔「第 N 堂」（課程管理日期列，2026-08-17）

- **現象**：方案課「本科已上 5 堂」，但中間一筆尚未建成 ClassSession 的預排被標成第 3 堂，後面已上的變成第 4–6 堂。
- **根因**：`getSessionNumber` 對 `sessionUnits()` 逐筆加一，只跳過請假／超排。預排是 session-dates 展開（`isProjected`），不是已確認堂次，卻跟已上／已排共用序號。
- **對標**：RFC 5545 `STATUS:TENTATIVE` 與 Google Calendar `status=tentative` 不是 confirmed；Open edX 未發布草稿不進學習序；Frappe Education 行事曆只列已存 `CourseSchedule`。本系統評量 `batchSessionNumbers` 也只數實體 ClassSession。
- **強制規則**：第 N 堂只給已物化且佔堂數的列；`isProjected` 回 `null`（畫面只留「預排」）。已排未點名的 `scheduled` 仍編號。
- **測試必補**：已上、預排、已上 → 預排無序號，後一筆已上為 3。


