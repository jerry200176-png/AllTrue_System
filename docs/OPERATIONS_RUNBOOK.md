# Operations Runbook (SOP + Lessons Learned)

This runbook captures the practical SOP to keep AllTrue stable during development and deployment.

## A. Development SOP

1. Sync first:
   - `git checkout main`
   - `git pull`
2. Implement changes in focused commits.
3. If frontend changed, deploy build:
   - `cd frontend && npm run deploy`
4. Verify:
   - frontend opens
   - key API endpoint responds
5. Sync to GitHub:
   - `./scripts/git-sync.sh "feat/fix: message"`

6. **老師端（2026-04-12 起）**：預設首頁為 **教學工作台**（`teacher-home`）。部署含前端變更後，建議抽樣：**老師登入** → 工作台載入、跨分校本週課表、點「出勤／評量」導頁、側欄出缺勤**紅點**（當日有待點名 `scheduled` 堂次時）是否正常。

## B. GitHub SOP

- Main collaboration target is `jerry-sync-main`.
- Feature branches should PR into `jerry-sync-main`.
- Backup branches are **not** for normal merge.
- If PR contains huge unrelated diffs or historical artifacts: close it, do not merge.

## C. Incident lessons (must remember)

From previous incidents:
- Mixed/unrelated git histories caused confusion and wrong merge targets.
- Laravel cache/config drift caused app to read outdated paths/config.
- Missing `server.php` or `public/.htaccess` broke API routing/runtime.
- Wrong DB credentials in `backend/.env` caused full API failure.
- Wrong file ownership in frontend build dirs caused deploy/build failures.
- `GET /api/v1/alerts/tuition` 曾被誤改成只回傳「未繳費」或只查堂數制，會漏掉「剩餘 <= 2 堂（含 0）」與**月結制**將近繳費日提醒。**完整規則務必維持與** `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` **一致**（改 API 前先讀該檔）。

**2026-04-10 事故教訓（分校選單 / 學生資料消失 / 全 API 500）**：
- `Campus.id` 在 DB 中不是從 1 連續排列（興隆=17, 新店=9, 大安=15, 木柵=16…），**任何修改分校 ID 前必須先查 DB**。
- `backend/public/branches.json` 是 API 失敗時的備援，其 ID 必須與 `Campus` 表一致，否則查詢帶錯 `CampusID` 讓資料看起來消失。
- `「資料消失」通常不是真的被刪除`：先查 DB 筆數 (`SELECT COUNT(*) FROM Student`)，再確認前端 `branch_id` 與真實 `Campus.id` 對應是否正確。
- **全 API 500 的第一步永遠是清 bootstrap cache**（見下方 H 節）。

**2026-04-10 事故教訓（剩餘堂數／月結繳費提醒消失）**：
- `GET /api/v1/alerts/tuition` 的契約不可縮減；**完整規則以** `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` **為準**，並與 `docs/AI_REGRESSION_LESSONS.md` 對照。
- 堂數制須含：`unpaid`、**`low_sessions`（`RemainingSessions <= 2`，含 0 堂）**；**不可只查 `ScheduleMode=count` 而漏掉月結 `date` + `settlement_day`**。
- `frontend/src/pages/DirectorDashboard.vue` 的「繳費提醒」依賴上述 API；縮減契約會讓畫面誤顯「無需催繳」。
- 修改 `AlertController` 或 `NotificationSyncService` 的提醒條件後，務必跑 `tests/Feature/TuitionAlertsApiTest.php` 並手動抽樣月結／堂數制。

## D. Recovery SOP (website looks old / API broken)

1. Confirm current code branch and latest commit:
   - `git status`
   - `git log -1 --oneline`
2. Check app routing essentials:
   - `backend/server.php`
   - `backend/public/.htaccess`
3. Clear Laravel cached files:
   - `backend/bootstrap/cache/*.php` (config/services/packages)
