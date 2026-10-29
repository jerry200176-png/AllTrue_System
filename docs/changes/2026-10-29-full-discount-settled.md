## 2026-10-29 — fix(billing): 全額折扣的續約算已繳清；免費／贈送記錄核准人
<!-- release-notes: staff_update=staff-2026-10-29-full-discount-settled -->
- 月結續約折扣到 NT$0（100% 或固定折扣等於學費）時，這期帳單直接標成已繳清（原因 `full_discount`），帳務中心、名單與催繳都不再顯示未繳或待登記。折扣的核准人記在帳單上（`reconciled_by`），折扣明細存在帳單的 `billing_snapshot`。
- 「免費／贈送」NT$0 帳單（新開或改標）現在也記下核准人與時間（`reconciled_by`／`reconciled_at`）。
- 之後這張帳單被改成有金額，就不再算已繳清。目前折扣功能仍是關閉的（#4020），所以這條只對重新開啟折扣後的續約生效。
