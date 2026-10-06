## 2026-10-06 — feat(accounting): 學生帳務對帳展開帳單／已確認收據可見完整涵蓋上課日（M1）
<!-- release-notes: silent_ship=silent-2026-10-06-director-billing-recon-m1 -->
<!-- silent-reason: 既有帳務對帳視窗的展開區多列出上課日，操作流程與入口不變；入口收斂與教職員卡片併 M2 一起發 -->
- `AccountingLedgerModal`：帳單列一律可展開（不再限有收款），展開先列「涵蓋上課日」再列收款時間線；已確認收據可「展開上課日」。日期直接讀繳費單 `invoices/{id}/slip-data` 與收據 `payment-reports/{id}/receipt`（同 `billingDocumentView` 正規化），不新增日期算法；超過 12 堂顯示「尚有 N 堂」；載入失敗只標「上課日暫時無法載入」＋重試，金額照常顯示。契約：[`IMPL_HANDOFF_M1`](../plans/2026-10-06-director-billing-recon-IMPL_HANDOFF_M1.md)（#3641）。
