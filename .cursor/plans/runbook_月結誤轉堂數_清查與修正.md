# Runbook：月結誤轉堂數（8堂顯示 24,000）清查與單筆修正

## 1) 使用情境

- 使用者反映：原本月結課程，通知/畫面卻出現「堂數制 8 堂 NT$24,000」。
- 已知根因：月結課曾誤走 `purchase-batch`，被建立成 `ScheduleMode='count'` 新課程。
- 目標：先找出所有異常，再安全修正單一案例，最後走標準交付流水線。

---

## 2) 執行前規範（必做）

- 先在非尖峰時段執行。
- 先備份資料庫（至少 `StudentClass`）。
- SQL 一律先跑「查詢版（只讀）」；確認 ID 後才跑「交易更新版」。
- 單筆修正必須有工單編號（`@ticket_no`）留痕。

---

## 3) 清查 SQL（可直接執行）

### 3.1 全校異常清單（同學生同科目同時有 active 月結 + active 堂數）

```sql
SELECT
  sc.StudentID,
  s.name AS student_name,
  sc.SubjectID,
  COALESCE(sub.Subject_Name, CONCAT('Subject#', sc.SubjectID)) AS subject_name,
  SUM(CASE WHEN sc.ScheduleMode = 'date'  AND IFNULL(sc.Stop, 0) = 0 THEN 1 ELSE 0 END) AS active_monthly_count,
  SUM(CASE WHEN sc.ScheduleMode = 'count' AND IFNULL(sc.Stop, 0) = 0 THEN 1 ELSE 0 END) AS active_session_count,
  GROUP_CONCAT(
    CASE
      WHEN IFNULL(sc.Stop, 0) = 0 THEN CONCAT(
        'ID=', sc.ID,
        ',mode=', sc.ScheduleMode,
        ',Charge=', IFNULL(sc.Charge, 0),
        ',SessionCount=', IFNULL(sc.SessionCount, 0),
        ',Start=', DATE_FORMAT(sc.StartDate, '%Y-%m-%d'),
        ',End=', IFNULL(DATE_FORMAT(sc.EndDate, '%Y-%m-%d'), 'NULL')
      )
      ELSE NULL
    END
    ORDER BY sc.ID DESC SEPARATOR ' | '
  ) AS active_courses_snapshot
FROM StudentClass sc
JOIN Student s ON s.id = sc.StudentID
LEFT JOIN Subject sub ON sub.id = sc.SubjectID
GROUP BY sc.StudentID, s.name, sc.SubjectID, sub.Subject_Name
HAVING active_monthly_count > 0
   AND active_session_count > 0
ORDER BY sc.StudentID, sc.SubjectID;
```

### 3.2 疑似「8 堂 24,000」高風險單（優先客服處理）

```sql
SELECT
  sc.ID,
  sc.StudentID,
  s.name AS student_name,
  sc.SubjectID,
  COALESCE(sub.Subject_Name, CONCAT('Subject#', sc.SubjectID)) AS subject_name,
  sc.ScheduleMode,
  sc.SessionCount,
  sc.Charge,
  sc.Pay,
  sc.Paid,
  sc.Stop,
  sc.StartDate,
  sc.EndDate
FROM StudentClass sc
JOIN Student s ON s.id = sc.StudentID
LEFT JOIN Subject sub ON sub.id = sc.SubjectID
WHERE sc.ScheduleMode = 'count'
  AND IFNULL(sc.Stop, 0) = 0
  AND IFNULL(sc.SessionCount, 0) = 8
  AND IFNULL(sc.Charge, 0) = 24000
ORDER BY sc.ID DESC;
```

### 3.3 單一學生明細（給客服/櫃台核對）

```sql
-- 替換成實際學生 ID
SET @student_id := 1234;

SELECT
  sc.ID,
  sc.StudentID,
  s.name AS student_name,
  sc.SubjectID,
  COALESCE(sub.Subject_Name, CONCAT('Subject#', sc.SubjectID)) AS subject_name,
  sc.ScheduleMode,
  sc.Stop,
  sc.SessionCount,
  sc.Charge,
  sc.Pay,
  sc.Paid,
  DATE_FORMAT(sc.StartDate, '%Y-%m-%d') AS StartDate,
  DATE_FORMAT(sc.EndDate, '%Y-%m-%d')   AS EndDate,
  sc.settlement_day,
  sc.monthly_sessions
FROM StudentClass sc
JOIN Student s ON s.id = sc.StudentID
LEFT JOIN Subject sub ON sub.id = sc.SubjectID
WHERE sc.StudentID = @student_id
ORDER BY sc.SubjectID, sc.ID DESC;
```

---

## 4) 單筆修正 SOP（交易版 SQL）

> 目標：關閉誤開的堂數制課程，延長正確的月結課程。  
> 規則：只改「已人工確認」的兩筆課程 ID，不做模糊批次 UPDATE。

### 4.1 變數設定（先填值）

```sql
SET @ticket_no := 'INC-2026-04-21-001';
SET @operator  := 'ops_jerry';

-- 正確月結課程 ID（ScheduleMode='date'）
SET @monthly_course_id := 10001;

-- 誤開堂數課程 ID（ScheduleMode='count'，例如 8 堂 24,000）
SET @wrong_count_course_id := 10099;

-- 修正後月結到期日（與櫃台確認）
SET @new_end_date := '2026-07-21';
```

### 4.2 預覽與鎖定（不可跳過）

