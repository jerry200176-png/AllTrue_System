## 2026-10-07 — refactor(billing): BillingCorrection owns the count-mode correction flow
<!-- release-notes: silent_ship=silent-2026-10-07-billing-correction-2 -->
<!-- silent-reason: 純搬移重構（未收款堂數更正的守門、預覽與確認流程移到 BillingCorrection::correctCountMode），回應內容與狀態碼不變。 -->
- ARCH2-C3 第 9 刀（帳務更正 2/n）：堂數制帳務更正的各項守門、預覽（含確認 token）與確認寫入搬到 `BillingCorrection::correctCountMode`；控制器只剩授權、驗證與日期制分流。