4. Verify DB credentials in `backend/.env`.
5. Rebuild frontend:
   - `cd frontend && npm run deploy`
6. Re-test web + API endpoints.

## E. Pre-merge checklist

- PR target branch is correct (`jerry-sync-main`)
- No accidental artifacts (`dist`, cache, local binaries, temp files)
- Frontend changes have been deployed
- No critical regressions in login, students, scheduling, attendance, finance pages

## F. High-risk areas

- Session deduction / remaining sessions logic
- Subject-unit statistics output format
- Campus/branch data isolation
- Attendance and class-session relationship integrity

## G. SOP：分校設定異常（選單多 / 資料消失）

> 適用症狀：分校選單顯示超過 8 間、切換分校後學生/老師/課程變空白

1. **先確認 DB 真實 Campus.id**（最重要，勿臆測）：
   ```bash
   mysql -h 127.0.0.1 -u admin -padmin123 AllTrue \
     -e "SELECT id, name FROM Campus ORDER BY id; \
         SELECT CampusID, COUNT(*) as cnt FROM Student GROUP BY CampusID;"
   ```

2. **確認 `branches.json` ID 與 DB 一致**：
   ```bash
   cat backend/public/branches.json
   ```
   若有出入，直接依 DB 結果修正；正確格式（8 間）：
   ```json
   [
     {"id": 17, "name": "興隆分校", "code": "xinglong"},
     {"id": 9,  "name": "新店分校", "code": "xindian"},
     {"id": 15, "name": "大安分校", "code": "daan"},
     {"id": 16, "name": "木柵分校", "code": "muzha"},
     {"id": 2,  "name": "東湖分校", "code": "donghu"},
     {"id": 3,  "name": "大直分校", "code": "dazhi"},
     {"id": 4,  "name": "汐止分校", "code": "xizhi"},
     {"id": 1,  "name": "內湖分校", "code": "neihu"}
   ]
   ```

3. **確認 `useBranches.js` 的 `DEFAULT_BRANCHES` ID 與上方一致**：
   ```
   frontend/src/lib/useBranches.js  →  const DEFAULT_BRANCHES = [...]
   ```

4. **重新 deploy 前端**：
   ```bash
   cd frontend && npm run deploy
   ```

5. **請使用者強制重新整理瀏覽器**（Ctrl+Shift+R / Cmd+Shift+R）。

---

## H. SOP：全 API 500 緊急恢復

> 適用症狀：所有 `/api/v1/*` 都回傳 HTTP 500，包含 `/branches`、`/auth/login`

**Step 1：查 Laravel 錯誤日誌**
```bash
tail -30 backend/storage/logs/laravel.log
```
常見關鍵字：
- `Class "..." not found` → 快取問題，執行 Step 2
- `SQLSTATE` / `Connection refused` → DB 連線問題，確認 `.env` 與 MySQL 狀態
- `No application encryption key` → `.env` APP_KEY 遺失

**Step 2：清 bootstrap cache（最常見修復）**
```bash
rm -f backend/bootstrap/cache/services.php \
       backend/bootstrap/cache/packages.php \
       backend/bootstrap/cache/config.php
```

**Step 3：驗證恢復**
```bash
curl -sk https://daan.lifenet.com.tw/api/v1/branches
# 預期：HTTP 200，回傳 JSON 陣列
```

**Step 4：若仍 500，依序檢查**
```bash
# DB 是否可連
mysql -h 127.0.0.1 -u admin -padmin123 AllTrue -e "SELECT 1"

# PHP-FPM 是否存活
ps aux | grep php-fpm | grep -v grep

# Apache 是否存活
sudo systemctl status apache2

# .env 是否存在
ls -la backend/.env
```

---

## I. Reference docs

- `README.md`
- `AI_QUICKSTART.md`
- `docs/GITHUB_SYNC_WORKFLOW.md`
- `docs/INCIDENT_2026-04-10_BRANCH_CAMPUS_500.md`（分校選單 / 學生消失 / 全 API 500）