```sql
START TRANSACTION;

SELECT ID, StudentID, SubjectID, ScheduleMode, Stop, EndDate, SessionCount, Charge, Pay, Paid
FROM StudentClass
WHERE ID IN (@monthly_course_id, @wrong_count_course_id)
FOR UPDATE;
```

人工確認重點：
- `@monthly_course_id` 必須是 `ScheduleMode='date'` 且 `Stop=0`。
- `@wrong_count_course_id` 必須是 `ScheduleMode='count'` 且 `Stop=0`。
- `StudentID`、`SubjectID` 必須一致。

### 4.3 執行修正（同一交易內）

```sql
-- 關閉誤開堂數課（保留稽核軌跡）
UPDATE StudentClass
SET
  Stop = 1,
  closed_reason = 'monthly_misbatch',
  MDate = NOW(),
  Memo = CONCAT_WS('\n',
    NULLIF(Memo, ''),
    CONCAT(
      '[FIX ', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'), '] ',
      'ticket=', @ticket_no, ', operator=', @operator,
      ', action=close_wrong_count_course, wrong_course_id=', @wrong_count_course_id
    )
  )
WHERE ID = @wrong_count_course_id
  AND ScheduleMode = 'count'
  AND IFNULL(Stop, 0) = 0;

-- 延長正確月結課到期日
UPDATE StudentClass
SET
  EndDate = @new_end_date,
  MDate = NOW(),
  Memo = CONCAT_WS('\n',
    NULLIF(Memo, ''),
    CONCAT(
      '[FIX ', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'), '] ',
      'ticket=', @ticket_no, ', operator=', @operator,
      ', action=extend_monthly_enddate, monthly_course_id=', @monthly_course_id,
      ', new_end_date=', @new_end_date
    )
  )
WHERE ID = @monthly_course_id
  AND ScheduleMode = 'date'
  AND IFNULL(Stop, 0) = 0;
```

### 4.4 交易內驗證

```sql
SELECT
  SUM(CASE WHEN ID = @monthly_course_id AND ScheduleMode = 'date'  AND IFNULL(Stop,0)=0 THEN 1 ELSE 0 END) AS monthly_active_ok,
  SUM(CASE WHEN ID = @wrong_count_course_id AND ScheduleMode = 'count' AND IFNULL(Stop,0)=1 THEN 1 ELSE 0 END) AS wrong_count_closed_ok
FROM StudentClass
WHERE ID IN (@monthly_course_id, @wrong_count_course_id);
```

判斷：
- 兩欄都為 `1` -> `COMMIT;`
- 任一不是 `1` -> `ROLLBACK;` 並回報工程師

```sql
COMMIT;
-- 失敗時改用：ROLLBACK;
```

### 4.5 交易後驗證（客服可看）

```sql
SELECT
  sc.ID, sc.StudentID, s.name AS student_name, sc.SubjectID,
  COALESCE(sub.Subject_Name, CONCAT('Subject#', sc.SubjectID)) AS subject_name,
  sc.ScheduleMode, sc.Stop, sc.SessionCount, sc.Charge, sc.EndDate, sc.closed_reason
FROM StudentClass sc
JOIN Student s ON s.id = sc.StudentID
LEFT JOIN Subject sub ON sub.id = sc.SubjectID
WHERE sc.ID IN (@monthly_course_id, @wrong_count_course_id);
```

---

## 5) 軟體公司流水線（你現在「下一步要幹嘛」）

### Step A：Incident 建單（今天立刻）

- 建一張 incident（標題：月結誤轉堂數造成 8 堂 24,000 顯示）。
- 附證據：家長截圖、學生 ID、課程 ID、發生時間、操作人。
- 指派：Support（R）+ Backend（A）+ Ops（C）+ 店主任（I）。

### Step B：L1 清查（30 分鐘內）

- 先跑「3.2 + 3.3 SQL」確認是否命中本事故型態。
- 產出「待修名單」：`monthly_course_id` / `wrong_count_course_id` / 建議 `new_end_date`。

### Step C：L2 單筆修正（同日）

- 依「4) 單筆修正 SOP」逐案執行（每案一個 transaction）。
- 每案要留下：ticket、operator、SQL 執行時間、結果截圖。

### Step D：產品驗收（同日）

- UI 驗收：該學生不再顯示 8 堂 24,000 異常項目。
- 業務驗收：月結課到期日/收費邏輯符合主任確認值。
- 客服回覆家長完成關單。

### Step E：Regression Gate（隔日）

- 工程跑回歸：月結續約流程、`purchase-batch` 對月結 422 保護、堂數制加購不受影響。
- Ops 跑一次「3.1 全校異常清查 SQL」，確認異常數下降到 0（或有明確待辦）。

### Step F：收尾與防再犯（本週內）

- 更新 `docs/CHANGELOG.md`：記錄本次資料修正與影響範圍。
- 更新 `docs/AI_REGRESSION_LESSONS.md`：加入「月結課不可走 purchase-batch」檢核。
- 若仍有歷史殘留，排一個批次修復窗口，不要在上課尖峰時段執行。

---

## 6) 風險提醒

- 不要直接批次 `UPDATE ... WHERE ScheduleMode='count' AND SessionCount=8 AND Charge=24000`，風險過高。
- 若同一學生同科目本來就同時有合法堂數課 + 月結課，必須由主任人工判定後再修。
- 修正只改 `Stop/closed_reason/Memo/EndDate`，避免動到歷史繳費欄位造成對帳偏差。
