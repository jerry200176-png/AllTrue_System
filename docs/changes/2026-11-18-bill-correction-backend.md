## 2026-11-18 — feat(billing): 更正帳單後端（預設關閉）
<!-- release-notes: silent_ship=silent-2026-11-18-bill-correction-backend -->
<!-- silent-reason: 後端 API 與撤銷收據跟著錢走的修正，沒有畫面、分校清單為空，教職員看不到任何變化。 -->

- 新增 `BillCorrection` 與 `invoices/{invoice}/correction/preview｜apply`：作廢舊帳單、開新帳單、已收的錢整筆轉過去；堂數制由主任填新總額，月結漏收的課由系統算。分校清單 `billing.bill_correction_campus_ids` 為空，所以全部拒絕。
- `PaymentReportController::void()`：收據的錢被更正轉走後，撤銷會沿著更正鏈把錢從新帳單拿回來；錢已退費或不夠倒轉時拒絕。
- 收款超過上限的拒絕多回 `correctable_invoice_id`（只在分校開啟時）。

Release-Impact: internal