---

## J. Pricing Contract（避免每堂費用再次誤算）

**2026-04-10 回歸教訓（課程費用）**：
- `price_per_session` / `rate_per_30min` 在課程管理預設語意是「每堂費用」，不是每小時費用。
- 只有在 API 請求明確傳入 `rate_unit=hour` 時，才可使用時薪計價。
- 不可依 `day_time_slots` 是否有 `duration_minutes` 自動推斷成時薪，否則會把堂費再次乘上時數，造成總費用膨脹。

**開發檢查點（改排課/收費前必看）**：
1. 若是堂數制：`Charge = 每堂費用 × SessionCount`。
2. 若是時薪制（需明確 `rate_unit=hour`）：`Charge = 時薪 × 各堂時數總和`。
3. PR 必須包含至少一個 regression test，覆蓋「未傳 `rate_unit` 時維持堂費」情境。

---

## K. Attendance / Remaining Sessions / Subject Units SOP（2026-04-12 更新）

> 這段是**強制口徑**，後續人員與 AI 不可再混用邏輯。

### 1) 剩餘堂數唯一規則

- 堂數制課程：`RemainingSessions = SessionCount - UsedSessions`（`UsedSessions` 與 `SessionCount` 取 min cap）
- 月結制課程：`RemainingSessions` 恆 0，`UsedSessions` 依實際上課堂數累加
- **已上堂數（UsedSessions 口徑）**須與畫面上「已上」一致，取以下三者之**最大值**後再與購買堂數取 cap：
  1. 已扣點出缺勤：`StudentSingIn.SessionDeducted = 1`（DISTINCT 堂次）
  2. 排課堂次已結：`ClassSession.Status ∈ {completed, attended, late}`
  3. 無綁定堂次之 orphan 評量（歷史補登遺留）：`LearningRecord.Status=approved` 且 `ClassSessionID<=0`
- 另與 `SessionDeductionLedger`（attendance / retro_leave / status_adjust）取 max，避免漏帳
- **核准評量 = 點名的一種手段**：核准時透過 `ApprovalSessionSyncService::syncOnApprove` 建立 `StudentSignIn(Memo=lr_approve)`、更新 `ClassSession.Status=attended`、呼叫 `deductOnAttendance`，與手動點名走同一管線

### 2) 到班判定口徑

- 視為到班並扣堂：`present`, `late`
- 不扣堂：`absent`, `excused`, `leave`
- `late` 雖扣堂，但必須保留在出缺勤資料中供家長端查閱
- **核准評量視同 present 到班**

#### 2a) 曠改請假（`excused` + 既有 `ClassSessionID`）

- 老師／主任在出缺勤將某堂標為 **請假（`excused`）且對應既有堂次** 時：後端會寫入 **`schedules`（status=leave）** 並呼叫 **`CourseLeaveCascadeService::applyLeaveCascade`**，與課程管理／智慧排課的請假順延**同一套邏輯**；該筆 `StudentSingIn` 仍 **`SessionDeducted=0`**（該節不扣堂），但後續預排堂次可能前移並延長 `EndDate`。
- **禁止**在 `AttendanceController` 另寫一套順延而繞過 `CourseLeaveCascadeService`，以免與 `ScheduleController` 行為分歧。

### 3) 科目數（Subject Units）口徑

- 科目數只看評量審核結果（approved LearningRecord）與其加權規則
- 科目數與堂數是兩條獨立管線，不可互相回寫

### 4) 開發/改碼禁忌（給後續 AI）

