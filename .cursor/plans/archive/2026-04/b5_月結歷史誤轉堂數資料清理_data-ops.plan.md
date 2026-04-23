# Bug Fix Plan（輕量 Data-Ops）：月結歷史誤轉堂數資料清理

---

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P2 |
| 根因類型 | 歷史資料錯誤（b4 code fix 前遺留） |
| 根因摘要 | b4 修復前，月結課（`ScheduleMode='date'`）誤走 `purchase-batch`，新建了一筆 `ScheduleMode='count'` 課程；且 `Charge` 被 `Rate × SessionDuration × SessionCount` 多乘了時長，算出 24,000（正確月費應為 12,000） |
| 錯誤行為 | 家長收到繳費通知顯示「數學（堂數制）剩餘 8 堂 NT$24,000」，金額為正確月費的兩倍 |
| 預期行為 | 通知單應顯示月結課 12,000/月；錯誤堂數課應被關閉（Stop=1） |
| 影響範圍 | b4 部署前曾對月結課按「加購」的所有案例；當前確認至少 1 筆（陳昱恆 · 數學） |
| B1 偵查來源 | 本計畫 B1 偵查（2026-04-21），根因候選 1 確認 |

---

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 月結歷史誤轉堂數資料清理 |
| 版本 | v1.0 |
| 狀態 | 已完成 |
| 嚴重度 | P2（資料錯誤，系統仍可用；有 workaround：主任可手動停用錯誤課程） |
| 目標角色 | Ops（執行 SQL）、director（驗收） |
| 關聯計畫 | b4_月結加購續報變堂數制（§13 已預告此歷史清理需求） |

---

## 2. 業務背景與影響

b4 修復了程式碼，但 **b4 部署前已產生的錯誤課程列不會自動消失**。  
受影響學生會持續收到金額錯誤的繳費通知（24,000 而非 12,000），造成：

1. 家長誤以為費用調漲，產生客訴
2. 主任若未手動處理，系統會對同一科目出現兩筆 active 課程（月結 + 堂數）
3. 帳單/繳費提醒可能基於錯誤 Charge 值發出

**修復後預期行為**：
- 錯誤堂數課 Stop=1、closed_reason='monthly_misbatch'
- 原月結課維持 active，EndDate 正確，Charge=12,000
- 通知單不再顯示 24,000 的堂數課

---

## 3. 範圍

### In Scope
- 關閉誤建的 `ScheduleMode='count'` 課程（Stop=1）
- 確認原月結課 active、EndDate 正確、Charge=12,000
- 全校批次清查（找出其他同型態錯誤案例）

### Out of Scope
- 程式碼異動：不改（b4 已完成）
- 已繳款的錯誤課程退款流程：由主任人工判斷，不在本計畫自動處理
- `ClassSession` 堂次紀錄：誤建課程若已產生堂次，本計畫只關閉課程，堂次清理另排工單

---

## 4. RACI

| 任務 | R | A | C | I |
|---|---|---|---|---|
| B1 偵查確認根因 | AI Agent | AI Agent | — | 使用者 |
| 清查 SQL 執行 | Ops | Ops | AI Agent | 主任 |
| 單筆修正 SQL 執行 | Ops | Ops | AI Agent | 主任 |
| 業務驗收（通知單正確） | 主任 | 主任 | — | — |
| CHANGELOG 更新 | AI Agent | AI Agent | — | 使用者 |

### 4b. Dependencies
- b4 code fix 已部署（`purchase-batch` 對月結課已回傳 422），確保修正後不再產生新錯誤
- 無 DB migration 需求

---

## 5. Acceptance Criteria

### AC-001：錯誤堂數課被關閉
- AC-001-a：`StudentClass` 中 `ScheduleMode='count'` 且 `SessionCount=8, Charge=24000` 的錯誤課程，`Stop=1`、`closed_reason='monthly_misbatch'`
- AC-001-b：關閉後，前端課程列表不再顯示該筆課程（Stop=1 被過濾）

### AC-002：原月結課正確
- AC-002-a：同學生同科目的原月結課（`ScheduleMode='date'`）`Stop=0`，`Charge=12000`，`EndDate` 為主任確認值
- AC-002-b：同學生同科目只剩一筆 `Stop=0` 的月結課

### AC-003：通知單金額正確
- AC-003-a：重新產生/查看通知單，顯示 12,000 而非 24,000

### AC-004：全校清查無殘留
- AC-004-a：清查 SQL（§8）執行後，`active_monthly_count > 0 AND active_session_count > 0` 的案例數 = 0（或所有殘留案例有明確待辦工單）

---

## 6. 功能需求 FR

