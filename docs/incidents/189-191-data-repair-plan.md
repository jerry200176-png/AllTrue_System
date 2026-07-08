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

## 變更紀錄

| 日期 | 說明 |
|------|------|
| 2026-07-08 | 初版草稿（唯讀 production 查證附於 GitHub #1095/#1097） |