- 核准評量時**必須**呼叫 `ApprovalSessionSyncService::syncOnApprove`，此為唯一的核准驅動扣堂入口；禁止在任何地方直接呼叫 `SessionDeductionService::deductForSession` 而不透過 `deductOnAttendance` 或 `syncOnApprove`
- 禁止**只**用 `SessionDeducted` 或**只**用 `approved LearningRecord count` 單一來源當 `UsedSessions`（必須與 `ClassSession` 已完成狀態一併對齊；實作見 `SessionDeductionService::batchObservedUsedSessions` / `recomputeCounters`）。無綁定堂次之 orphan 評量仍可依日期計入（補登遺留）。
- 若調整堂數計算，必須先檢查：
  - `AttendanceController`（手動點名）
  - `SwipeRfidController`（刷卡）
  - `SessionDeductionService`
  - `ApprovalSessionSyncService`（核准驅動扣堂）
  - `StudentClassController::index`（課程列表展示）

### 5) 課程管理堂次警示排查 SOP

營運/客服回報「排程列數與購買堂數不一致」時：

1. **確認課程 ID**（請回報者提供學生姓名 + 科目，從後台查 `StudentClass.ID`）
2. **查後端堂次分佈**：
   ```sql
   SELECT Status, COUNT(*) AS cnt
   FROM ClassSession
   WHERE StudentClassID = ?
   GROUP BY Status;
   ```
3. **計算有效堂次**：加總非 `cancelled/leave/leave_adjusted/excused` 的 `cnt`
4. **比對購買堂數**：`SELECT SessionCount FROM StudentClass WHERE ID = ?`
5. **判定**：
   - 有效 = 購買 → 前端未更新或快取，執行前端 deploy 並請使用者清瀏覽器快取
   - 有效 > 購買 → 真異常，檢查是否多排；聯繫工程修正
   - 有效 < 購買 → 若有 leave 列＝正常（待補課）；無 leave 列＝缺堂，檢查 `extendSessionsIfNeeded` 是否遺漏
6. **回報格式**：course_id / branch_id / 有效堂次 / 購買堂數 / 各狀態計數

**批次稽核查詢**（dry-run，唯讀）：列出所有「有效堂次 != 購買堂數」的進行中課程

```sql
SELECT
  sc.ID AS course_id,
  s.CampusID AS branch_id,
  sc.SessionCount AS purchased,
  COUNT(CASE WHEN cs.Status NOT IN ('cancelled','leave','leave_adjusted','excused') THEN 1 END) AS effective,
  COUNT(CASE WHEN cs.Status = 'leave' THEN 1 END) AS leaves,
  COUNT(CASE WHEN cs.Status = 'leave_adjusted' THEN 1 END) AS leave_adj,
  COUNT(CASE WHEN cs.Status = 'cancelled' THEN 1 END) AS cancelled,
  COUNT(CASE WHEN cs.Status = 'excused' THEN 1 END) AS excused,
  COUNT(*) AS total_rows
FROM StudentClass sc
JOIN Student s ON s.id = sc.StudentID
LEFT JOIN ClassSession cs ON cs.StudentClassID = sc.ID
WHERE sc.Stop = 0
  AND sc.SessionCount > 0
GROUP BY sc.ID, s.CampusID, sc.SessionCount
HAVING effective != purchased
ORDER BY branch_id, course_id;
```

---

## L. 效能優化上線操作（mobile-learning-lag-fix）

### 變更摘要

| 項目 | 新值 | 回退方式 |
|------|------|----------|
| badge 輪詢間隔 | 60s 統一，背景頁暫停 | `perfFlags.js` → `BADGE_POLL_INTERVAL: 25000` → rebuild |
| 評量頁 per_page | 50（含載入更多）| `.env` `PERF_LR_DEFAULT_PER_PAGE=200` |
| 學生/class-sessions per_page | 200 / 500 | `perfFlags.js` 改回舊值 → rebuild |
| 通知 sync | 每分校 5min throttle | `.env` `PERF_THROTTLE_NOTIF_SYNC=false` |
| 手機 backdrop-filter | 640px 以下停用 | 移除 `styles.css` 中 `MOBILE PERF RELIEF` 區塊 → rebuild |
| DB indexes | 4 組複合索引 | `php artisan migrate:rollback --step=1` |

