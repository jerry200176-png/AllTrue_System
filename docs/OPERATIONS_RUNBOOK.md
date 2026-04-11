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
- `docs/INCIDENT_2026-04-10_GITHUB_AND_SITE_ROLLBACK.md`
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

## K. Attendance / Remaining Sessions / Subject Units SOP（2026-04-10 凍結）

> 這段是**強制口徑**，後續人員與 AI 不可再混用邏輯。

### 1) 剩餘堂數唯一規則

- 堂數制課程：`RemainingSessions = SessionCount - UsedSessions`（`UsedSessions` 與 `SessionCount` 取 min cap）
- **已上堂數（UsedSessions 口徑）**須與畫面上「已上」一致，取以下三者之**最大值**後再與購買堂數取 cap：
  1. 已扣點出缺勤：`StudentSingIn.SessionDeducted = 1`（DISTINCT 堂次）
  2. 排課堂次已結：`ClassSession.Status ∈ {completed, attended, late}`
  3. 已核准評量所綁堂次：`LearningRecord.Status=approved` 且 `ClassSessionID>0`（DISTINCT）
- 另與 `SessionDeductionLedger`（attendance / retro_leave / status_adjust）取 max，避免漏帳
- **評量審核 API 仍不得主動呼叫扣堂**；但若該堂次在 (2)(3) 已可辨識為已上，同步邏輯必須算入，否則會出現「畫面五堂已上、剩餘卻顯示六堂」之類錯誤（2026-04-10 實例：黃品皓課程列表）

### 2) 到班判定口徑

- 視為到班並扣堂：`present`, `late`
- 不扣堂：`absent`, `excused`, `leave`
- `late` 雖扣堂，但必須保留在出缺勤資料中供家長端查閱

### 3) 科目數（Subject Units）口徑

- 科目數只看評量審核結果（approved LearningRecord）與其加權規則
- 科目數與堂數是兩條獨立管線，不可互相回寫

### 4) 開發/改碼禁忌（給後續 AI）

- 禁止在 `LearningRecordController::approve/batchApprove/rollback` 內呼叫任何扣堂邏輯
- 禁止**只**用 `SessionDeducted` 或**只**用 `approved LearningRecord count` 單一來源當 `UsedSessions`（必須與 `ClassSession` 已完成狀態一併對齊；實作見 `SessionDeductionService::batchObservedUsedSessions` / `recomputeCounters`）
- 若調整堂數計算，必須先檢查：
  - `AttendanceController`（手動點名）
  - `SwipeRfidController`（刷卡）
  - `SessionDeductionService`
  - `StudentClassController::index`（課程列表展示）

### 5) 上線前回歸檢查（必跑）

1. 點名 `present` 後，`UsedSessions +1 / RemainingSessions -1`
2. 點名 `late` 後，`UsedSessions +1 / RemainingSessions -1`，且家長端可見「遲到」
3. 評量從 pending → approved 後：**不得**由審核流程額外觸發扣堂；若該堂在排程上已標為已上/已綁堂次，匯總後的 `UsedSessions` 須與畫面一致（不得少算）
4. 評量 rollback 後，堂數不變
5. 科目數統計隨評量審核變動，但不影響堂數