| 編號 | 描述 |
|---|---|
| FR-001 | 對確認的錯誤堂數課，執行 `UPDATE StudentClass SET Stop=1, closed_reason='monthly_misbatch'`，不刪除資料（保留稽核軌跡） |
| FR-002 | 確認原月結課 `Charge=12000`，若不符則更新（與主任確認後執行） |
| FR-003 | 全校清查：找出所有「同學生同科目同時有 active 月結 + active 堂數」的案例，逐一處理 |
| FR-004 | 每筆修正須在 `Memo` 欄留下 ticket 號、操作人、時間戳（稽核軌跡） |

---

## 7. 非功能需求 NFR

不適用。本修復為純資料操作（單筆 UPDATE），無效能疑慮。執行應在非尖峰時段（非上課時間）進行。

---

## 8. 技術方向（Runbook SQL）

> 所有 SQL 必須在 `START TRANSACTION` 內執行，確認後才 `COMMIT`。

### 8.1 清查 SQL（先執行，只讀）

**找全校異常案例**：
```sql
SELECT
  sc.StudentID,
  s.name AS student_name,
  sc.SubjectID,
  SUM(CASE WHEN sc.ScheduleMode='date'  AND IFNULL(sc.Stop,0)=0 THEN 1 ELSE 0 END) AS active_monthly,
  SUM(CASE WHEN sc.ScheduleMode='count' AND IFNULL(sc.Stop,0)=0 THEN 1 ELSE 0 END) AS active_count,
  GROUP_CONCAT(
    CONCAT('ID=',sc.ID,',mode=',sc.ScheduleMode,',Charge=',IFNULL(sc.Charge,0),',Stop=',IFNULL(sc.Stop,0))
    ORDER BY sc.ID DESC SEPARATOR ' | '
  ) AS snapshot
FROM StudentClass sc
JOIN Student s ON s.id = sc.StudentID
GROUP BY sc.StudentID, sc.SubjectID
HAVING active_monthly > 0 AND active_count > 0;
```

### 8.2 單筆修正 SQL（交易版）

```sql
-- 先填變數
SET @ticket_no           := 'INC-2026-04-21-001';
SET @operator            := '';               -- 填操作人
SET @monthly_course_id   := 0;                -- 填原月結課 ID
SET @wrong_course_id     := 0;                -- 填錯誤堂數課 ID
SET @correct_charge      := 12000;            -- 確認月費
SET @new_end_date        := '';               -- 填主任確認的到期日 e.g. '2026-07-21'

START TRANSACTION;

-- Step 1：預覽（確認欄位正確）
SELECT ID, ScheduleMode, Stop, Charge, SessionCount, EndDate
FROM StudentClass
WHERE ID IN (@monthly_course_id, @wrong_course_id)
FOR UPDATE;

-- Step 2：關閉錯誤堂數課
UPDATE StudentClass
SET Stop=1, closed_reason='monthly_misbatch', MDate=NOW(),
    Memo=CONCAT_WS('\n', NULLIF(Memo,''),
      CONCAT('[FIX ',DATE_FORMAT(NOW(),'%Y-%m-%d %H:%i'),']: ticket=',@ticket_no,
             ', op=',@operator,', close wrong count course'))
WHERE ID=@wrong_course_id AND ScheduleMode='count' AND IFNULL(Stop,0)=0;

-- Step 3：修正月結課 Charge 與 EndDate
UPDATE StudentClass
SET Charge=@correct_charge, EndDate=@new_end_date, MDate=NOW(),
    Memo=CONCAT_WS('\n', NULLIF(Memo,''),
      CONCAT('[FIX ',DATE_FORMAT(NOW(),'%Y-%m-%d %H:%i'),']: ticket=',@ticket_no,
             ', op=',@operator,', correct charge+enddate'))
WHERE ID=@monthly_course_id AND ScheduleMode='date' AND IFNULL(Stop,0)=0;

-- Step 4：驗證
SELECT
  SUM(CASE WHEN ID=@monthly_course_id AND ScheduleMode='date'  AND IFNULL(Stop,0)=0 AND Charge=@correct_charge THEN 1 ELSE 0 END) AS monthly_ok,
  SUM(CASE WHEN ID=@wrong_course_id   AND ScheduleMode='count' AND IFNULL(Stop,0)=1 THEN 1 ELSE 0 END) AS wrong_closed
FROM StudentClass WHERE ID IN (@monthly_course_id, @wrong_course_id);
-- 兩欄都是 1 → COMMIT；否則 → ROLLBACK
```

### 8b. Decision Log

