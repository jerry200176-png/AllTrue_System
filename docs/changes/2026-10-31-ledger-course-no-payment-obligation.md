## 2026-10-31 — fix(billing): 輔導課在學生帳務不再顯示「還沒開帳單」 (#4699)
<!-- release-notes: staff_update=staff-2026-10-31-ledger-tutoring-not-unbilled -->
- 帳務 API 的每一門課多一個 `no_payment_obligation`，用的是原本 `scope.no_payment_obligation` 的同一條規則（輔導課不收費）。
- 學生帳務畫面的課程卡與頂部一行結論都略過這些課，所以沒被點開的輔導課不會再顯示「還沒開帳單」。
