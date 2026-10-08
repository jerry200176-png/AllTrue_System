## 2026-10-08 — test(billing): seeded property test for ContractMoneyVerdict invariants (L2)
<!-- release-notes: silent_ship=silent-2026-10-08-billing-verdict-invariants-test -->
<!-- silent-reason: 只新增測試，不改任何畫面或帳務計算。 -->
- 新增 `ContractMoneyVerdictInvariantsTest`：200 組固定種子的隨機合約（堂數制／月結、0..3 張帳單、部分／全額／多收／作廢收款、作廢帳單、$0 試上、Paid 旗標、套裝成員），檢查已收＋未繳＝應繳、未繳不為負、各堂狀態與金額一致、作廢不改金額、月結帳單＝當月堂數×單價、堂數制已上＋剩餘＝購買堂數。
