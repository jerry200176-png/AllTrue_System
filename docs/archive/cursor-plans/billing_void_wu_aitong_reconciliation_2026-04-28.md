# 吳艾潼 COURSE-000382 歷史錯帳作廢核對草案

> 目的：保留 audit trail，不刪除資料。實際 production 作廢前先用 SELECT 核對，確認後才透過受控 PR/CI/deploy 後的資料修復流程處理。

## 需核對的帳單

- `INV-202604-000199COURSE-000382`
- `INV-202605-000357COURSE-000382`

## 唯讀核對 SQL

```sql
SELECT
  i.id,
  i.StudentID,
  s.name AS student_name,
  i.StudentClassID,
  i.billing_period,
  i.IssueDate,
  i.DueDate,
  i.TotalAmount,
  i.PaidAmount,
  i.Status,
  i.Note,
  sc.Stop,
  sc.Paid,
  sc.closed_reason
FROM Invoice i
JOIN Student s ON s.id = i.StudentID
LEFT JOIN StudentClass sc ON sc.ID = i.StudentClassID
WHERE s.name = '吳艾潼'
  AND i.StudentClassID = 382
  AND i.billing_period IN ('2026-04', '2026-05')
ORDER BY i.billing_period, i.id;
```

## 作廢草案（確認後才可執行）

```sql
UPDATE Invoice
SET Status = 'void',
    Note = CONCAT(COALESCE(NULLIF(Note, ''), ''), ' [void: stale receivable COURSE-000382 verified 2026-04-28]')
WHERE StudentClassID = 382
  AND billing_period IN ('2026-04', '2026-05')
  AND Status <> 'void';
```

## 驗收

- 家長入口帳單紀錄不顯示上述 void invoices。
- 課程管理月結帳單列表不顯示上述 void invoices。
- 主任催繳/續課提醒的 `outstanding` 不加總上述 void invoices。
