## 2026-10-12 — fix(billing): 帳單狀態寫「已繳」但收款紀錄不足時，催繳名單、應收帳齡、銀行對帳建議照樣列出
<!-- release-notes: staff_update=staff-2026-10-12-stale-paid-bill-still-owes -->
- 帳單以收款紀錄為準（R28）：狀態欄過期寫成「已繳」、但實際收款不足的帳單，帳務中心待收清單、應收帳齡、銀行對帳建議不再漏掉。Refs #3781
