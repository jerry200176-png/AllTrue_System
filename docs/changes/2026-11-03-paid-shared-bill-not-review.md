## 2026-11-03 — fix(billing): 繳清的合併帳單不再列為待核對（#4803）
<!-- release-notes: staff_update=staff-2026-11-03-paid-shared-bill-not-review -->
- Founder 2026-11-03：一張帳單包含多門課或多個項目（`shared_or_multi_line_bill`）時，只要帳單已全部繳清就視為正常，不再列入待核對；仍有欠款的才列入。其他核對原因（例如金額不符）照舊判斷。