| 日期 | 選項 | 選擇 | 理由 |
|---|---|---|---|
| 2026-04-21 | A. 刪除錯誤課程 | 否 | 保留稽核軌跡，`closed_reason` 標記即可 |
| 2026-04-21 | B. Stop=1 + closed_reason | ✅ | 與 b4 及現有暫停流程一致 |
| 2026-04-21 | C. 批次 UPDATE 不加 transaction | 否 | 風險過高，必須交易保護 |

---

## 9. 資安與存取控制

不適用（核心層面）。操作為 Ops 內部 SQL，須由已授權人員在資料庫終端執行，不透過 API。執行前需確認操作人帳號具備 MySQL 寫入權限。

---

## 10. QA 驗收

### Happy Path
- [ ] 清查 SQL 回傳 0 筆（或所有案例已處理）
- [ ] 修正後 `SELECT * FROM StudentClass WHERE ID=@wrong_course_id` → `Stop=1, closed_reason='monthly_misbatch'`
- [ ] 修正後 `SELECT * FROM StudentClass WHERE ID=@monthly_course_id` → `Stop=0, Charge=12000, ScheduleMode='date'`
- [ ] 前端對該學生課程頁，不再顯示 24,000 的堂數課

### Edge Cases
- [ ] 若錯誤課程已有關聯 `ClassSession`，確認堂次不影響月結課的點名流程
- [ ] 若錯誤課程 `Paid=1`（已收款），停用前主任需確認退款/沖帳方式

### Revert-proof 驗證
- [ ] 備份可還原：`mysqldump alltrue StudentClass` 備份存在，可用 `@monthly_course_id/@wrong_course_id` 查詢原始快照

---

## 11. 上線與維運

### 執行步驟
1. 執行 §8.1 清查 SQL，取得全部案例清單
2. 每筆填入 §8.2 變數（ticket、monthly_course_id、wrong_course_id、correct_charge、new_end_date）
3. START TRANSACTION → 預覽 → 修正 → 驗證 → COMMIT
4. 前端/通知單 smoke test

### Migration
無。純 UPDATE 操作，無新欄位。

### 回滾方案
SQL 交易內失敗 → `ROLLBACK`，資料不變。  
若已 COMMIT 需還原 → 手動 `UPDATE` 反向操作（Memo 有時間戳可追溯）。

---

## 12. 優先級

| 欄位 | 內容 |
|---|---|
| 優先級 | P2，本週內完成 |
| 執行 Agent | `[OPS]` 清查 + 單筆修正 → `[DOCS]` CHANGELOG 更新 |

---

## 13. 風險 / 假設 / 開放問題

| 類型 | 內容 |
|---|---|
| 風險 | 若錯誤課程已有繳費紀錄（Invoice/Payment），關閉課程前需主任確認是否退款；本計畫不處理退款邏輯 |
| 風險 | 清查 SQL 可能漏掉「月結課已 Stop=1、但堂數課仍 active」的案例（舊月結被手動停用但新堂數課未清）；建議另跑 `ScheduleMode='count' AND IFNULL(Stop,0)=0` 全掃 |
| 假設 | 陳昱恆案例正確月費為 12,000，由主任口頭確認 |
| 假設 | 清查範圍以 `active_monthly > 0 AND active_count > 0` 為主；b4 部署後不再產生新案例 |
| 開放問題 | `new_end_date` 應設為多少？需主任確認（建議與原月結課 EndDate 或加 1 個月） |

---

## 14. Definition of Done

- [ ] FR-001（關閉錯誤課程）：`SELECT Stop, closed_reason FROM StudentClass WHERE ID=@wrong_course_id` 回傳 `Stop=1, closed_reason='monthly_misbatch'`
- [ ] FR-002（月結課 Charge 正確）：`SELECT Charge FROM StudentClass WHERE ID=@monthly_course_id` 回傳 `12000`
- [ ] FR-003（全校清查）：清查 SQL 回傳 0 筆（或每筆都有對應工單）
- [ ] FR-004（Memo 稽核軌跡）：`SELECT Memo FROM StudentClass WHERE ID IN (@monthly_course_id, @wrong_course_id)` 含 `[FIX` 開頭的 ticket 記錄
- [ ] Revert-proof：執行前 `mysqldump` 備份已確認存在
- [ ] CHANGELOG：`git diff docs/CHANGELOG.md` 含 `2026-04-21` 月結歷史清理條目

---

## Todos

- [x] `[OPS]` 執行 §8.1 清查 SQL，產出全校待修名單（確認僅 1 筆）
- [x] `[OPS]` 依 §8.2 逐筆執行交易修正（StudentClass ID=171，Charge 24000→12000）
- [x] `[OPS]` 前端 smoke test：UI 顯示 12,000，DB Charge=12,000，一致
- [x] `[DOCS]` `docs/CHANGELOG.md` 新增 2026-04-21 資料清理條目
