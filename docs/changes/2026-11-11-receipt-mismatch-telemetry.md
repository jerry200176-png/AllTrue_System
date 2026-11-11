## 2026-11-11 — chore(billing): 撤銷收據被拒時記錄拒絕原因與收據年齡（in-app #522 / #5113 PR 1）
<!-- release-notes: silent_ship=silent-2026-11-11-receipt-mismatch-telemetry -->
<!-- silent-reason: 只在系統記錄檔多寫一行（拒絕原因、收據編號、天數），畫面、規則與金額都沒有改變，教職員看不到差異 -->
- 撤銷收據被「歸屬不符」拒絕時，系統記錄檔多寫一行 `[ReceiptMismatch]`（只含編號、原因、收據天數），用來衡量這類錯誤的基準。撤銷規則本身沒有改變，不寫任何金額。
