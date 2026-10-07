## 2026-10-07 — perf(db): index InvoiceItem.InvoiceID (#3741)
<!-- release-notes: silent_ship=silent-2026-10-07-invoice-item-index -->
<!-- silent-reason: 只新增資料庫索引，資料與畫面完全不變 -->
- 新增 `InvoiceItem(InvoiceID)` 索引 `idx_invitem_invoice_id`，帳單明細查詢不再整表掃描（#3726/#3454 後續）。回滾：`ALTER TABLE InvoiceItem DROP INDEX idx_invitem_invoice_id`。
