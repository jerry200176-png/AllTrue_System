# 資料修復計畫：in-app #189 / #191（跨約重複堂次）

> **狀態**：Draft — **禁止在核准前於 production 執行任何寫入**  
> **GitHub**：[#1095](https://github.com/jerry200176-png/AllTrue_System/issues/1095)（#189）、[#1097](https://github.com/jerry200176-png/AllTrue_System/issues/1097)（#191）  
> **長期修復**：Epic [#957](https://github.com/jerry200176-png/AllTrue_System/issues/957)（ClassSession 物化 + unique slot index）

---

## 1. 影響分析

### #189 — 陳品承加課重複入帳（新店，回報者黃芝琳）

| 項目 | 內容 |
|------|------|
| 學生 | 2144 陳品承 |
| 主課程 | SC **1946**（理化，8 堂，Teacher 67） |
| 幽靈殼 | SC **2264**（SessionCount=0，StartDate=EndDate=2026-06-13，同師同生） |
| 實體衝突 | 6/13、6/20 各有一堂 **17:00–18:00 attended** 记在 SC2264，同時 SC1946 仍有 17:00–19:00 相關列（含 attended / cancelled placeholder） |
| 使用者影響 | 評量列表出現無法填寫的「第 4 堂」；未填評量提醒無法消除；可能重複扣堂 |
| 嚴重度 | **P1** — 工作流程卡住 + 帳務/堂數風險 |

### #191 — 吳夏妍跨約 5/14 雙記（新店，回報者彭老師）

| 項目 | 內容 |
|------|------|
| 學生 | 20 吳夏妍 |
| 舊約 | SC **395**（數學，9 堂，Stop=1）— 已消耗 **10/9** 堂 |
| 新約 | SC **1655**（5 堂，自 2026-05-14）— 5/14 亦有 completed 列 |
| 收據 | R-000200 已 void（2026-06-30），但底層堂次未對齊 |
| 根因 | 續約時新約建了 5/14 堂，舊約仍保留 5/14 attended → **跨 StudentClass 同日同時段雙記** |
| 使用者影響 | 收據日期列表 10 筆（應 9 筆）；主任撤銷收據後仍無法更正課程 |
| 嚴重度 | **P1** — 帳務對帳錯誤 + 歷史資料不一致 |

### 共同根因家族

- 加課 / 續約 / schedule 重物化未走單一 slot 權威（#957）
- 無 DB unique constraint：`student_id + session_date + start_time`（跨 SC）
- 與 in-app #173/#175 同族

---

## 2. 偵測查詢（唯讀）

### 2.1 跨課程同日同開始時間雙 attended（#191 型）

```sql
SELECT sc.StudentID, st.name AS student_name,
       cs.SessionDate, cs.StartTime,
       GROUP_CONCAT(DISTINCT cs.StudentClassID ORDER BY cs.StudentClassID) AS class_ids,
       COUNT(DISTINCT cs.StudentClassID) AS class_count,
       GROUP_CONCAT(cs.id ORDER BY cs.id) AS session_ids,
       GROUP_CONCAT(cs.Status ORDER BY cs.id) AS statuses
FROM ClassSession cs
JOIN StudentClass sc ON sc.ID = cs.StudentClassID
JOIN Student st ON st.id = sc.StudentID
WHERE cs.Status IN ('attended', 'completed')
GROUP BY sc.StudentID, st.name, cs.SessionDate, cs.StartTime
HAVING class_count > 1
ORDER BY cs.SessionDate DESC;
```

### 2.2 消耗堂數 > SessionCount（#191 型）

```sql
SELECT sc.ID, sc.StudentID, st.name, sc.SessionCount,
       COUNT(*) AS consumed
FROM StudentClass sc
JOIN Student st ON st.id = sc.StudentID
JOIN ClassSession cs ON cs.StudentClassID = sc.ID
WHERE cs.Status IN ('attended', 'completed', 'late')
GROUP BY sc.ID, sc.StudentID, st.name, sc.SessionCount
HAVING consumed > sc.SessionCount
ORDER BY consumed - sc.SessionCount DESC;
```

### 2.3 SessionCount=0 幽靈課程仍有 attended（#189 型）

```sql
SELECT sc.ID, sc.StudentID, st.name, sc.SessionCount, sc.StartDate, sc.EndDate,
       COUNT(cs.id) AS session_rows
FROM StudentClass sc
JOIN Student st ON st.id = sc.StudentID
JOIN ClassSession cs ON cs.StudentClassID = sc.ID
WHERE sc.SessionCount = 0
  AND cs.Status IN ('attended', 'completed', 'scheduled')
GROUP BY sc.ID, sc.StudentID, st.name, sc.SessionCount, sc.StartDate, sc.EndDate
HAVING session_rows > 0;
```

### 2.4 本案快查

```sql
-- #189
SELECT id, StudentClassID, SessionDate, StartTime, EndTime, Status, Note
FROM ClassSession
WHERE StudentClassID IN (1946, 2264) AND SessionDate IN ('2026-06-13','2026-06-20')
ORDER BY SessionDate, StartTime, id;

-- #191
SELECT id, StudentClassID, SessionDate, StartTime, Status
FROM ClassSession
WHERE StudentClassID IN (395, 1655) AND SessionDate BETWEEN '2026-05-01' AND '2026-05-31'
ORDER BY SessionDate, StartTime;
```

---

## 3. 修復策略比較

| 策略 | 說明 | #189 | #191 |
|------|------|------|------|
| **A. 作廢冗餘列** | 保留「課程 of record」的堂次，將幽靈/跨約重複列標 `cancelled` + Note | ✅ 首選：保留 SC1946，作廢 SC2264 的 6/13、6/20 attended | ✅ 作廢 SC395 的 5/14 attended（歸屬新約 SC1655） |
| **B. 合併 StudentClass** | 把幽靈 SC 併入主 SC | ❌ SC2264 為空殼，合併風險高 | ❌ 兩約業務意義不同 |
| **C. 僅 UI 隱藏** | 前端不顯示 | ❌ 不修正堂數/評量/收據 | ❌ 帳務仍錯 |
| **D. 保留歷史 + 補登說明** | 不刪列，只加 Note + 手動調 RemainingSessions | 備援 | 備援 |

**推薦**：策略 **A** — 最小寫入、可審計、與 #957 長期 unique slot 方向一致。

### #189 建議動作（待批准）

1. **作廢** SC2264 的 ClassSession 18569（6/13 17-18 attended）、18602（6/20 17-18 attended）→ `cancelled`，Note：`資料修復 #189 — 與 SC1946 重複加課，保留主課程紀錄`
2. **確認** SC1946 上 6/13、6/20 的合法 attended 列時段與評量一致（必要時把 17-19 調整為 17-18，或保留 17-19 若為實際授課時長）
3. **清理** SC1946 上 `cancelled` placeholder 列（20203、18511 等）若僅為物化幽靈 — 維持 cancelled，不刪除
4. **Stop=1** SC2264 或標記停用，避免再進評量列表
5. **重算** SC1946 `RemainingSessions` / 評量待填狀態

### #191 建議動作（待批准）

1. **作廢** SC395 的 5/14 attended 列（保留 SC1655 的 5/14 completed 為新約起算）
2. **驗證** SC395 消耗 = 9，最後一堂 = 5/7
3. **重開收據**（業務流程）：由主任依修正後 9 堂重開 R-000200 或新單 — **不在此腳本自動開帳**
4. **重算** SC395 `UsedSessions` / `RemainingSessions`

---

## 4. Migration / 修復腳本（草稿）

> 檔案建議路徑：`backend/app/Console/Commands/RepairDuplicateSessionSlots.php`  
> **尚未實作** — 僅規格。

### 4.1 介面

```bash
# 唯讀預覽（必須先跑）
php artisan repair:duplicate-sessions --dry-run --case=189
php artisan repair:duplicate-sessions --dry-run --case=191

# 執行（需 --force + 環境確認）
php artisan repair:duplicate-sessions --case=189 --force
```

### 4.2 安全要求

| 要求 | 說明 |
|------|------|
| **備份** | 執行前 `mysqldump` 全庫（見 `DANGEROUS_OPERATIONS.md`） |
| **Transaction** | 每 case 包在 `DB::transaction()`；單堂失敗則全案 rollback |
| **Dry run** | 預設 `--dry-run`；列出將更新的 `ClassSession.id`、舊/新 Status |
| **審計** | 寫入 `Note` 欄 + optional `repair_audit_log` 表（user_id, case, ids, ts） |
| **環境鎖** | 拒絕在 `APP_ENV=production` 除非 `--force` + `ALLOW_PROD_REPAIR=1` |
| **Pi 禁測試** | ⛔ 不得在 Pi 跑 `php artisan test` 驗證此腳本 |

### 4.3 回滾

1. 從備份還原受影響列：`git checkout` 無法還原 DB — 只能靠 **備份 SQL**
2. 腳本輸出 JSON snapshot：`storage/app/repair-snapshots/189-191-{timestamp}.json`（修復前狀態）
3. 回滾命令（草稿）：`php artisan repair:duplicate-sessions --rollback --snapshot=<file>`

---

## 5. 核准清單（CEO / 主任）

執行前需書面批准：

- [ ] 已閱讀本文件 §1–§3
- [ ] Dry run 輸出已人工核對（附截圖或 log）
- [ ] 已做 DB 備份（路徑：________）
- [ ] 同意 #189 保留 SC1946、作廢 SC2264 指定列
- [ ] 同意 #191 作廢 SC395 的 5/14 列
- [ ] 知悉收據重開需人工帳務流程

---

## 6. 預防（代碼面，非本腳本）

- #957：unique index + `ClassSessionMaterializationService` 單一寫入權威
- #1081 reproduction gate：新增「consumed > SessionCount」「跨 SC 同 slot attended」夜間查詢
- 續約流程：新約起算日不得與舊約最後一堂同日雙記（需產品規則確認）

---

## 7. Dry-run 準備與 audit 執行結果（2026-07-08）

> **狀態**：`repair:duplicate-sessions` 指令**尚未實作**；以下為 production **唯讀 audit**，等同 dry-run 的「偵測階段」。  
> ⛔ **未執行任何寫入**。

### 7.1 指令規格（實作後）

```bash
# 預設 dry-run（不寫 DB）
php artisan repair:duplicate-sessions --dry-run --case=189
php artisan repair:duplicate-sessions --dry-run --case=191
php artisan repair:duplicate-sessions --dry-run --case=all

# 輸出 JSON 報告
php artisan repair:duplicate-sessions --dry-run --case=189 --report=/tmp/189-dryrun.json
```

### 7.2 預期 dry-run 輸出格式

```
=== DRY RUN repair:duplicate-sessions case=189 ===
WOULD cancel ClassSession id=18569 (SC2264 2026-06-13 17:00 attended) → cancelled
  reason: duplicate of SC1946 attended slot; keep SC1946 id=15636
WOULD cancel ClassSession id=18602 (SC2264 2026-06-20 17:00 attended) → cancelled
  reason: duplicate of SC1946; keep SC1946 id=15633
WOULD stop StudentClass id=2264 (ghost shell SessionCount=0)
SNAPSHOT: storage/app/repair-snapshots/189-191-20260708.json
ROWS_AFFECTED: 2 sessions, 0 invoices, 1 student_class (stop flag)
```

### 7.3 Production audit 執行（唯讀，2026-07-08）

**§2.1 跨課程同日同時段雙 attended**：至少 **20 組**（全系統）；本案 #189 命中：

| 學生 | 日期 | 時間 | class_ids | session_ids | statuses |
|------|------|------|-----------|-------------|----------|
| 陳品承 | 2026-06-13 | 17:00 | 1946, 2264 | 15636, 18569 | attended, attended |
| 陳品承 | 2026-06-20 | 17:00 | 1946, 2264 | 15633, 18602 | attended, attended |

**§2.2 consumed > SessionCount**：全系統 **15+** 筆；#191 案例 SC395 需個別驗證（見下）。

**§2.3 SessionCount=0 幽靈課程有堂次**：全系統多筆；SC2264 符合幽靈殼特徵。

**§2.4 本案快查 — #189 before（修復前狀態）**

| id | SC | 日期 | 時段 | Status | Note |
|----|-----|------|------|--------|------|
| 15636 | 1946 | 6/13 | 17-19 | attended | 加課 |
| 18569 | 2264 | 6/13 | 17-18 | attended | — |
| 15633 | 1946 | 6/20 | 15-17 | attended | — |
| 18602 | 2264 | 6/20 | 17-18 | attended | 系統加課 |

**§2.4 本案快查 — #191 before**

| id | SC | 日期 | 時段 | Status |
|----|-----|------|------|--------|
| 3215 | 395 | 5/14 | 16-18 | attended（舊約） |
| 13302 | 1655 | 5/14 | 18-20 | completed（新約） |

→ 同日雙記，但時段不同（16-18 vs 18-20）；修復策略須主任確認 5/14 以哪一約為準。

### 7.4 Before / After 對照（核准後預期）

| Case | Before | After（策略 A） |
|------|--------|-----------------|
| #189 | SC2264 兩堂 attended 與 SC1946 重疊；評量「第 4 堂」卡住 | SC2264 的 18569、18602 → `cancelled` + Note；SC1946 保留；SC2264 `Stop=1` |
| #191 | SC395 消耗 10/9；5/14 舊約 attended 與新約 completed 共存 | SC395 的 5/14 attended → `cancelled`；SC395 消耗回到 9；收據重開走人工帳務 |

### 7.5 安全檢查（執行前必過）

| # | 檢查 | 2026-07-08 audit |
|---|------|------------------|
| 1 | Dry-run / audit 已跑 | ✅ 本節 |
| 2 | 備份路徑已記錄 | ⏳ 待執行前 |
| 3 | 主任確認保留哪個 SC | ⏳ #189 保留 1946；#191 保留 1655 |
| 4 | 無進行中 LearningRecord 審核卡在同一 session | 需執行前再查 |
| 5 | `ALLOW_PROD_REPAIR=1` 未設定 | ✅ 未啟用 |

### 7.6 回滾驗證（草稿）

1. 執行前腳本寫入 `repair-snapshots/*.json`（含 ClassSession 列完整欄位）
2. 回滾：`php artisan repair:duplicate-sessions --rollback --snapshot=<file>`
3. 驗證：`SELECT id, Status, Note FROM ClassSession WHERE id IN (...)` 與 snapshot 一致
4. 抽樣：陳品承 SC1946 評量待填數恢復修復前水準

### 7.7 暫時替代：Pi 唯讀 audit 一鍵腳本（直到 artisan 實作）

```bash
ssh admin@pi.lifenet.com.tw 'cd /home/admin/backend && php artisan tinker --execute="
echo \"#189\n\";
foreach (DB::select(\"SELECT id, StudentClassID, SessionDate, StartTime, EndTime, Status, Note FROM ClassSession WHERE StudentClassID IN (1946,2264) AND SessionDate IN (\\\"2026-06-13\\\",\\\"2026-06-20\\\") ORDER BY SessionDate, StartTime, id\") as \$r) echo json_encode(\$r, JSON_UNESCAPED_UNICODE).PHP_EOL;
echo \"#191\n\";
foreach (DB::select(\"SELECT id, StudentClassID, SessionDate, StartTime, Status FROM ClassSession WHERE StudentClassID IN (395,1655) AND SessionDate BETWEEN \\\"2026-05-01\\\" AND \\\"2026-05-31\\\" ORDER BY SessionDate\") as \$r) echo json_encode(\$r, JSON_UNESCAPED_UNICODE).PHP_EOL;
"'
```

---

## 變更紀錄

| 日期 | 說明 |
|------|------|
| 2026-07-08 | 初版草稿（唯讀 production 查證附於 GitHub #1095/#1097） |
| 2026-07-08 | §7 dry-run 規格 + production audit 結果 |