### 後端回退（5 分鐘內）
```bash
echo "PERF_THROTTLE_NOTIF_SYNC=false" >> /home/admin/backend/.env
echo "PERF_LR_DEFAULT_PER_PAGE=200" >> /home/admin/backend/.env
cd /home/admin/backend && php artisan config:clear
# 如需回退索引：php artisan migrate:rollback --step=1
```

### SLO 門檻
| 端點 | P95 目標 | P99 上限 |
|------|----------|----------|
| `GET /api/v1/learning-records` | ≤ 1200ms | ≤ 2000ms |
| `GET /api/v1/notifications/unread-count` | ≤ 300ms | ≤ 600ms |
| `GET /api/v1/class-sessions` | ≤ 800ms | ≤ 1500ms |

### Go / No-Go
- **Go**：卡頓回報下降 ≥ 50%，無核心回歸
- **No-Go**：任一核心回歸，或 SLO 30 分鐘持續超標

---

## M. Log 管理與 Tmpfs 緩衝（2026-04-16）

### 1) 現況

- `laravel.log`：已改為 **daily rotation**（14 天保留），取代原本 `single`（永不輪轉）。
- `perf.log`：daily rotation，14 天保留（未改動）。
- 根檔案系統：NVMe SSD（`/dev/nvme0n1p2`），非 SD 卡。

### 2) Tmpfs 緩衝（選擇性啟用）

啟用高頻 log 記憶體緩衝，定時落盤，降低 I/O 負載：

```bash
sudo bash /home/admin/scripts/infra/setup-log-tmpfs.sh
```

- 掛載 128 MB tmpfs 於 `/var/log/alltrue-tmpfs`
- systemd timer 每 5 分鐘 flush 至 `backend/storage/logs/`
- 使用率 > 80% 自動降級為直接落盤

### 3) 回滾（< 5 分鐘）

```bash
sudo bash /home/admin/scripts/infra/rollback-log-tmpfs.sh
```

冪等操作：卸載 tmpfs → 停止 timer → 清理 fstab → 還原直寫。

### 4) 監控

- Health 端點：`GET /api/v1/health` → `log_pipeline` 區段
- systemd timer：`systemctl list-timers | grep alltrue`
- tmpfs 使用率：`df -h /var/log/alltrue-tmpfs`
- flush 日誌：`journalctl -u alltrue-log-flush.service`
- 告警紀錄：`journalctl -t alltrue-log`

### 5) 儲存介質盤點

```bash
bash /home/admin/scripts/infra/storage-inventory.sh
```

輸出根檔案系統來源、裝置型號與 SD 卡偵測結果。

### 6) 基線量測

```bash
bash /home/admin/scripts/infra/baseline-capture.sh
```

產出報告至 `docs/baselines/`，含 log 寫入量、API P95、記憶體狀態。

---

### 6) 上線前回歸檢查（必跑）

0. 新後端含出缺勤科目修正者：確認已跑 migration **`2026_04_12_200000_remap_orphaned_subject_ids`**（若環境有舊 Subject 主鍵殘留）；`GET /api/v1/attendance` 抽查 `subject_name` 非空列
1. 點名 `present` 後，`UsedSessions +1 / RemainingSessions -1`
2. 點名 `late` 後，`UsedSessions +1 / RemainingSessions -1`，且家長端可見「遲到」
3. 核准評量後：`RemainingSessions -1`（堂數制）、`UsedSessions +1`（月結制）、`ClassSession.Status=attended`、出缺勤不再列出待點名
4. 若已有獨立點名再核准：堂數不重複扣
5. 核准後再手動送出點名（POST attendance）：應回傳 409
6. 評量 rollback 後：堂數恢復、`ClassSession.Status=scheduled`（若無其他點名）；若有獨立點名，rollback 不影響獨立點名
7. 科目數統計隨評量審核變動，但不影響堂數

---

## N. LINE 課表回報推播設定（`staff_line_group_id`）（2026-04-18 新增）

