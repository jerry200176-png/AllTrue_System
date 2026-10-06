## 2026-10-06 — feat(accounting): 學生帳務入口收斂到帳務中心（director billing recon M2a）
<!-- release-notes: staff_update=staff-2026-10-06-billing-entry-convergence -->
- 課程查找／學生管理的「帳單（唯讀）」「帳單與對帳」「帳單」改為「學生帳務」，一律跳到帳務中心並直接打開該課程的學生帳務檔（`intent=ledger`，`buildTuitionLedgerNav`）；佇列中有這門課時同時定位該列，沒有時提示「已直接打開學生帳務」。
- 移除課程查找「帳單與對帳紀錄」與學生管理「月結帳單記錄」兩個殘缺帳單視窗（PRD FR-005），資訊已由學生帳務檔完整涵蓋。付款動作（前往繳費／查看待對帳）行為不變。契約：[`director-student-billing-reconciliation-ia`](../plans/2026-10-06-director-student-billing-reconciliation-ia.md) 附錄 B／C（#3641）。
