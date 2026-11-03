## 2026-11-03 — feat(billing): 待核對的課可以標「已核對」（#4798）
<!-- release-notes: staff_update=staff-2026-11-03-review-checked -->
- Founder 2026-11-02 決定 A、2026-11-03 migration GO：主任在待核對（`review_required`）的課按「已核對」（`POST /student-classes/{id}/payment-review/check`），條件是沒有欠款，而且每個上過課的月份都有帳單；否則拒絕（`payment_review_check_owed`、`payment_review_check_unbilled`）。
- 新增欄位 `StudentClass.review_checked_signature`（只新增、可為空）。它記住核對當下的帳務狀態（核對原因、各期帳單、金額、已付、上課數）。帳單、收款或上課只要有變動，簽章就不同，課會自己回到待核對。
- 標記時寫一筆 `payment_review_checked` 稽核事件。`closed_reason` 是 `settled_pending` 的課會改成 `settled`，跟收款確認的做法一樣。
