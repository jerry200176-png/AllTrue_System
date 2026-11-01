## 2026-11-01 — fix(billing): 舊「已收」標記的月結方案成員與只有方案標記的成員也能補登收款／改成未繳（#4597）
<!-- release-notes: staff_update=staff-2026-11-01-legacy-flag-package-items -->
- 多科月結方案建立的成員應收記為 0，補登收款的上限因此一律回「月結已上堂次或應收已變動」；現在 `MonthlyBillingService::summarizePeriod()` 對這類成員改用方案單價乘以該月已上堂次算出應收，再套用上限。已有固定應收的成員不變。
- 方案已標記已收、但成員本身未標記（沒有繳費日、帳單或收款）時，學生帳務卡片原本不顯示任何動作；`AccountingController::ledger()` 現在送出含方案標記的「已收」狀態，卡片會出現「需核對」與補登收款／改成未繳。
