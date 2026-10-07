## 2026-10-07 — refactor(billing): BillingCorrection owns the count-mode correction write
<!-- release-notes: silent_ship=silent-2026-10-07-billing-correction-1 -->
<!-- silent-reason: 純搬移重構（未收款堂數更正的確認 token 與鎖定寫入移到 Services/Billing/BillingCorrection），回應內容與狀態碼不變。 -->
- ARCH2-C3 第 8 刀（帳務更正 1/n）：`billingCorrectionConfirmationToken` 與未收款堂數更正的鎖定寫入交易搬到 `BillingCorrection`；控制器保留同名一行轉接與請求驗證／預覽。