### 背景

課表出入回報系統（`schedule-discrepancies`）在老師提交回報時，會自動推播 LINE 訊息至各分校的主任群組。推播使用 LINE Messaging API Push Message，需要：
1. 分校既有的 `messaging_channel_token`（LINE Bot Channel Access Token）
2. 新增的 `staff_line_group_id`（主任 LINE 群組的 Group ID）

Migration `2026_04_17_200001_add_staff_line_group_id_to_campus` 已新增此欄位至 `Campus` 資料表（nullable）。**未設定時，推播會靜默跳過**（不影響 API 回應與 in-app 功能）。

### 設定步驟

#### 1) 取得 LINE Group ID

1. 在 LINE 上為各分校主任建立（或使用既有）群組
2. 將 AllTrue LINE Bot 加入群組（需為群組成員）
3. 隨便在群組發一則訊息，Bot 會收到 Webhook 事件
4. 查看 `backend/storage/logs/laravel.log` 或 Webhook 日誌，找到：
   ```json
   { "type": "message", "source": { "type": "group", "groupId": "Cxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" } }
   ```
5. 複製 `groupId`（以 `C` 開頭，32 位英數字）

#### 2) 寫入資料庫

```bash
# 登入 MySQL（或使用 phpMyAdmin / Tinker）
cd /home/admin/backend

# 方法 A：Artisan Tinker
php artisan tinker
>>> DB::table('Campus')->where('id', <分校ID>)->update(['staff_line_group_id' => 'C你的GroupId'])

# 方法 B：直接 SQL
mysql -u root -p alltrue -e "UPDATE Campus SET staff_line_group_id='C你的GroupId' WHERE id=<分校ID>;"
```

#### 3) 驗證

```bash
# 確認欄位已寫入
php artisan tinker --execute="print_r(DB::table('Campus')->select('id','name','staff_line_group_id')->get()->toArray())"

# 觸發測試推播（在 Tinker 中）
php artisan tinker
>>> $d = \App\Models\ScheduleDiscrepancy::latest()->first();
>>> \App\Services\ScheduleDiscrepancyNotifier::notify($d);
```

#### 4) 未設定時的行為

若 `staff_line_group_id` 為空（或 `messaging_channel_token` 未設定），`ScheduleDiscrepancyNotifier` 會：
- 記錄 `INFO schedule_discrepancy.line_skip`（`reason: missing_group_id` 或 `missing_token`）至 `laravel.log`
- 靜默返回，**不影響 API 回應**（HTTP 200）
- in-app 儀表板仍正常顯示所有回報

#### 5) 排錯

| 症狀 | 檢查點 |
|---|---|
| LINE 收不到訊息（無 log） | 確認 `staff_line_group_id` 不為空；確認 Bot 在群組內 |
| log 出現 `line_4xx: status=403` | `messaging_channel_token` 已過期或沒有 push 權限，至 LINE Dev Console 確認 |
| log 出現 `line_4xx: status=400` | `groupId` 格式錯誤或 Bot 不在群組中 |
| log 出現 `line_failed` | 三次重試均失敗（5xx / 429），屬 LINE 服務端問題；in-app 仍正常，無需額外處理 |
| `test_submit_succeeds_even_without_line_config` 失敗 | 代表 Notifier 例外未被 try/catch 正確吸收，為嚴重回歸，立刻查 `ScheduleDiscrepancyNotifier` |

#### 6) 注意事項

- `staff_line_group_id` 是**分校級**設定，每個分校一個群組
- 若分校沒有 LINE Messaging API（`messaging_channel_token`），推播功能無效（已在 OQ-05 P1 列入下一 Sprint：建立 UI 設定頁讓主任自助填入）
- LINE Notify 已於 2025-03-31 下線，此系統使用 LINE Messaging API Push Message（不同 API，需 Bot Channel Token）
- LINE Push 失敗**不阻擋**課表出入回報的提交與處理流程

